<?php
// includes/security.php - CSRF, auth, RBAC, lockout, audit, session timeout,
// security headers (CSP), HTTPS enforcement, rate limiting, app logging.
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

const SESSION_TIMEOUT = 900;      // 15 min inactivity
const SESSION_WARN_AT = 780;      // warn at 13 min (2 min before expiry)
const MAX_LOGIN_ATTEMPTS = 10;    // hard lock threshold (per account)
const LOCKOUT_MINUTES = 15;
const IP_FAIL_WINDOW = 300;       // 5 min
const IP_FAIL_MAX = 20;           // failed logins per IP (any user) per window - anti credential-stuffing
const USER_IP_FAIL_MAX = 5;       // failed logins per username+IP pair per window

// Chapter 3 Table 2 permission matrix
const PERMISSIONS = [
  'manage_users'       => ['Administrator'],
  'view_users'         => ['Administrator', 'Supervisor'], // Supervisor = read-only (Limited)
  'assign_roles'       => ['Administrator'],
  'register_crime'     => ['Administrator', 'Records Officer', 'Investigating Officer', 'Supervisor'],
  'view_crime'         => ['Administrator', 'Records Officer', 'Investigating Officer', 'Supervisor'],
  'update_crime'       => ['Administrator', 'Records Officer', 'Investigating Officer', 'Supervisor'],
  'update_investigation' => ['Administrator', 'Investigating Officer', 'Supervisor'],
  'add_evidence'       => ['Investigating Officer'],
  'view_audit'         => ['Administrator', 'Supervisor'],
  'generate_reports'   => ['Administrator', 'Records Officer', 'Investigating Officer', 'Supervisor'],
];

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function app_env(): string {
  return strtolower((string)env('APP_ENV', 'production'));
}

function app_is_production(): bool {
  return app_env() === 'production';
}

function app_force_https(): bool {
  return strtolower((string)env('FORCE_HTTPS', '1')) === '1';
}

function app_debug(): bool {
  return strtolower((string)env('APP_DEBUG', '0')) === '1' && !app_is_production();
}

function app_log_dir(): string {
  return dirname(__DIR__) . '/logs';
}

function app_log(string $level, string $message): void {
  try {
    $dir = app_log_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $line = sprintf("[%s] %s %s %s\n", date('c'), strtoupper($level), $message, PHP_EOL);
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
  } catch (Throwable $t) { /* logging must never break the request */ }
}

function is_https(): bool {
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function force_https_redirect(): void {
  if (!app_force_https() || app_env() === 'cli' || PHP_SAPI === 'cli') return;
  if (!is_https()) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip port for redirect host safety (allow only host[:port] chars)
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) $host = 'localhost';
    header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
  }
}

function csp_nonce(): string {
  static $nonce = null;
  if ($nonce === null) {
    $nonce = base64_encode(random_bytes(16));
  }
  return $nonce;
}

function send_security_headers(): void {
  if (headers_sent()) return;
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: SAMEORIGIN');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
  header('X-Permitted-Cross-Domain-Policies: none');
  if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  }
  $nonce = csp_nonce();
  $csp = implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
    "img-src 'self' data:",
    "font-src 'self' https://cdn.jsdelivr.net",
    "connect-src 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'self'",
  ]);
  header("Content-Security-Policy: {$csp}");
}

function ensure_session(): void {
  if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
      session_name('PDBSESS');
      session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
  }
}

function e(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
  ensure_session();
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool {
  ensure_session();
  return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function client_ip(): string {
  // Do not trust X-Forwarded-For for security decisions unless you control the proxy.
  return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Per-user-IP throttle: too many failed logins for this username from this IP.
 * Complements account lockout and the global per-IP cap.
 */
function user_ip_throttled(string $username): bool {
  if ($username === '') return false;
  try {
    $pdo = db();
    $stmt = $pdo->prepare(
      "SELECT COUNT(*) FROM login_history
       WHERE status IN ('failed','locked')
         AND login_time > (NOW() - INTERVAL :w SECOND)
         AND ip_address = :ip
         AND user_id = (
           SELECT user_id FROM users WHERE username = :u LIMIT 1
         )"
    );
    $stmt->bindValue(':w', IP_FAIL_WINDOW, PDO::PARAM_INT);
    $stmt->bindValue(':ip', substr(client_ip(), 0, 45));
    $stmt->bindValue(':u', $username);
    $stmt->execute();
    return (int)$stmt->fetchColumn() >= USER_IP_FAIL_MAX;
  } catch (Throwable $t) {
    return false;
  }
}

/** Global per-IP throttle (credential stuffing across many usernames). */
function ip_login_throttled(): bool {
  try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_history WHERE ip_address = :ip AND status IN ('failed','locked') AND login_time > (NOW() - INTERVAL :w SECOND)");
    $stmt->bindValue(':ip', substr(client_ip(), 0, 45));
    $stmt->bindValue(':w', IP_FAIL_WINDOW, PDO::PARAM_INT);
    $stmt->execute();
    return (int)$stmt->fetchColumn() >= IP_FAIL_MAX;
  } catch (Throwable $t) {
    return false; // DB down: account lockout still applies
  }
}

function password_hash_secure(string $pw): string {
  if (defined('PASSWORD_ARGON2ID')) {
    return password_hash($pw, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
  }
  return password_hash($pw, PASSWORD_BCRYPT);
}

function audit(string $action, ?string $record, string $status, ?string $desc = null): void {
  try {
    $pdo = db();
    $u = $_SESSION['user_id'] ?? null;
    $a = substr($action, 0, 80);
    $r = $record ? substr($record, 0, 100) : null;
    $ip = substr(client_ip(), 0, 45);
    $s = substr($status, 0, 20);
    $d = $desc ? substr($desc, 0, 255) : null;
    $prev = null;
    try { $prev = $pdo->query('SELECT row_hash FROM audit_log ORDER BY log_id DESC LIMIT 1')->fetchColumn() ?: null; } catch (Throwable $t2) { $prev = null; }
    $rowHash = hash('sha256', ($prev ?? 'GENESIS') . '|' . ($u ?? '-') . '|' . $a . '|' . ($r ?? '-') . '|' . $ip . '|' . $s . '|' . ($d ?? '-') . '|' . time());
    $stmt = $pdo->prepare('INSERT INTO audit_log (user_id, action, record_affected, ip_address, status, description, prev_hash, row_hash) VALUES (:u, :a, :r, :ip, :s, :d, :p, :h)');
    $stmt->execute([':u' => $u, ':a' => $a, ':r' => $r, ':ip' => $ip, ':s' => $s, ':d' => $d, ':p' => $prev ? substr((string)$prev, 0, 64) : null, ':h' => $rowHash]);
  } catch (Throwable $t) {
    app_log('error', 'audit failed: ' . $t->getMessage());
  }
}

function audit_verify_chain(int $limit = 500): array {
  try {
    $pdo = db();
    $rows = $pdo->query('SELECT log_id, user_id, action, record_affected, ip_address, status, description, prev_hash, row_hash FROM audit_log ORDER BY log_id ASC LIMIT ' . $limit)->fetchAll();
    $prev = null; $bad = [];
    foreach ($rows as $r) {
      $expectedPrev = $prev;
      $storedPrev = $r['prev_hash'] ?? null;
      if ($expectedPrev === null) { if ($storedPrev !== null && $storedPrev !== '') { /* first row may have null, allow */ } }
      elseif ($storedPrev !== $expectedPrev) { $bad[] = (int)$r['log_id']; }
      $prev = $r['row_hash'];
    }
    return ['checked' => count($rows), 'broken' => $bad, 'ok' => empty($bad)];
  } catch (Throwable $t) { return ['checked' => 0, 'broken' => [], 'ok' => false, 'error' => $t->getMessage()]; }
}

function evidence_hash(?string $evidenceId, ?string $type, ?string $desc, ?string $storage): ?string {
  $raw = ($evidenceId ?? '') . '|' . ($type ?? '') . '|' . ($desc ?? '') . '|' . ($storage ?? '');
  return hash('sha256', $raw);
}

function log_history(?string $userId, string $status): void {
  try {
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO login_history (user_id, ip_address, status, user_agent) VALUES (:u, :ip, :s, :ua)');
    $stmt->execute([
      ':u' => $userId,
      ':ip' => substr(client_ip(), 0, 45),
      ':s' => $status,
      ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
    ]);
  } catch (Throwable $t) { /* ignore */ }
}

function is_logged_in(): bool {
  ensure_session();
  return isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role_name']);
}

function require_login(): void {
  force_https_redirect();
  ensure_session();
  if (!is_logged_in()) {
    header('Location: login.php');
    exit;
  }
  // Inactivity timeout
  $last = (int)($_SESSION['last_activity'] ?? time());
  if (time() - $last > SESSION_TIMEOUT) {
    audit('SESSION_TIMEOUT', $_SESSION['username'] ?? null, 'expired', 'Inactivity timeout');
    session_unset();
    session_destroy();
    header('Location: login.php?reason=timeout');
    exit;
  }
  $_SESSION['last_activity'] = time();
  // Force password reset
  $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
  if (!empty($_SESSION['force_reset']) && !in_array($script, ['change_password.php', 'logout.php'], true)) {
    header('Location: change_password.php?forced=1');
    exit;
  }
}

function require_role(array $roles): void {
  require_login();
  if (!in_array($_SESSION['role_name'] ?? '', $roles, true)) {
    http_response_code(403);
    $pageTitle = 'Forbidden';
    $activeNav = '';
    require __DIR__ . '/header.php';
    echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Your role is not permitted to access this page.</div><p><a href="dashboard.php">Back to dashboard</a></p>';
    require __DIR__ . '/footer.php';
    exit;
  }
}

function can(string $permission): bool {
  $role = $_SESSION['role_name'] ?? '';
  return in_array($role, PERMISSIONS[$permission] ?? [], true);
}

function require_perm(string $permission): void {
  require_login();
  if (!can($permission)) {
    http_response_code(403);
    $pageTitle = 'Forbidden';
    $activeNav = '';
    require __DIR__ . '/header.php';
    echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Action not permitted for your role.</div><p><a href="dashboard.php">Back to dashboard</a></p>';
    require __DIR__ . '/footer.php';
    exit;
  }
}

// Password policy: min 10, upper + lower + digit, reject common list
function password_policy_errors(string $pw): array {
  $errors = [];
  if (strlen($pw) < 10) $errors[] = 'Password must be at least 10 characters.';
  if (!preg_match('/[A-Z]/', $pw)) $errors[] = 'Password must include an uppercase letter.';
  if (!preg_match('/[a-z]/', $pw)) $errors[] = 'Password must include a lowercase letter.';
  if (!preg_match('/[0-9]/', $pw)) $errors[] = 'Password must include a number.';
  $common = ['password','password123','1234567890','qwerty12345','admin12345','letmein123','welcome123','police1234','nigeria123','password1!'];
  if (in_array(strtolower($pw), $common, true)) $errors[] = 'Password is too common. Choose a less predictable one.';
  return $errors;
}

// Progressive delay: 0,1,2,4,8... capped at 5s (avoids DoS, slows brute force)
function login_delay(int $failedAttempts): void {
  if ($failedAttempts <= 1) return;
  $delay = min(1 << ($failedAttempts - 1), 5);
  sleep($delay);
}

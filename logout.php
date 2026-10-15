<?php
// logout.php
declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
ensure_session();
if (is_logged_in()) {
  audit('LOGOUT', $_SESSION['username'] ?? null, 'success', null);
  try {
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE login_history SET logout_time = NOW() WHERE user_id = :u AND logout_time IS NULL ORDER BY login_time DESC LIMIT 1');
    $stmt->execute([':u' => $_SESSION['user_id']]);
  } catch (Throwable $t) { /* ignore */ }
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $p['path'],
    'domain' => $p['domain'],
    'secure' => $p['secure'],
    'httponly' => $p['httponly'],
    'samesite' => $p['samesite'] ?? 'Lax',
  ]);
}
session_destroy();
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Location: login.php');
exit;

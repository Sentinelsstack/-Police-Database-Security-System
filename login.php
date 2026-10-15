<?php
// login.php - production login: progressive delay + account lockout + per-IP throttle + audit
declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
force_https_redirect();
ensure_session();

if (is_logged_in()) {
  header('Location: dashboard.php');
  exit;
}

$errors = [];
$username = '';
$reason = $_GET['reason'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $errors[] = 'Invalid session. Please reload and try again.';
  } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (ip_login_throttled()) {
      audit('LOGIN_IP_THROTTLED', null, 'failed', 'Too many failed logins from this address');
      app_log('warn', 'IP throttle hit: ' . client_ip());
      $errors[] = 'Too many failed attempts from this network. Try again later.';
    } elseif ($username !== '' && user_ip_throttled($username)) {
      audit('LOGIN_USER_IP_THROTTLED', $username, 'failed', 'Too many failed logins for this user from this IP');
      app_log('warn', 'User+IP throttle hit: ' . $username . ' @ ' . client_ip());
      $errors[] = 'Too many failed attempts for this account from this network. Try again later.';
    } elseif ($username === '' || $password === '') {
      $errors[] = 'Enter both username and password.';
    } else {
      try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        // Generic response to avoid username enumeration; still enforce delay/lockout when user exists
        if (!$user) {
          sleep(1);
          log_history(null, 'failed');
          $errors[] = 'Invalid username or password.';
        } else {
          // Locked check
          if ($user['account_status'] === 'locked' || ($user['locked_until'] && strtotime($user['locked_until']) > time())) {
            log_history($user['user_id'], 'locked');
            audit('LOGIN_BLOCKED', $username, 'locked', 'Attempt while locked');
            $errors[] = 'Account is temporarily locked. Try again later.';
          } elseif ($user['account_status'] === 'disabled') {
            log_history($user['user_id'], 'failed');
            $errors[] = 'Invalid username or password.';
          } else {
            login_delay((int)$user['failed_attempts']);
            if (password_verify($password, $user['password_hash'])) {
              // Success: reset counters
              $upd = $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE user_id = :id');
              $upd->execute([':id' => $user['user_id']]);
              session_regenerate_id(true);
              if (!empty($user['totp_enabled'])) {
                $_SESSION['pending_2fa'] = $user['user_id'];
                $_SESSION['pending_2fa_time'] = time();
                log_history($user['user_id'], 'success');
                audit('LOGIN_2FA_REQUIRED', $user['username'], 'success', null);
                header('Location: verify_2fa.php');
                exit;
              }
              $_SESSION['user_id'] = $user['user_id'];
              $_SESSION['username'] = $user['username'];
              $_SESSION['role_name'] = $user['role_name'];
              $_SESSION['role_id'] = (int)$user['role_id'];
              $_SESSION['officer_id'] = $user['officer_id'] ?? null;
              $_SESSION['last_activity'] = time();
              $_SESSION['force_reset'] = ((int)$user['force_password_reset'] === 1);
              log_history($user['user_id'], 'success');
              audit('LOGIN', $user['username'], 'success', 'Role: ' . $user['role_name']);
              if ($_SESSION['force_reset']) {
                header('Location: change_password.php?forced=1');
              } else {
                header('Location: dashboard.php');
              }
              exit;
            } else {
              $attempts = (int)$user['failed_attempts'] + 1;
              if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                $lock = $pdo->prepare('UPDATE users SET failed_attempts = :a, locked_until = DATE_ADD(NOW(), INTERVAL ' . LOCKOUT_MINUTES . ' MINUTE) WHERE user_id = :id');
                $lock->execute([':a' => $attempts, ':id' => $user['user_id']]);
                log_history($user['user_id'], 'locked');
                audit('LOGIN_LOCKED', $username, 'locked', $attempts . ' failed attempts');
                $errors[] = 'Too many failed attempts. Account locked for ' . LOCKOUT_MINUTES . ' minutes.';
              } else {
                $upd = $pdo->prepare('UPDATE users SET failed_attempts = :a WHERE user_id = :id');
                $upd->execute([':a' => $attempts, ':id' => $user['user_id']]);
                log_history($user['user_id'], 'failed');
                audit('LOGIN_FAILED', $username, 'failed', 'Attempt ' . $attempts);
                $errors[] = 'Invalid username or password.';
              }
            }
          }
        }
      } catch (Throwable $t) {
        app_log('error', 'login error: ' . $t->getMessage());
        $errors[] = 'System error. Please try again later.';
      }
    }
  }
}

$pageTitle = 'Login';
$activeNav = '';
force_https_redirect();
send_security_headers();
$cspNonce = csp_nonce();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - Police DB</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link href="assets/css/custom.css?v=3" rel="stylesheet">
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>
<main id="main" class="container" tabindex="-1">
<div class="login-wrap">
  <div class="text-center mb-3"><span class="badge-shield" aria-hidden="true">NPF</span>
    <h1 class="h4 mt-2">Police Database Security System</h1>
    <p class="text-muted">Authorised users only. All access is logged.</p>
  </div>
  <?php if ($reason === 'timeout'): ?><div class="alert alert-warning" role="alert">Session expired due to inactivity. Please log in again.</div><?php endif; ?>
  <?php if ($errors): ?>
  <div class="alert alert-danger" role="alert" tabindex="-1" autofocus>
    <h2 class="h6">There <?= count($errors) === 1 ? 'is 1 error' : 'are ' . count($errors) . ' errors' ?></h2>
    <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <div class="card"><div class="card-body">
  <form method="post" action="login.php" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="mb-3">
      <label class="form-label" for="username">Username</label>
      <input class="form-control" id="username" name="username" autocomplete="username" required value="<?= e($username) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label" for="password">Password</label>
      <div class="input-group">
        <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
        <button class="btn btn-outline-secondary" type="button" id="togglePw" aria-pressed="false" aria-controls="password">Show</button>
      </div>
    </div>
    <button class="btn btn-primary w-100" type="submit">Log in</button>
  </form>
  </div></div>
</div>
</main>
<script nonce="<?= e($cspNonce) ?>">
document.getElementById('togglePw').addEventListener('click', function(){
  const pw = document.getElementById('password');
  const show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  this.textContent = show ? 'Hide' : 'Show';
  this.setAttribute('aria-pressed', show ? 'true' : 'false');
});
</script>
</body>
</html>

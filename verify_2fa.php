<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/totp.php';
force_https_redirect();
ensure_session();
if (empty($_SESSION['pending_2fa'])) { header('Location: login.php'); exit; }
// Pending 2FA ticket expires in 5 minutes
if (empty($_SESSION['pending_2fa_time']) || time() - (int)$_SESSION['pending_2fa_time'] > 300) {
  unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_time']);
  audit('LOGIN_2FA_EXPIRED', null, 'failed', 'Pending 2FA timed out');
  header('Location: login.php?reason=timeout');
  exit;
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? null)) $errors[] = 'Invalid session.';
  else {
    $code = trim($_POST['code'] ?? '');
    try {
      $pdo = db();
      $s = $pdo->prepare('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :id LIMIT 1');
      $s->execute([':id' => $_SESSION['pending_2fa']]);
      $user = $s->fetch();
      if ($user && !empty($user['totp_secret']) && totp_verify((string)$user['totp_secret'], $code)) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['role_id'] = (int)$user['role_id'];
        $_SESSION['officer_id'] = $user['officer_id'] ?? null;
        $_SESSION['last_activity'] = time();
        $_SESSION['force_reset'] = ((int)$user['force_password_reset'] === 1);
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_time']);
        audit('LOGIN_2FA_OK', $user['username'], 'success', null);
        header('Location: ' . ($_SESSION['force_reset'] ? 'change_password.php?forced=1' : 'dashboard.php'));
        exit;
      } else { $errors[] = 'Invalid authenticator code.'; audit('LOGIN_2FA_FAIL', $user['username'] ?? '?', 'failed', null); }
    } catch (Throwable $t) { app_log('error', '2fa error: ' . $t->getMessage()); $errors[] = 'System error.'; }
  }
}
send_security_headers();
$cspNonce = csp_nonce();
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Two-factor check - Police DB</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"><link href="assets/css/custom.css?v=3" rel="stylesheet"></head><body><main class="container"><div class="login-wrap"><h1 class="h4">Two-factor verification</h1><p class="text-muted">Enter the 6-digit code from your authenticator app.</p><?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" autofocus><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?><div class="card"><div class="card-body"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div class="mb-3"><label class="form-label" for="code">Authenticator code</label><input class="form-control" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" required maxlength="6" autocomplete="one-time-code"></div><button class="btn btn-primary w-100" type="submit">Verify</button></form></div></div></div></main></body></html>

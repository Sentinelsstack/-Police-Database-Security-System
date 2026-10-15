<?php
// change_password.php - first-login force reset + voluntary change
declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
require_login();

$errors = [];
$success = '';
$forced = isset($_GET['forced']) || !empty($_SESSION['force_reset']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $errors[] = 'Invalid session. Please reload and try again.';
  } else {
    $current = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    try {
      $pdo = db();
      $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id LIMIT 1');
      $stmt->execute([':id' => $_SESSION['user_id']]);
      $row = $stmt->fetch();
      if (!$row || !password_verify($current, $row['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
      } elseif ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
      } elseif (password_verify($new, $row['password_hash'])) {
        $errors[] = 'New password must differ from the current one.';
      } else {
        $policy = password_policy_errors($new);
        if ($policy) {
          $errors = array_merge($errors, $policy);
        } else {
          $hash = password_hash_secure($new);
          $upd = $pdo->prepare('UPDATE users SET password_hash = :h, force_password_reset = 0, failed_attempts = 0, locked_until = NULL WHERE user_id = :id');
          $upd->execute([':h' => $hash, ':id' => $_SESSION['user_id']]);
          $_SESSION['force_reset'] = false;
          audit('PASSWORD_CHANGE', $_SESSION['username'], 'success', $forced ? 'forced reset' : 'voluntary');
          $success = 'Password changed successfully.';
        }
      }
    } catch (Throwable $t) {
      $errors[] = 'System error. Please try again later.';
    }
  }
}

$pageTitle = 'Change Password';
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>
<h1>Change Password</h1>
<?php if ($forced): ?><div class="alert alert-warning" role="alert">You must change your password before continuing.</div><?php endif; ?>
<?php if ($errors): ?>
<div class="alert alert-danger" role="alert" tabindex="-1" autofocus><h2 class="h6">There <?= count($errors) === 1 ? 'is 1 error' : 'are ' . count($errors) . ' errors' ?></h2><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?> <a href="dashboard.php">Go to dashboard</a></div><?php endif; ?>
<div class="card" style="max-width:32rem"><div class="card-body">
<form method="post" action="change_password.php<?= $forced ? '?forced=1' : '' ?>">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="mb-3"><label class="form-label" for="current">Current password</label><input class="form-control" type="password" id="current" name="current" autocomplete="current-password" required></div>
  <div class="mb-3"><label class="form-label" for="new">New password (min 10 chars, upper + lower + number)</label><input class="form-control" type="password" id="new" name="new" autocomplete="new-password" required aria-describedby="pwhelp"><div id="pwhelp" class="form-text">Never reuse your seed password.</div></div>
  <div class="mb-3"><label class="form-label" for="confirm">Confirm new password</label><input class="form-control" type="password" id="confirm" name="confirm" autocomplete="new-password" required></div>
  <button class="btn btn-primary" type="submit">Change password</button>
  <?php if (!$forced): ?><a class="btn btn-secondary" href="dashboard.php">Cancel</a><?php endif; ?>
</form>
</div></div>
<?php
require_once __DIR__ . '/includes/totp.php';
$two = db()->prepare('SELECT totp_enabled FROM users WHERE user_id = :id LIMIT 1');
$two->execute([':id' => $_SESSION['user_id']]);
$totpOn = (int)($two->fetchColumn() ?? 0) === 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'totp' && csrf_check($_POST['csrf'] ?? null) && !$forced) {
  if (($_POST['totp_action'] ?? '') === 'enable') {
    $sec = totp_generate_secret();
    db()->prepare('UPDATE users SET totp_secret = :s, totp_enabled = 1 WHERE user_id = :id')->execute([':s' => $sec, ':id' => $_SESSION['user_id']]);
    audit('2FA_ENABLE', $_SESSION['username'], 'success', null);
    $totpOn = true;
    echo '<div class="alert alert-success" role="status">Two-factor enabled. Secret: <code>' . e($sec) . '</code><br>Add to Google Authenticator with: <code>' . e(totp_provision_uri($sec, (string)$_SESSION['username'])) . '</code></div>';
  } elseif (($_POST['totp_action'] ?? '') === 'disable') {
    db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE user_id = :id')->execute([':id' => $_SESSION['user_id']]);
    audit('2FA_DISABLE', $_SESSION['username'], 'success', null);
    $totpOn = false;
    echo '<div class="alert alert-info" role="status">Two-factor disabled.</div>';
  }
}
?>
<?php if (!$forced): ?><div class="card mt-3" style="max-width:32rem"><div class="card-body"><h2 class="h5">Two-factor authentication (TOTP)</h2><p class="text-muted small">Status: <?= $totpOn ? '<span class="badge bg-success">Enabled</span>' : '<span class="badge bg-secondary">Disabled</span>' ?></p><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="totp"><input type="hidden" name="totp_action" value="<?= $totpOn ? 'disable' : 'enable' ?>"><button class="btn btn-sm btn-outline-primary" type="submit"><?= $totpOn ? 'Disable 2FA' : 'Enable 2FA' ?></button></form></div></div><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
// users.php - Admin full manage, Supervisor read-only (Limited per Ch3 Table 2)
declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
require_login();
if (!can('view_users')) {
  http_response_code(403);
  $pageTitle = 'Forbidden'; $activeNav = 'users';
  require __DIR__ . '/includes/header.php';
  echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Your role cannot view users.</div>';
  require __DIR__ . '/includes/footer.php';
  exit;
}
$readOnly = !can('manage_users'); // Supervisor
$errors = [];
$success = '';
$pdo = db();

// Actions (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $errors[] = 'Invalid session. Please reload.';
  } else {
    $action = $_POST['do'] ?? '';
    try {
      if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $full = trim($_POST['full_name'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $temp = $_POST['temp_pw'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) $errors[] = 'Username: 3-50 chars, letters/numbers/underscore.';
        elseif ($full === '') $errors[] = 'Full name is required.';
        elseif ($roleId < 1) $errors[] = 'Select a role.';
        else {
          $pp = password_policy_errors($temp);
          if ($pp) $errors = array_merge($errors, $pp);
          else {
            $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
            $exists->execute([':u' => $username]);
            if ($exists->fetch()) $errors[] = 'Username already exists.';
            else {
              $uid = 'USR' . str_pad((string)(random_int(100, 9999)), 4, '0', STR_PAD_LEFT);
              $ins = $pdo->prepare('INSERT INTO users (user_id, username, password_hash, full_name, role_id, account_status, force_password_reset) VALUES (:id,:u,:h,:f,:r,\'active\',1)');
              $ins->execute([':id' => $uid, ':u' => $username, ':h' => password_hash_secure($temp), ':f' => substr($full, 0, 100), ':r' => $roleId]);
              audit('USER_CREATE', $username, 'success', 'ID ' . $uid);
              $success = 'User ' . $username . ' created. They must change password on first login.';
            }
          }
        }
      } elseif ($action === 'status') {
        $uid = $_POST['user_id'] ?? '';
        $st = $_POST['status'] ?? '';
        if (!in_array($st, ['active', 'locked', 'disabled'], true)) $errors[] = 'Invalid status.';
        elseif ($uid === ($_SESSION['user_id'] ?? '')) $errors[] = 'You cannot change your own status.';
        else {
          $upd = $pdo->prepare('UPDATE users SET account_status = :s, failed_attempts = 0, locked_until = CASE WHEN :s = \'active\' THEN NULL ELSE locked_until END WHERE user_id = :id');
          $upd->execute([':s' => $st, ':id' => $uid]);
          audit('USER_STATUS', $uid, 'success', 'Set ' . $st);
          $success = 'Status updated.';
        }
      } elseif ($action === 'reset') {
        $uid = $_POST['user_id'] ?? '';
        $temp = $_POST['temp_pw'] ?? '';
        $pp = password_policy_errors($temp);
        if ($pp) $errors = array_merge($errors, $pp);
        elseif ($uid === ($_SESSION['user_id'] ?? '')) $errors[] = 'Use Change Password for your own account.';
        else {
          $upd = $pdo->prepare('UPDATE users SET password_hash = :h, force_password_reset = 1, failed_attempts = 0, locked_until = NULL WHERE user_id = :id');
          $upd->execute([':h' => password_hash_secure($temp), ':id' => $uid]);
          audit('USER_RESET', $uid, 'success', 'Temp password issued');
          $success = 'Temporary password set. User must change on next login.';
        }
      }
    } catch (Throwable $t) {
      $errors[] = 'System error. Please try again.';
    }
  }
}

$users = $pdo->query('SELECT u.user_id, u.username, u.full_name, r.role_name, u.account_status, u.last_login FROM users u JOIN roles r ON r.role_id = u.role_id ORDER BY u.date_created')->fetchAll();
$roles = $pdo->query('SELECT role_id, role_name FROM roles ORDER BY role_id')->fetchAll();

$pageTitle = 'Users';
$activeNav = 'users';
require __DIR__ . '/includes/header.php';
?>
<h1>Users</h1>
<?php if ($readOnly): ?><div class="alert alert-info" role="status">Supervisor view: read-only.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" autofocus><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>

<div class="table-responsive"><table class="table table-striped">
<caption>System users and roles</caption>
<thead><tr><th scope="col">ID</th><th scope="col">Username</th><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last login</th><?php if (!$readOnly): ?><th scope="col">Actions</th><?php endif; ?></tr></thead>
<tbody>
<?php foreach ($users as $u): ?><tr>
<td><?= e($u['user_id']) ?></td><td><?= e($u['username']) ?></td><td><?= e($u['full_name']) ?></td>
<td><span class="badge bg-secondary"><?= e($u['role_name']) ?></span></td><td><?= e($u['account_status']) ?></td><td><?= e((string)($u['last_login'] ?? '-')) ?></td>
<?php if (!$readOnly): ?><td>
  <form method="post" class="d-inline" action="users.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="status">
    <input type="hidden" name="user_id" value="<?= e($u['user_id']) ?>">
    <label class="visually-hidden" for="st-<?= e($u['user_id']) ?>">Status for <?= e($u['username']) ?></label>
    <select class="form-select form-select-sm d-inline w-auto" id="st-<?= e($u['user_id']) ?>" name="status">
      <?php foreach (['active','locked','disabled'] as $s): ?><option value="<?= $s ?>" <?= $u['account_status']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-primary" type="submit">Set</button>
  </form>
  <form method="post" class="d-inline" action="users.php" onsubmit="return confirm('Issue temporary password?')">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="reset">
    <input type="hidden" name="user_id" value="<?= e($u['user_id']) ?>">
    <label class="visually-hidden" for="tp-<?= e($u['user_id']) ?>">Temporary password</label>
    <input class="form-control form-control-sm d-inline w-auto" id="tp-<?= e($u['user_id']) ?>" name="temp_pw" required minlength="10" placeholder="TempP@ss123">
    <button class="btn btn-sm btn-outline-warning" type="submit">Reset</button>
  </form>
</td><?php endif; ?>
</tr><?php endforeach; ?>
</tbody>
</table></div>

<?php if (!$readOnly): ?>
<h2 class="h5 mt-4">Create user</h2>
<div class="card" style="max-width:36rem"><div class="card-body">
<form method="post" action="users.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="do" value="create">
  <div class="mb-3"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" required pattern="[a-zA-Z0-9_]{3,50}"></div>
  <div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" required maxlength="100"></div>
  <div class="mb-3"><label class="form-label" for="role_id">Role</label><select class="form-select" id="role_id" name="role_id" required><?php foreach ($roles as $r): ?><option value="<?= (int)$r['role_id'] ?>"><?= e($r['role_name']) ?></option><?php endforeach; ?></select></div>
  <div class="mb-3"><label class="form-label" for="temp_pw">Temporary password (min 10, upper+lower+number)</label><input class="form-control" type="password" id="temp_pw" name="temp_pw" required minlength="10" autocomplete="new-password"></div>
  <button class="btn btn-primary" type="submit">Create user</button>
</form>
</div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

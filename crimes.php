<?php
// crimes.php - view all; create register_crime; edit update_crime (+IO assigned-only)
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();

$action = $_GET['action'] ?? 'list';
$errors = [];
$success = '';
$pdo = db();
$role = $_SESSION['role_name'] ?? '';

if ($action === 'add' && !can('register_crime')) { http_response_code(403); $pageTitle='Forbidden'; $activeNav='crimes'; require __DIR__.'/includes/header.php'; echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Cannot register crimes.</div>'; require __DIR__.'/includes/footer.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $errors[] = 'Invalid session. Please reload.';
  } elseif ($action === 'edit' && !can('update_crime')) {
    $errors[] = 'Your role cannot update crimes.';
  } else {
    $type = $_POST['crime_type'] ?? '';
    $desc = trim($_POST['description'] ?? '');
    $loc = trim($_POST['location'] ?? '');
    $date = $_POST['date_reported'] ?? '';
    $status = $_POST['status'] ?? 'Reported';
    $officer = $_POST['reporting_officer_id'] ?? '';
    if (!in_array($type, crime_types(), true)) $errors[] = 'Select a valid crime type.';
    elseif ($desc === '' || strlen($desc) > 2000) $errors[] = 'Description is required (max 2000 chars).';
    elseif ($loc === '' || strlen($loc) > 150) $errors[] = 'Location is required (max 150 chars).';
    elseif (!valid_date($date)) $errors[] = 'Enter a valid report date (not in the future).';
    elseif (!in_array($status, crime_statuses(), true)) $errors[] = 'Invalid status.';
    if (!$errors && $officer !== '') {
      $s = $pdo->prepare('SELECT 1 FROM officers WHERE officer_id = :o LIMIT 1');
      $s->execute([':o' => $officer]);
      if (!$s->fetchColumn()) $errors[] = 'Selected reporting officer does not exist.';
    }
    if (!$errors && $action === 'edit') {
      $cid = $_POST['crime_id'] ?? '';
      if ($role === 'Investigating Officer' && !crime_assigned_to_me($cid)) {
        $errors[] = 'Investigating Officers may edit only assigned crimes (created by you or linked to your cases).';
      }
    }
    if (!$errors) {
      try {
        if ($action === 'add') {
          $id = gen_id('CR');
          $stmt = $pdo->prepare('INSERT INTO crimes (crime_id, crime_type, description, location, date_reported, reporting_officer_id, status, created_by) VALUES (:id,:t,:d,:l,:dt,:o,:s,:u)');
          $stmt->execute([':id' => $id, ':t' => $type, ':d' => $desc, ':l' => $loc, ':dt' => $date, ':o' => $officer ?: null, ':s' => $status, ':u' => $_SESSION['user_id']]);
          audit('CRIME_CREATE', $id, 'success', $type);
          $success = 'Crime registered (' . $id . ').';
        } else {
          $cid = $_POST['crime_id'] ?? '';
          $stmt = $pdo->prepare('UPDATE crimes SET crime_type=:t, description=:d, location=:l, date_reported=:dt, reporting_officer_id=:o, status=:s WHERE crime_id=:id');
          $stmt->execute([':t' => $type, ':d' => $desc, ':l' => $loc, ':dt' => $date, ':o' => $officer ?: null, ':s' => $status, ':id' => $cid]);
          audit('CRIME_UPDATE', $cid, 'success', $type);
          $success = 'Crime updated.';
        }
      } catch (Throwable $t) { $errors[] = 'System error. Please try again.'; }
    }
  }
}

$pageTitle = 'Crimes';
$activeNav = 'crimes';
require __DIR__ . '/includes/header.php';
?>
<h1>Crimes</h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" autofocus><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>
  <?php if (can('register_crime')): ?><p><a class="btn btn-primary" href="crimes.php?action=add">Register crime</a></p><?php endif; ?>
  <?php
  $rows = $pdo->query('SELECT c.*, o.name AS officer_name FROM crimes c LEFT JOIN officers o ON o.officer_id = c.reporting_officer_id ORDER BY c.date_created DESC LIMIT 200')->fetchAll();
  $officers = $pdo->query('SELECT officer_id, name FROM officers ORDER BY name')->fetchAll();
  ?>
  <div class="table-responsive"><table class="table table-striped">
  <caption>Reported crimes (<?= count($rows) ?>)</caption>
  <thead><tr><th scope="col">ID</th><th scope="col">Type</th><th scope="col">Location</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col">Officer</th><th scope="col">Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7">No crimes yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr>
    <td><?= e($r['crime_id']) ?></td><td><?= e($r['crime_type']) ?></td><td><?= e($r['location']) ?></td>
    <td><?= e((string)$r['date_reported']) ?></td><td><?= e($r['status']) ?></td><td><?= e((string)($r['officer_name'] ?? '-')) ?></td>
    <td><a href="crimes.php?action=view&amp;id=<?= e($r['crime_id']) ?>">View</a><?php if (can('update_crime')): ?> | <a href="crimes.php?action=edit&amp;id=<?= e($r['crime_id']) ?>">Edit</a><?php endif; ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
<?php elseif ($action === 'view'): ?>
  <?php
  $s = $pdo->prepare('SELECT c.*, o.name AS officer_name FROM crimes c LEFT JOIN officers o ON o.officer_id = c.reporting_officer_id WHERE c.crime_id = :id LIMIT 1');
  $s->execute([':id' => $_GET['id'] ?? '']);
  $c = $s->fetch();
  if (!$c) { echo '<div class="alert alert-warning" role="alert">Crime not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
  $cases = $pdo->prepare('SELECT case_id, case_number, investigation_status FROM cases WHERE crime_id = :id ORDER BY date_opened DESC');
  $cases->execute([':id' => $c['crime_id']]);
  $linked = $cases->fetchAll();
  ?>
  <div class="card mb-3"><div class="card-body">
    <h2 class="h5"><?= e($c['crime_id']) ?> - <?= e($c['crime_type']) ?></h2>
    <dl class="row"><dt class="col-sm-3">Location</dt><dd class="col-sm-9"><?= e($c['location']) ?></dd>
    <dt class="col-sm-3">Date reported</dt><dd class="col-sm-9"><?= e((string)$c['date_reported']) ?></dd>
    <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= e($c['status']) ?></dd>
    <dt class="col-sm-3">Officer</dt><dd class="col-sm-9"><?= e((string)($c['officer_name'] ?? '-')) ?></dd>
    <dt class="col-sm-3">Description</dt><dd class="col-sm-9"><?= e($c['description']) ?></dd></dl>
    <?php if (can('update_crime')): ?><a class="btn btn-sm btn-outline-primary" href="crimes.php?action=edit&amp;id=<?= e($c['crime_id']) ?>">Edit</a><?php endif; ?>
    <a class="btn btn-sm btn-secondary" href="crimes.php">Back</a>
  </div></div>
  <h2 class="h5">Linked cases (<?= count($linked) ?>)</h2>
  <ul><?php foreach ($linked as $lc): ?><li><a href="cases.php?action=view&amp;id=<?= e($lc['case_id']) ?>"><?= e($lc['case_number']) ?></a> (<?= e($lc['investigation_status']) ?>)</li><?php endforeach; ?></ul>
<?php else: ?>
  <?php
  $edit = null;
  if ($action === 'edit') {
    $s = $pdo->prepare('SELECT * FROM crimes WHERE crime_id = :id LIMIT 1');
    $s->execute([':id' => $_GET['id'] ?? '']);
    $edit = $s->fetch();
    if (!$edit) { echo '<div class="alert alert-warning" role="alert">Crime not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
    if ($role === 'Investigating Officer' && !crime_assigned_to_me($edit['crime_id'])) {
      echo '<div class="alert alert-danger" role="alert">Investigating Officers may edit only assigned crimes.</div>';
      require __DIR__ . '/includes/footer.php'; exit;
    }
  }
  $officers = $pdo->query('SELECT officer_id, name FROM officers ORDER BY name')->fetchAll();
  ?>
  <div class="card" style="max-width:40rem"><div class="card-body">
  <h2 class="h5"><?= $action === 'add' ? 'Register crime' : 'Edit crime ' . e($edit['crime_id']) ?></h2>
  <form method="post" action="crimes.php?action=<?= $action ?><?= $edit ? '&amp;id=' . e($edit['crime_id']) : '' ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($edit): ?><input type="hidden" name="crime_id" value="<?= e($edit['crime_id']) ?>"><?php endif; ?>
    <div class="mb-3"><label class="form-label" for="crime_type">Crime type</label><select class="form-select" id="crime_type" name="crime_type" required><?php foreach (crime_types() as $t): ?><option <?= ($edit['crime_type'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" required maxlength="2000" rows="4"><?= e($edit['description'] ?? '') ?></textarea></div>
    <div class="mb-3"><label class="form-label" for="location">Location</label><input class="form-control" id="location" name="location" required maxlength="150" value="<?= e($edit['location'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="date_reported">Date reported</label><input class="form-control" type="date" id="date_reported" name="date_reported" required value="<?= e((string)($edit['date_reported'] ?? date('Y-m-d'))) ?>"></div>
    <div class="mb-3"><label class="form-label" for="reporting_officer_id">Reporting officer</label><select class="form-select" id="reporting_officer_id" name="reporting_officer_id"><option value="">-- none --</option><?php foreach ($officers as $o): ?><option value="<?= e($o['officer_id']) ?>" <?= ($edit['reporting_officer_id'] ?? '') === $o['officer_id'] ? 'selected' : '' ?>><?= e($o['name']) ?> (<?= e($o['officer_id']) ?>)</option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><?php foreach (crime_statuses() as $st): ?><option <?= ($edit['status'] ?? 'Reported') === $st ? 'selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary" type="submit"><?= $action === 'add' ? 'Register' : 'Save' ?></button>
    <a class="btn btn-secondary" href="crimes.php">Cancel</a>
  </form>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

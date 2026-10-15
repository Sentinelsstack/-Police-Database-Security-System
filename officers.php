<?php
// officers.php - view all roles; create/edit Admin only
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();

$action = $_GET['action'] ?? 'list';
$errors = [];
$success = '';
$pdo = db();
$isAdmin = can('manage_users');

if (in_array($action, ['add', 'edit'], true) && !$isAdmin) {
  http_response_code(403);
  $pageTitle = 'Forbidden'; $activeNav = 'officers';
  require __DIR__ . '/includes/header.php';
  echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Only Administrators can add or edit officers.</div>';
  require __DIR__ . '/includes/footer.php';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $errors[] = 'Invalid session. Please reload.';
  } else {
    $service = trim($_POST['service_number'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $rank = trim($_POST['rank'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $station = trim($_POST['station'] ?? '');
    $contact = trim($_POST['contact_info'] ?? '');
    if ($service === '' || $name === '' || $rank === '') $errors[] = 'Service number, name and rank are required.';
    elseif (strlen($service) > 30 || strlen($name) > 100) $errors[] = 'Service number or name too long.';
    if (!$errors) {
      try {
        $contactEnc = $contact !== '' ? enc_encrypt($contact) : null;
        if ($action === 'add') {
          $id = gen_id('OFF');
          $stmt = $pdo->prepare('INSERT INTO officers (officer_id, service_number, name, rank, department, station, contact_info) VALUES (:id,:s,:n,:r,:d,:st,:c)');
          $stmt->execute([':id' => $id, ':s' => $service, ':n' => $name, ':r' => $rank, ':d' => $dept ?: null, ':st' => $station ?: null, ':c' => $contactEnc]);
          audit('OFFICER_CREATE', $id, 'success', $service);
          $success = 'Officer registered (' . $id . ').';
        } else {
          $id = $_POST['officer_id'] ?? '';
          $stmt = $pdo->prepare('UPDATE officers SET service_number=:s, name=:n, rank=:r, department=:d, station=:st, contact_info=:c WHERE officer_id=:id');
          $stmt->execute([':s' => $service, ':n' => $name, ':r' => $rank, ':d' => $dept ?: null, ':st' => $station ?: null, ':c' => $contactEnc, ':id' => $id]);
          audit('OFFICER_UPDATE', $id, 'success', $service);
          $success = 'Officer updated.';
        }
      } catch (PDOException $ex) {
        $errors[] = ($ex->getCode() === '23000') ? 'Service number already exists.' : 'System error. Please try again.';
      }
    }
  }
}

$pageTitle = 'Officers';
$activeNav = 'officers';
require __DIR__ . '/includes/header.php';
?>
<h1>Officers</h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" autofocus><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>
  <?php if ($isAdmin): ?><p><a class="btn btn-primary" href="officers.php?action=add">Register officer</a></p><?php endif; ?>
  <?php $rows = $pdo->query('SELECT * FROM officers ORDER BY date_created DESC LIMIT 200')->fetchAll(); ?>
  <div class="table-responsive"><table class="table table-striped">
  <caption>Registered officers (<?= count($rows) ?>)</caption>
  <thead><tr><th scope="col">ID</th><th scope="col">Service No</th><th scope="col">Name</th><th scope="col">Rank</th><th scope="col">Station</th><?php if ($isAdmin): ?><th scope="col">Actions</th><?php endif; ?></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6">No officers yet. <?= $isAdmin ? 'Seeded OFF001 (Adaeze Obi) should appear here.' : '' ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr>
    <td><?= e($r['officer_id']) ?></td><td><?= e($r['service_number']) ?></td><td><?= e($r['name']) ?></td>
    <td><?= e($r['rank']) ?></td><td><?= e((string)($r['station'] ?? '-')) ?></td>
    <?php if ($isAdmin): ?><td><a href="officers.php?action=edit&amp;id=<?= e($r['officer_id']) ?>">Edit</a></td><?php endif; ?>
  </tr><?php endforeach; ?>
  </tbody></table></div>
<?php else: ?>
  <?php
  $edit = null;
  if ($action === 'edit') {
    $s = $pdo->prepare('SELECT * FROM officers WHERE officer_id = :id LIMIT 1');
    $s->execute([':id' => $_GET['id'] ?? '']);
    $edit = $s->fetch();
    if (!$edit) { echo '<div class="alert alert-warning" role="alert">Officer not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
    $edit['contact_info'] = enc_decrypt($edit['contact_info'] ?? null);
  }
  ?>
  <div class="card" style="max-width:38rem"><div class="card-body">
  <h2 class="h5"><?= $action === 'add' ? 'Register officer' : 'Edit officer ' . e($edit['officer_id']) ?></h2>
  <form method="post" action="officers.php?action=<?= $action ?><?= $edit ? '&amp;id=' . e($edit['officer_id']) : '' ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($edit): ?><input type="hidden" name="officer_id" value="<?= e($edit['officer_id']) ?>"><?php endif; ?>
    <div class="mb-3"><label class="form-label" for="service_number">Service number</label><input class="form-control" id="service_number" name="service_number" required maxlength="30" value="<?= e($edit['service_number'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="name">Full name</label><input class="form-control" id="name" name="name" required maxlength="100" value="<?= e($edit['name'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="rank">Rank</label><input class="form-control" id="rank" name="rank" required maxlength="50" value="<?= e($edit['rank'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="department">Department</label><input class="form-control" id="department" name="department" maxlength="80" value="<?= e($edit['department'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="station">Station</label><input class="form-control" id="station" name="station" maxlength="80" value="<?= e($edit['station'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="contact_info">Contact info</label><input class="form-control" id="contact_info" name="contact_info" maxlength="120" value="<?= e($edit['contact_info'] ?? '') ?>"></div>
    <button class="btn btn-primary" type="submit"><?= $action === 'add' ? 'Register' : 'Save' ?></button>
    <a class="btn btn-secondary" href="officers.php">Cancel</a>
  </form>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

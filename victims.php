<?php
// victims.php - Complainant/Victim register linked to crimes
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_once __DIR__ . '/includes/crypto.php';
require_login();

$action = $_GET['action'] ?? 'list';
$errors = [];
$success = '';
$pdo = db();

if (in_array($action, ['add', 'edit'], true) && !can('register_crime')) {
  http_response_code(403); $pageTitle = 'Forbidden'; $activeNav = 'victims';
  require __DIR__ . '/includes/header.php';
  echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Cannot manage victims.</div>';
  require __DIR__ . '/includes/footer.php'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
  if (!csrf_check($_POST['csrf'] ?? null)) { $errors[] = 'Invalid session. Please reload.'; }
  else {
    $name = trim($_POST['full_name'] ?? '');
    $crimeId = $_POST['crime_id'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $contact = trim($_POST['contact_info'] ?? '');
    $addr = trim($_POST['address'] ?? '');
    $stmt2 = trim($_POST['statement'] ?? '');
    if ($name === '' || strlen($name) > 100) $errors[] = 'Full name required (max 100).';
    if ($gender !== '' && !in_array($gender, genders(), true)) $errors[] = 'Invalid gender.';
    if ($crimeId !== '') { $c = $pdo->prepare('SELECT 1 FROM crimes WHERE crime_id = :c LIMIT 1'); $c->execute([':c' => $crimeId]); if (!$c->fetchColumn()) $errors[] = 'Linked crime not found.'; }
    if (!$errors) {
      try {
        $enc = $contact !== '' ? enc_encrypt($contact) : null;
        if ($action === 'add') {
          $id = gen_id('VC');
          $pdo->prepare('INSERT INTO victims (victim_id, crime_id, full_name, gender, contact_info, address, statement) VALUES (:id,:c,:n,:g,:ct,:a,:s)')->execute([':id' => $id, ':c' => $crimeId ?: null, ':n' => $name, ':g' => $gender ?: null, ':ct' => $enc, ':a' => $addr ?: null, ':s' => $stmt2 ?: null]);
          audit('VICTIM_CREATE', $id, 'success', $name);
          $success = 'Victim registered (' . $id . ').';
        } else {
          $vid = $_POST['victim_id'] ?? '';
          $pdo->prepare('UPDATE victims SET crime_id=:c, full_name=:n, gender=:g, contact_info=:ct, address=:a, statement=:s WHERE victim_id=:id')->execute([':c' => $crimeId ?: null, ':n' => $name, ':g' => $gender ?: null, ':ct' => $enc, ':a' => $addr ?: null, ':s' => $stmt2 ?: null, ':id' => $vid]);
          audit('VICTIM_UPDATE', $vid, 'success', $name);
          $success = 'Victim updated.';
        }
      } catch (Throwable $t) { $errors[] = 'System error. Please try again.'; }
    }
  }
}

$pageTitle = 'Victims';
$activeNav = 'victims';
require __DIR__ . '/includes/header.php';
?>
<h1>Victims / Complainants</h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" autofocus><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($action === 'list'): ?>
  <?php if (can('register_crime')): ?><p><a class="btn btn-primary" href="victims.php?action=add">Register victim</a></p><?php endif; ?>
  <?php $rows = $pdo->query('SELECT v.*, c.crime_type FROM victims v LEFT JOIN crimes c ON c.crime_id = v.crime_id ORDER BY v.date_created DESC LIMIT 200')->fetchAll(); ?>
  <div class="table-responsive"><table class="table table-striped"><caption>Victims (<?= count($rows) ?>)</caption><thead><tr><th scope="col">ID</th><th scope="col">Name</th><th scope="col">Crime</th><th scope="col">Actions</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="4">No victims yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr><td><?= e($r['victim_id']) ?></td><td><?= e($r['full_name']) ?></td><td><?= e((string)($r['crime_type'] ?? $r['crime_id'] ?? '-')) ?></td><td><a href="victims.php?action=view&amp;id=<?= e($r['victim_id']) ?>">View</a><?php if (can('register_crime')): ?> | <a href="victims.php?action=edit&amp;id=<?= e($r['victim_id']) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
<?php elseif ($action === 'view'): ?>
  <?php $s = $pdo->prepare('SELECT v.*, c.crime_type FROM victims v LEFT JOIN crimes c ON c.crime_id = v.crime_id WHERE v.victim_id = :id LIMIT 1'); $s->execute([':id' => $_GET['id'] ?? '']); $v = $s->fetch(); if (!$v) { echo '<div class="alert alert-warning" role="alert">Not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; } $v['contact_info'] = enc_decrypt($v['contact_info'] ?? null); ?>
  <div class="card"><div class="card-body"><h2 class="h5"><?= e($v['victim_id']) ?> - <?= e($v['full_name']) ?></h2>
  <dl class="row"><dt class="col-sm-3">Crime</dt><dd class="col-sm-9"><?= e((string)($v['crime_type'] ?? $v['crime_id'] ?? '-')) ?></dd><dt class="col-sm-3">Contact</dt><dd class="col-sm-9"><?= e((string)($v['contact_info'] ?? '-')) ?></dd><dt class="col-sm-3">Address</dt><dd class="col-sm-9"><?= e((string)($v['address'] ?? '-')) ?></dd><dt class="col-sm-3">Statement</dt><dd class="col-sm-9"><?= e((string)($v['statement'] ?? '-')) ?></dd></dl>
  <a class="btn btn-sm btn-secondary" href="victims.php">Back</a></div></div>
<?php else: ?>
  <?php $edit = null; if ($action === 'edit') { $s = $pdo->prepare('SELECT * FROM victims WHERE victim_id = :id LIMIT 1'); $s->execute([':id' => $_GET['id'] ?? '']); $edit = $s->fetch(); if (!$edit) { echo '<div class="alert alert-warning" role="alert">Not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; } $edit['contact_info'] = enc_decrypt($edit['contact_info'] ?? null); } $crimes = $pdo->query('SELECT crime_id, crime_type FROM crimes ORDER BY date_created DESC LIMIT 200')->fetchAll(); ?>
  <div class="card" style="max-width:40rem"><div class="card-body"><h2 class="h5"><?= $action === 'add' ? 'Register victim' : 'Edit victim' ?></h2>
  <form method="post" action="victims.php?action=<?= $action ?><?= $edit ? '&amp;id=' . e($edit['victim_id']) : '' ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><?php if ($edit): ?><input type="hidden" name="victim_id" value="<?= e($edit['victim_id']) ?>"><?php endif; ?>
  <div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" required maxlength="100" value="<?= e($edit['full_name'] ?? '') ?>"></div>
  <div class="mb-3"><label class="form-label" for="crime_id">Linked crime</label><select class="form-select" id="crime_id" name="crime_id"><option value="">-- none --</option><?php foreach ($crimes as $c): ?><option value="<?= e($c['crime_id']) ?>" <?= ($edit['crime_id'] ?? '') === $c['crime_id'] ? 'selected' : '' ?>><?= e($c['crime_id']) ?> - <?= e($c['crime_type']) ?></option><?php endforeach; ?></select></div>
  <div class="mb-3"><label class="form-label" for="gender">Gender</label><select class="form-select" id="gender" name="gender"><option value="">--</option><?php foreach (genders() as $g): ?><option <?= ($edit['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></div>
  <div class="mb-3"><label class="form-label" for="contact_info">Contact (encrypted)</label><input class="form-control" id="contact_info" name="contact_info" maxlength="120" value="<?= e($edit['contact_info'] ?? '') ?>"></div>
  <div class="mb-3"><label class="form-label" for="address">Address</label><input class="form-control" id="address" name="address" maxlength="200" value="<?= e($edit['address'] ?? '') ?>"></div>
  <div class="mb-3"><label class="form-label" for="statement">Statement</label><textarea class="form-control" id="statement" name="statement" rows="3"><?= e($edit['statement'] ?? '') ?></textarea></div>
  <button class="btn btn-primary" type="submit"><?= $action === 'add' ? 'Register' : 'Save' ?></button> <a class="btn btn-secondary" href="victims.php">Cancel</a></form></div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

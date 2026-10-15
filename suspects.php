<?php
// suspects.php - secure photo upload (finfo + 2MB + re-encode strips EXIF)
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();

$action = $_GET['action'] ?? 'list';
$errors = [];
$success = '';
$pdo = db();

if (in_array($action, ['add', 'edit'], true) && !can('register_crime')) {
  http_response_code(403); $pageTitle = 'Forbidden'; $activeNav = 'suspects';
  require __DIR__ . '/includes/header.php';
  echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Cannot manage suspects.</div>';
  require __DIR__ . '/includes/footer.php'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $errors[] = 'Invalid session. Please reload.';
  } else {
    $name = trim($_POST['full_name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['date_of_birth'] ?? '';
    $addr = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact_info'] ?? '');
    $idd = trim($_POST['id_details'] ?? '');
    if ($name === '' || strlen($name) > 100) $errors[] = 'Full name is required (max 100 chars).';
    if ($gender !== '' && !in_array($gender, genders(), true)) $errors[] = 'Invalid gender.';
    if ($dob !== '' && !valid_date($dob)) $errors[] = 'Invalid date of birth.';
    $photoRel = null;
    if (!$errors) {
      try {
        $photoRel = handle_photo_upload($_FILES['photo'] ?? []);
      } catch (RuntimeException $rx) { $errors[] = $rx->getMessage(); }
    }
    if (!$errors) {
      try {
        $contactEnc = $contact !== '' ? enc_encrypt($contact) : null;
        $iddEnc = $idd !== '' ? enc_encrypt($idd) : null;
        if ($action === 'add') {
          $id = gen_id('SP');
          $stmt = $pdo->prepare('INSERT INTO suspects (suspect_id, full_name, gender, date_of_birth, address, contact_info, id_details, photo_path) VALUES (:id,:n,:g,:d,:a,:c,:i,:p)');
          $stmt->execute([':id' => $id, ':n' => $name, ':g' => $gender ?: null, ':d' => $dob ?: null, ':a' => $addr ?: null, ':c' => $contactEnc, ':i' => $iddEnc, ':p' => $photoRel]);
          audit('SUSPECT_CREATE', $id, 'success', $name);
          $success = 'Suspect registered (' . $id . ').';
        } else {
          $sid = $_POST['suspect_id'] ?? '';
          if ($photoRel) {
            $old = $pdo->prepare('SELECT photo_path FROM suspects WHERE suspect_id = :id LIMIT 1');
            $old->execute([':id' => $sid]);
            $oldPath = $old->fetchColumn();
            $stmt = $pdo->prepare('UPDATE suspects SET full_name=:n, gender=:g, date_of_birth=:d, address=:a, contact_info=:c, id_details=:i, photo_path=:p WHERE suspect_id=:id');
            $stmt->execute([':n' => $name, ':g' => $gender ?: null, ':d' => $dob ?: null, ':a' => $addr ?: null, ':c' => $contactEnc, ':i' => $iddEnc, ':p' => $photoRel, ':id' => $sid]);
            if ($oldPath && $oldPath !== $photoRel) { @unlink(dirname(__DIR__) . '/' . $oldPath); }
          } else {
            $stmt = $pdo->prepare('UPDATE suspects SET full_name=:n, gender=:g, date_of_birth=:d, address=:a, contact_info=:c, id_details=:i WHERE suspect_id=:id');
            $stmt->execute([':n' => $name, ':g' => $gender ?: null, ':d' => $dob ?: null, ':a' => $addr ?: null, ':c' => $contactEnc, ':i' => $iddEnc, ':id' => $sid]);
          }
          audit('SUSPECT_UPDATE', $sid, 'success', $name);
          $success = 'Suspect updated.';
        }
      } catch (Throwable $t) { $errors[] = 'System error. Please try again.'; }
    }
  }
}

$pageTitle = 'Suspects';
$activeNav = 'suspects';
require __DIR__ . '/includes/header.php';
?>
<h1>Suspects</h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" autofocus><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>
  <?php if (can('register_crime')): ?><p><a class="btn btn-primary" href="suspects.php?action=add">Register suspect</a></p><?php endif; ?>
  <?php $rows = $pdo->query('SELECT * FROM suspects ORDER BY date_created DESC LIMIT 200')->fetchAll(); ?>
  <div class="table-responsive"><table class="table table-striped">
  <caption>Registered suspects (<?= count($rows) ?>)</caption>
  <thead><tr><th scope="col">ID</th><th scope="col">Photo</th><th scope="col">Name</th><th scope="col">Gender</th><th scope="col">DOB</th><th scope="col">Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6">No suspects yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr>
    <td><?= e($r['suspect_id']) ?></td>
    <td><?php if (!empty($r['photo_path'])): ?><img src="<?= e($r['photo_path']) ?>" alt="Photo of <?= e($r['full_name']) ?>" width="48" height="48" style="object-fit:cover;border-radius:.25rem"><?php else: ?>-<?php endif; ?></td>
    <td><?= e($r['full_name']) ?></td><td><?= e((string)($r['gender'] ?? '-')) ?></td><td><?= e((string)($r['date_of_birth'] ?? '-')) ?></td>
    <td><a href="suspects.php?action=view&amp;id=<?= e($r['suspect_id']) ?>">View</a> | <a href="suspects.php?action=edit&amp;id=<?= e($r['suspect_id']) ?>">Edit</a></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
<?php elseif ($action === 'view'): ?>
  <?php
  $s = $pdo->prepare('SELECT * FROM suspects WHERE suspect_id = :id LIMIT 1');
  $s->execute([':id' => $_GET['id'] ?? '']);
  $sp = $s->fetch();
  if (!$sp) { echo '<div class="alert alert-warning" role="alert">Suspect not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
  $sp['contact_info'] = enc_decrypt($sp['contact_info'] ?? null);
  $sp['id_details'] = enc_decrypt($sp['id_details'] ?? null);
  $cs = $pdo->prepare('SELECT c.case_id, c.case_number FROM case_suspects cs JOIN cases c ON c.case_id = cs.case_id WHERE cs.suspect_id = :id');
  $cs->execute([':id' => $sp['suspect_id']]);
  $linked = $cs->fetchAll();
  ?>
  <div class="card mb-3"><div class="card-body">
    <h2 class="h5"><?= e($sp['suspect_id']) ?> - <?= e($sp['full_name']) ?></h2>
    <?php if (!empty($sp['photo_path'])): ?><img src="<?= e($sp['photo_path']) ?>" alt="Photo of <?= e($sp['full_name']) ?>" width="160" style="border-radius:.5rem" class="mb-3"><?php endif; ?>
    <dl class="row"><dt class="col-sm-3">Gender</dt><dd class="col-sm-9"><?= e((string)($sp['gender'] ?? '-')) ?></dd>
    <dt class="col-sm-3">DOB</dt><dd class="col-sm-9"><?= e((string)($sp['date_of_birth'] ?? '-')) ?></dd>
    <dt class="col-sm-3">Address</dt><dd class="col-sm-9"><?= e((string)($sp['address'] ?? '-')) ?></dd>
    <dt class="col-sm-3">ID details</dt><dd class="col-sm-9"><?= e((string)($sp['id_details'] ?? '-')) ?></dd></dl>
    <a class="btn btn-sm btn-outline-primary" href="suspects.php?action=edit&amp;id=<?= e($sp['suspect_id']) ?>">Edit</a>
    <a class="btn btn-sm btn-secondary" href="suspects.php">Back</a>
  </div></div>
  <h2 class="h5">Linked cases (<?= count($linked) ?>)</h2>
  <ul><?php foreach ($linked as $lc): ?><li><a href="cases.php?action=view&amp;id=<?= e($lc['case_id']) ?>"><?= e($lc['case_number']) ?></a></li><?php endforeach; ?></ul>
<?php else: ?>
  <?php
  $edit = null;
  if ($action === 'edit') {
    $s = $pdo->prepare('SELECT * FROM suspects WHERE suspect_id = :id LIMIT 1');
    $s->execute([':id' => $_GET['id'] ?? '']);
    $edit = $s->fetch();
    if (!$edit) { echo '<div class="alert alert-warning" role="alert">Suspect not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
    $edit['contact_info'] = enc_decrypt($edit['contact_info'] ?? null);
    $edit['id_details'] = enc_decrypt($edit['id_details'] ?? null);
  }
  ?>
  <div class="card" style="max-width:40rem"><div class="card-body">
  <h2 class="h5"><?= $action === 'add' ? 'Register suspect' : 'Edit suspect ' . e($edit['suspect_id']) ?></h2>
  <form method="post" enctype="multipart/form-data" action="suspects.php?action=<?= $action ?><?= $edit ? '&amp;id=' . e($edit['suspect_id']) : '' ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($edit): ?><input type="hidden" name="suspect_id" value="<?= e($edit['suspect_id']) ?>"><?php endif; ?>
    <div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" required maxlength="100" value="<?= e($edit['full_name'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="gender">Gender</label><select class="form-select" id="gender" name="gender"><option value="">--</option><?php foreach (genders() as $g): ?><option <?= ($edit['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label" for="date_of_birth">Date of birth</label><input class="form-control" type="date" id="date_of_birth" name="date_of_birth" value="<?= e((string)($edit['date_of_birth'] ?? '')) ?>"></div>
    <div class="mb-3"><label class="form-label" for="address">Address</label><input class="form-control" id="address" name="address" maxlength="200" value="<?= e($edit['address'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="contact_info">Contact info</label><input class="form-control" id="contact_info" name="contact_info" maxlength="120" value="<?= e($edit['contact_info'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="id_details">Identification details</label><input class="form-control" id="id_details" name="id_details" maxlength="150" value="<?= e($edit['id_details'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="photo">Photo (JPG/PNG, max 2MB - EXIF stripped on save)</label><input class="form-control" type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png"></div>
    <button class="btn btn-primary" type="submit"><?= $action === 'add' ? 'Register' : 'Save' ?></button>
    <a class="btn btn-secondary" href="suspects.php">Cancel</a>
  </form>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

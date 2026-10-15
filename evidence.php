<?php
// evidence.php - Records blocked; add IO (+Admin override); view Admin/IO/Supervisor
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();

$action = $_GET['action'] ?? 'list';
$errors = [];
$success = '';
$pdo = db();
$role = $_SESSION['role_name'] ?? '';
$isAdmin = ($role === 'Administrator');
$canAdd = ($role === 'Investigating Officer') || $isAdmin; // matrix: IO yes; Admin override documented
$canView = in_array($role, ['Administrator', 'Investigating Officer', 'Supervisor'], true);

if (!$canView) {
  http_response_code(403); $pageTitle = 'Forbidden'; $activeNav = 'evidence';
  require __DIR__ . '/includes/header.php';
  echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Records Officers cannot access evidence.</div>';
  require __DIR__ . '/includes/footer.php'; exit;
}
if (in_array($action, ['add', 'edit'], true) && !$canAdd) {
  http_response_code(403); $pageTitle = 'Forbidden'; $activeNav = 'evidence';
  require __DIR__ . '/includes/header.php';
  echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Only Investigating Officers (and Administrator) may manage evidence.</div>';
  require __DIR__ . '/includes/footer.php'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $errors[] = 'Invalid session. Please reload.';
  } else {
    $caseId = $_POST['case_id'] ?? '';
    $type = $_POST['evidence_type'] ?? '';
    $desc = trim($_POST['description'] ?? '');
    $collected = $_POST['date_collected'] ?? '';
    $officer = $_POST['collecting_officer_id'] ?? '';
    $storage = trim($_POST['storage_ref'] ?? '');
    $s = $pdo->prepare('SELECT officer_id FROM cases WHERE case_id = :c LIMIT 1');
    $s->execute([':c' => $caseId]);
    $caseRow = $s->fetch();
    if (!$caseRow) $errors[] = 'Selected case does not exist.';
    elseif ($role === 'Investigating Officer' && !case_assigned_to_me($caseId)) $errors[] = 'You may add evidence only to your assigned cases.';
    if (!in_array($type, evidence_types(), true)) $errors[] = 'Select a valid evidence type.';
    if ($collected !== '' && !valid_date($collected)) $errors[] = 'Invalid collection date.';
    if ($officer !== '') {
      $s = $pdo->prepare('SELECT 1 FROM officers WHERE officer_id = :o LIMIT 1');
      $s->execute([':o' => $officer]);
      if (!$s->fetchColumn()) $errors[] = 'Collecting officer does not exist.';
    }
    if (!$errors) {
      try {
        if ($action === 'add') {
          $id = gen_id('EV');
          $h = evidence_hash($id, $type, $desc ?: null, $storage ?: null);
          $stmt = $pdo->prepare('INSERT INTO evidence (evidence_id, case_id, evidence_type, description, date_collected, collecting_officer_id, storage_ref, evidence_hash) VALUES (:id,:c,:t,:d,:dt,:o,:s,:h)');
          $stmt->execute([':id' => $id, ':c' => $caseId, ':t' => $type, ':d' => $desc ?: null, ':dt' => $collected ?: null, ':o' => $officer ?: null, ':s' => $storage ?: null, ':h' => $h]);
          $pdo->prepare('INSERT INTO evidence_custody (evidence_id, action, handler, notes, created_by) VALUES (:e,\'Collected\',:h,:n,:u)')->execute([':e' => $id, ':h' => $officer ?: ($role ?? ''), ':n' => 'Initial collection for ' . $caseId, ':u' => $_SESSION['user_id'] ?? null]);
          audit('EVIDENCE_CREATE', $id, 'success', $caseId);
          $success = 'Evidence recorded (' . $id . '). Hash sealed.';
        } else {
          $eid = $_POST['evidence_id'] ?? '';
          $chk = $pdo->prepare('SELECT case_id FROM evidence WHERE evidence_id = :id LIMIT 1');
          $chk->execute([':id' => $eid]);
          $oldCase = $chk->fetchColumn();
          if ($role === 'Investigating Officer' && $oldCase && !case_assigned_to_me((string)$oldCase)) {
            $errors[] = 'You may edit only evidence in your assigned cases.';
          } else {
            $h = evidence_hash($eid, $type, $desc ?: null, $storage ?: null);
            $stmt = $pdo->prepare('UPDATE evidence SET case_id=:c, evidence_type=:t, description=:d, date_collected=:dt, collecting_officer_id=:o, storage_ref=:s, evidence_hash=:h WHERE evidence_id=:id');
            $stmt->execute([':c' => $caseId, ':t' => $type, ':d' => $desc ?: null, ':dt' => $collected ?: null, ':o' => $officer ?: null, ':s' => $storage ?: null, ':h' => $h, ':id' => $eid]);
            audit('EVIDENCE_UPDATE', $eid, 'success', $caseId);
            $success = 'Evidence updated. Hash renewed.';
          }
        }
      } catch (Throwable $t) { $errors[] = 'System error. Please try again.'; }
    }
  }
}

$pageTitle = 'Evidence';
$activeNav = 'evidence';
require __DIR__ . '/includes/header.php';
?>
<h1>Evidence</h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" autofocus><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>
  <?php if ($canAdd): ?><p><a class="btn btn-primary" href="evidence.php?action=add">Record evidence</a></p><?php endif; ?>
  <?php $rows = $pdo->query('SELECT e.*, c.case_number FROM evidence e JOIN cases c ON c.case_id = e.case_id ORDER BY e.date_created DESC LIMIT 200')->fetchAll(); ?>
  <div class="table-responsive"><table class="table table-striped">
  <caption>Evidence records (<?= count($rows) ?>)</caption>
  <thead><tr><th scope="col">ID</th><th scope="col">Case</th><th scope="col">Type</th><th scope="col">Collected</th><th scope="col">Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5">No evidence yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr>
    <td><?= e($r['evidence_id']) ?></td><td><?= e($r['case_number']) ?></td><td><?= e($r['evidence_type']) ?></td><td><?= e((string)($r['date_collected'] ?? '-')) ?></td>
    <td><a href="evidence.php?action=view&amp;id=<?= e($r['evidence_id']) ?>">View</a><?php if ($canAdd): ?> | <a href="evidence.php?action=edit&amp;id=<?= e($r['evidence_id']) ?>">Edit</a><?php endif; ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
<?php elseif ($action === 'view'): ?>
  <?php
  $s = $pdo->prepare('SELECT e.*, c.case_number FROM evidence e JOIN cases c ON c.case_id = e.case_id WHERE e.evidence_id = :id LIMIT 1');
  $s->execute([':id' => $_GET['id'] ?? '']);
  $ev = $s->fetch();
  if (!$ev) { echo '<div class="alert alert-warning" role="alert">Evidence not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
  $calc = evidence_hash($ev['evidence_id'], $ev['evidence_type'], $ev['description'], $ev['storage_ref']);
  $sealed = !empty($ev['evidence_hash']) && hash_equals((string)$ev['evidence_hash'], (string)$calc);
  $cust = $pdo->prepare('SELECT * FROM evidence_custody WHERE evidence_id = :e ORDER BY created_at DESC LIMIT 50');
  $cust->execute([':e' => $ev['evidence_id']]);
  $custody = $cust->fetchAll();
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'custody' && $canAdd) {
    if (csrf_check($_POST['csrf'] ?? null)) {
      $ca = $_POST['custody_action'] ?? 'Stored';
      if (in_array($ca, ['Collected','Stored','Transferred','Released','Returned'], true)) {
        $pdo->prepare('INSERT INTO evidence_custody (evidence_id, action, handler, notes, created_by) VALUES (:e,:a,:h,:n,:u)')->execute([':e' => $ev['evidence_id'], ':a' => $ca, ':h' => substr(trim($_POST['handler'] ?? ''), 0, 100) ?: null, ':n' => substr(trim($_POST['notes'] ?? ''), 0, 255) ?: null, ':u' => $_SESSION['user_id'] ?? null]);
        audit('CUSTODY_ADD', $ev['evidence_id'], 'success', $ca);
        $cust->execute([':e' => $ev['evidence_id']]);
        $custody = $cust->fetchAll();
        echo '<div class="alert alert-success" role="status">Custody entry recorded.</div>';
      }
    }
  }
  ?>
  <div class="card mb-3"><div class="card-body">
    <h2 class="h5"><?= e($ev['evidence_id']) ?> - <?= e($ev['evidence_type']) ?></h2>
    <dl class="row"><dt class="col-sm-3">Case</dt><dd class="col-sm-9"><a href="cases.php?action=view&amp;id=<?= e($ev['case_id']) ?>"><?= e($ev['case_number']) ?></a></dd>
    <dt class="col-sm-3">Collected</dt><dd class="col-sm-9"><?= e((string)($ev['date_collected'] ?? '-')) ?></dd>
    <dt class="col-sm-3">Storage</dt><dd class="col-sm-9"><?= e((string)($ev['storage_ref'] ?? '-')) ?></dd>
    <dt class="col-sm-3">Description</dt><dd class="col-sm-9"><?= e((string)($ev['description'] ?? '-')) ?></dd>
    <dt class="col-sm-3">Integrity</dt><dd class="col-sm-9"><?php if ($sealed): ?><span class="badge bg-success">Sealed <?= e(substr((string)$ev['evidence_hash'], 0, 12)) ?>…</span><?php else: ?><span class="badge bg-danger">Hash mismatch — investigate</span><?php endif; ?></dd></dl>
    <a class="btn btn-sm btn-secondary" href="evidence.php">Back</a>
  </div></div>
  <h2 class="h5">Chain of custody (<?= count($custody) ?>)</h2>
  <div class="table-responsive"><table class="table table-sm"><caption>Custody trail</caption><thead><tr><th scope="col">Time</th><th scope="col">Action</th><th scope="col">Handler</th><th scope="col">Notes</th></tr></thead><tbody>
  <?php foreach ($custody as $cu): ?><tr><td><?= e((string)$cu['created_at']) ?></td><td><?= e($cu['action']) ?></td><td><?= e((string)($cu['handler'] ?? '-')) ?></td><td><?= e((string)($cu['notes'] ?? '-')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($canAdd): ?><div class="card mt-3"><div class="card-body"><h3 class="h6">Add custody entry</h3><form method="post" action="evidence.php?action=view&amp;id=<?= e($ev['evidence_id']) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="custody"><div class="row g-2"><div class="col-md-3"><label class="form-label" for="ca">Action</label><select class="form-select" id="ca" name="custody_action"><option>Stored</option><option>Transferred</option><option>Released</option><option>Returned</option><option>Collected</option></select></div><div class="col-md-3"><label class="form-label" for="ch">Handler</label><input class="form-control" id="ch" name="handler" maxlength="100"></div><div class="col-md-4"><label class="form-label" for="cn">Notes</label><input class="form-control" id="cn" name="notes" maxlength="255"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary" type="submit">Add</button></div></div></form></div></div><?php endif; ?>
<?php else: ?>
  <?php
  $edit = null;
  if ($action === 'edit') {
    $s = $pdo->prepare('SELECT * FROM evidence WHERE evidence_id = :id LIMIT 1');
    $s->execute([':id' => $_GET['id'] ?? '']);
    $edit = $s->fetch();
    if (!$edit) { echo '<div class="alert alert-warning" role="alert">Evidence not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
  }
  $cases = $pdo->query('SELECT case_id, case_number FROM cases ORDER BY date_created DESC LIMIT 200')->fetchAll();
  $officers = $pdo->query('SELECT officer_id, name FROM officers ORDER BY name')->fetchAll();
  ?>
  <div class="card" style="max-width:40rem"><div class="card-body">
  <h2 class="h5"><?= $action === 'add' ? 'Record evidence' : 'Edit evidence ' . e($edit['evidence_id']) ?></h2>
  <form method="post" action="evidence.php?action=<?= $action ?><?= $edit ? '&amp;id=' . e($edit['evidence_id']) : '' ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($edit): ?><input type="hidden" name="evidence_id" value="<?= e($edit['evidence_id']) ?>"><?php endif; ?>
    <div class="mb-3"><label class="form-label" for="case_id">Case</label><select class="form-select" id="case_id" name="case_id" required><?php foreach ($cases as $c): ?><option value="<?= e($c['case_id']) ?>" <?= ($edit['case_id'] ?? '') === $c['case_id'] ? 'selected' : '' ?>><?= e($c['case_number']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label" for="evidence_type">Type</label><select class="form-select" id="evidence_type" name="evidence_type"><?php foreach (evidence_types() as $t): ?><option <?= ($edit['evidence_type'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" rows="3"><?= e($edit['description'] ?? '') ?></textarea></div>
    <div class="mb-3"><label class="form-label" for="date_collected">Date collected</label><input class="form-control" type="date" id="date_collected" name="date_collected" value="<?= e((string)($edit['date_collected'] ?? '')) ?>"></div>
    <div class="mb-3"><label class="form-label" for="collecting_officer_id">Collecting officer</label><select class="form-select" id="collecting_officer_id" name="collecting_officer_id"><option value="">-- none --</option><?php foreach ($officers as $o): ?><option value="<?= e($o['officer_id']) ?>" <?= ($edit['collecting_officer_id'] ?? '') === $o['officer_id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label" for="storage_ref">Storage reference</label><input class="form-control" id="storage_ref" name="storage_ref" maxlength="150" value="<?= e($edit['storage_ref'] ?? '') ?>"></div>
    <button class="btn btn-primary" type="submit"><?= $action === 'add' ? 'Record' : 'Save' ?></button>
    <a class="btn btn-secondary" href="evidence.php">Cancel</a>
  </form>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
// cases.php - Crime -> Case -> Suspect linking; Records cannot edit investigations
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();

$action = $_GET['action'] ?? 'list';
$errors = [];
$success = '';
$pdo = db();
$role = $_SESSION['role_name'] ?? '';

if ($action === 'add' && !can('register_crime')) {
  http_response_code(403); $pageTitle = 'Forbidden'; $activeNav = 'cases';
  require __DIR__ . '/includes/header.php';
  echo '<h1>403 - Forbidden</h1><div class="alert alert-danger" role="alert">Cannot open cases.</div>';
  require __DIR__ . '/includes/footer.php'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
  if (!csrf_check($_POST['csrf'] ?? null)) {
    $errors[] = 'Invalid session. Please reload.';
  } elseif ($action === 'edit' && !can('update_investigation') && $role === 'Records Officer') {
    $errors[] = 'Records Officers cannot update investigations.';
  } elseif ($action === 'edit' && !can('update_crime') && !can('update_investigation')) {
    $errors[] = 'Your role cannot update cases.';
  } else {
    $crimeId = $_POST['crime_id'] ?? '';
    $officerId = $_POST['officer_id'] ?? '';
    $caseNo = trim($_POST['case_number'] ?? '');
    $opened = $_POST['date_opened'] ?? '';
    $inv = $_POST['investigation_status'] ?? 'Open';
    $charges = substr(trim($_POST['charges'] ?? ''), 0, 2000);
    $hearing = $_POST['next_hearing'] ?? '';
    $custody = substr(trim($_POST['custody_status'] ?? ''), 0, 80);
    $timelineTitle = substr(trim($_POST['timeline_title'] ?? ''), 0, 150);
    $timelineDetails = substr(trim($_POST['timeline_details'] ?? ''), 0, 2000);
    $suspects = array_filter((array)($_POST['suspects'] ?? []));
    $s = $pdo->prepare('SELECT 1 FROM crimes WHERE crime_id = :c LIMIT 1');
    $s->execute([':c' => $crimeId]);
    if (!$s->fetchColumn()) $errors[] = 'Selected crime does not exist.';
    if ($officerId !== '') {
      $s = $pdo->prepare('SELECT 1 FROM officers WHERE officer_id = :o LIMIT 1');
      $s->execute([':o' => $officerId]);
      if (!$s->fetchColumn()) $errors[] = 'Selected officer does not exist.';
    }
    if ($caseNo === '' || strlen($caseNo) > 40) $errors[] = 'Case number is required (max 40).';
    if (!valid_date($opened)) $errors[] = 'Enter a valid opening date.';
    if (!in_array($inv, case_statuses(), true)) $errors[] = 'Invalid investigation status.';
    if ($hearing !== '' && !valid_date($hearing)) $errors[] = 'Invalid hearing date.';
    if ($action === 'edit') {
      $cid = $_POST['case_id'] ?? '';
      if ($role === 'Investigating Officer' && !case_assigned_to_me($cid)) {
        $errors[] = 'Investigating Officers may edit only assigned cases.';
      }
    }
    if (!$errors) {
      try {
        $pdo->beginTransaction();
        if ($action === 'add') {
          $id = gen_id('CS');
          $stmt = $pdo->prepare('INSERT INTO cases (case_id, crime_id, officer_id, case_number, date_opened, investigation_status, charges, next_hearing, custody_status) VALUES (:id,:c,:o,:n,:d,:s,:ch,:nh,:cu)');
          $stmt->execute([':id' => $id, ':c' => $crimeId, ':o' => $officerId ?: null, ':n' => $caseNo, ':d' => $opened, ':s' => $inv, ':ch' => $charges ?: null, ':nh' => $hearing ?: null, ':cu' => $custody ?: null]);
          foreach ($suspects as $sp) {
            $chk = $pdo->prepare('SELECT 1 FROM suspects WHERE suspect_id = :s LIMIT 1');
            $chk->execute([':s' => $sp]);
            if ($chk->fetchColumn()) {
              $pdo->prepare('INSERT IGNORE INTO case_suspects (case_id, suspect_id) VALUES (:c,:s)')->execute([':c' => $id, ':s' => $sp]);
            }
          }
          audit('CASE_CREATE', $id, 'success', $caseNo);
          $success = 'Case opened (' . $id . ').';
        } else {
          $cid = $_POST['case_id'] ?? '';
          $stmt = $pdo->prepare('UPDATE cases SET crime_id=:c, officer_id=:o, case_number=:n, date_opened=:d, investigation_status=:s, charges=:ch, next_hearing=:nh, custody_status=:cu WHERE case_id=:id');
          $stmt->execute([':c' => $crimeId, ':o' => $officerId ?: null, ':n' => $caseNo, ':d' => $opened, ':s' => $inv, ':ch' => $charges ?: null, ':nh' => $hearing ?: null, ':cu' => $custody ?: null, ':id' => $cid]);
          if ($timelineTitle !== '') {
            $pdo->prepare('INSERT INTO case_events (case_id, event_type, title, details, event_date, created_by) VALUES (:c,\'Note\',:t,:d,CURDATE(),:u)')->execute([':c' => $cid, ':t' => $timelineTitle, ':d' => $timelineDetails ?: null, ':u' => $_SESSION['user_id'] ?? null]);
          }
          $pdo->prepare('DELETE FROM case_suspects WHERE case_id = :c')->execute([':c' => $cid]);
          foreach ($suspects as $sp) {
            $chk = $pdo->prepare('SELECT 1 FROM suspects WHERE suspect_id = :s LIMIT 1');
            $chk->execute([':s' => $sp]);
            if ($chk->fetchColumn()) {
              $pdo->prepare('INSERT IGNORE INTO case_suspects (case_id, suspect_id) VALUES (:c,:s)')->execute([':c' => $cid, ':s' => $sp]);
            }
          }
          audit('CASE_UPDATE', $cid, 'success', $caseNo);
          $success = 'Case updated.';
        }
        $pdo->commit();
      } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = ($ex->getCode() === '23000') ? 'Case number already exists.' : 'System error. Please try again.';
      } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'System error. Please try again.';
      }
    }
  }
}

$pageTitle = 'Cases';
$activeNav = 'cases';
require __DIR__ . '/includes/header.php';
?>
<h1>Cases</h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" autofocus><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>
  <?php if (can('register_crime')): ?><p><a class="btn btn-primary" href="cases.php?action=add">Open case</a></p><?php endif; ?>
  <?php $rows = $pdo->query('SELECT cs.*, c.crime_type, o.name AS officer_name FROM cases cs JOIN crimes c ON c.crime_id = cs.crime_id LEFT JOIN officers o ON o.officer_id = cs.officer_id ORDER BY cs.date_created DESC LIMIT 200')->fetchAll(); ?>
  <div class="table-responsive"><table class="table table-striped">
  <caption>Investigation cases (<?= count($rows) ?>)</caption>
  <thead><tr><th scope="col">Case No</th><th scope="col">Crime</th><th scope="col">Officer</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5">No cases yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr>
    <td><?= e($r['case_number']) ?></td><td><?= e($r['crime_type']) ?> (<?= e($r['crime_id']) ?>)</td>
    <td><?= e((string)($r['officer_name'] ?? '-')) ?></td><td><?= e($r['investigation_status']) ?></td>
    <td><a href="cases.php?action=view&amp;id=<?= e($r['case_id']) ?>">View</a><?php if (can('update_crime') || can('update_investigation')): ?> | <a href="cases.php?action=edit&amp;id=<?= e($r['case_id']) ?>">Edit</a><?php endif; ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
<?php elseif ($action === 'view'): ?>
  <?php
  $s = $pdo->prepare('SELECT cs.*, c.crime_type, o.name AS officer_name FROM cases cs JOIN crimes c ON c.crime_id = cs.crime_id LEFT JOIN officers o ON o.officer_id = cs.officer_id WHERE cs.case_id = :id LIMIT 1');
  $s->execute([':id' => $_GET['id'] ?? '']);
  $cs = $s->fetch();
  if (!$cs) { echo '<div class="alert alert-warning" role="alert">Case not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
  $sp = $pdo->prepare('SELECT s.suspect_id, s.full_name FROM case_suspects cs JOIN suspects s ON s.suspect_id = cs.suspect_id WHERE cs.case_id = :id');
  $sp->execute([':id' => $cs['case_id']]);
  $linkedSp = $sp->fetchAll();
  $ev = $pdo->prepare('SELECT evidence_id, evidence_type FROM evidence WHERE case_id = :id');
  $ev->execute([':id' => $cs['case_id']]);
  $linkedEv = $ev->fetchAll();
  ?>
  <div class="card mb-3"><div class="card-body">
    <h2 class="h5"><?= e($cs['case_number']) ?> - <?= e($cs['investigation_status']) ?></h2>
    <dl class="row"><dt class="col-sm-3">Crime</dt><dd class="col-sm-9"><a href="crimes.php?action=view&amp;id=<?= e($cs['crime_id']) ?>"><?= e($cs['crime_type']) ?></a></dd>
    <dt class="col-sm-3">Officer</dt><dd class="col-sm-9"><?= e((string)($cs['officer_name'] ?? '-')) ?></dd>
    <dt class="col-sm-3">Opened</dt><dd class="col-sm-9"><?= e((string)$cs['date_opened']) ?></dd>
    <dt class="col-sm-3">Charges</dt><dd class="col-sm-9"><?= e((string)($cs['charges'] ?? '-')) ?></dd>
    <dt class="col-sm-3">Next hearing</dt><dd class="col-sm-9"><?= e((string)($cs['next_hearing'] ?? '-')) ?></dd>
    <dt class="col-sm-3">Custody</dt><dd class="col-sm-9"><?= e((string)($cs['custody_status'] ?? '-')) ?></dd></dl>
    <div class="d-flex gap-3 align-items-center"><a class="btn btn-sm btn-secondary" href="cases.php">Back</a><div><div id="caseQr"></div><small class="text-muted">Scan to open this case</small></div></div>
  </div></div>
  <h2 class="h5">Suspects (<?= count($linkedSp) ?>)</h2>
  <ul><?php foreach ($linkedSp as $l): ?><li><a href="suspects.php?action=view&amp;id=<?= e($l['suspect_id']) ?>"><?= e($l['full_name']) ?></a></li><?php endforeach; ?></ul>
  <h2 class="h5">Evidence (<?= count($linkedEv) ?>)</h2>
  <ul><?php foreach ($linkedEv as $l): ?><li><a href="evidence.php?action=view&amp;id=<?= e($l['evidence_id']) ?>"><?= e($l['evidence_id']) ?> (<?= e($l['evidence_type']) ?>)</a></li><?php endforeach; ?></ul>
  <?php $tl = $pdo->prepare('SELECT * FROM case_events WHERE case_id = :c ORDER BY event_date DESC, id DESC LIMIT 50'); $tl->execute([':c' => $cs['case_id']]); $events = $tl->fetchAll(); ?>
  <h2 class="h5 mt-3">Timeline (<?= count($events) ?>)</h2>
  <ol><?php foreach ($events as $ev2): ?><li><strong><?= e($ev2['event_date'] ?? '') ?></strong> — <?= e($ev2['title']) ?> <span class="text-muted">(<?= e($ev2['event_type']) ?>)</span><?php if (!empty($ev2['details'])): ?><br><small><?= e($ev2['details']) ?></small><?php endif; ?></li><?php endforeach; ?></ol>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" nonce="<?= e(csp_nonce()) ?>"></script>
  <script nonce="<?= e(csp_nonce()) ?>">try { new QRCode(document.getElementById('caseQr'), {text: window.location.href, width: 96, height: 96}); } catch(e) {}</script>
<?php else: ?>
  <?php
  $edit = null; $editSp = [];
  if ($action === 'edit') {
    $s = $pdo->prepare('SELECT * FROM cases WHERE case_id = :id LIMIT 1');
    $s->execute([':id' => $_GET['id'] ?? '']);
    $edit = $s->fetch();
    if (!$edit) { echo '<div class="alert alert-warning" role="alert">Case not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
    if ($role === 'Investigating Officer' && !case_assigned_to_me($edit['case_id'])) {
      echo '<div class="alert alert-danger" role="alert">Investigating Officers may edit only assigned cases.</div>';
      require __DIR__ . '/includes/footer.php'; exit;
    }
    if ($role === 'Records Officer') {
      echo '<div class="alert alert-danger" role="alert">Records Officers cannot update investigations.</div>';
      require __DIR__ . '/includes/footer.php'; exit;
    }
    $q = $pdo->prepare('SELECT suspect_id FROM case_suspects WHERE case_id = :id');
    $q->execute([':id' => $edit['case_id']]);
    $editSp = array_column($q->fetchAll(), 'suspect_id');
  }
  $crimes = $pdo->query('SELECT crime_id, crime_type FROM crimes ORDER BY date_created DESC LIMIT 200')->fetchAll();
  $officers = $pdo->query('SELECT officer_id, name FROM officers ORDER BY name')->fetchAll();
  $suspects = $pdo->query('SELECT suspect_id, full_name FROM suspects ORDER BY full_name LIMIT 200')->fetchAll();
  ?>
  <div class="card" style="max-width:42rem"><div class="card-body">
  <h2 class="h5"><?= $action === 'add' ? 'Open case' : 'Edit case ' . e($edit['case_number']) ?></h2>
  <form method="post" action="cases.php?action=<?= $action ?><?= $edit ? '&amp;id=' . e($edit['case_id']) : '' ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($edit): ?><input type="hidden" name="case_id" value="<?= e($edit['case_id']) ?>"><?php endif; ?>
    <div class="mb-3"><label class="form-label" for="crime_id">Crime</label><select class="form-select" id="crime_id" name="crime_id" required><?php foreach ($crimes as $c): ?><option value="<?= e($c['crime_id']) ?>" <?= ($edit['crime_id'] ?? '') === $c['crime_id'] ? 'selected' : '' ?>><?= e($c['crime_id']) ?> - <?= e($c['crime_type']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label" for="officer_id">Assigned officer</label><select class="form-select" id="officer_id" name="officer_id"><option value="">-- unassigned --</option><?php foreach ($officers as $o): ?><option value="<?= e($o['officer_id']) ?>" <?= ($edit['officer_id'] ?? '') === $o['officer_id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label" for="case_number">Case number</label><input class="form-control" id="case_number" name="case_number" required maxlength="40" value="<?= e($edit['case_number'] ?? '') ?>"></div>
    <div class="mb-3"><label class="form-label" for="date_opened">Date opened</label><input class="form-control" type="date" id="date_opened" name="date_opened" required value="<?= e((string)($edit['date_opened'] ?? date('Y-m-d'))) ?>"></div>
    <div class="mb-3"><label class="form-label" for="investigation_status">Investigation status</label><select class="form-select" id="investigation_status" name="investigation_status"><?php foreach (case_statuses() as $st): ?><option <?= ($edit['investigation_status'] ?? 'Open') === $st ? 'selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label" for="charges">Charges</label><textarea class="form-control" id="charges" name="charges" rows="2"><?= e($edit['charges'] ?? '') ?></textarea></div>
    <div class="row g-2"><div class="col-md-6 mb-3"><label class="form-label" for="next_hearing">Next hearing</label><input class="form-control" type="date" id="next_hearing" name="next_hearing" value="<?= e((string)($edit['next_hearing'] ?? '')) ?>"></div><div class="col-md-6 mb-3"><label class="form-label" for="custody_status">Custody status</label><input class="form-control" id="custody_status" name="custody_status" maxlength="80" value="<?= e($edit['custody_status'] ?? '') ?>"></div></div>
    <?php if ($edit): ?><fieldset class="mb-3"><legend class="h6">Add timeline note</legend><div class="mb-2"><label class="form-label" for="timeline_title">Note title</label><input class="form-control" id="timeline_title" name="timeline_title" maxlength="150"></div><div class="mb-2"><label class="form-label" for="timeline_details">Details</label><textarea class="form-control" id="timeline_details" name="timeline_details" rows="2"></textarea></div></fieldset><?php endif; ?>
    <fieldset class="mb-3"><legend class="h6">Link suspects (one person, many cases)</legend>
    <?php foreach ($suspects as $sp): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="sp-<?= e($sp['suspect_id']) ?>" name="suspects[]" value="<?= e($sp['suspect_id']) ?>" <?= in_array($sp['suspect_id'], $editSp, true) ? 'checked' : '' ?>><label class="form-check-label" for="sp-<?= e($sp['suspect_id']) ?>"><?= e($sp['full_name']) ?> (<?= e($sp['suspect_id']) ?>)</label></div><?php endforeach; ?>
    </fieldset>
    <button class="btn btn-primary" type="submit"><?= $action === 'add' ? 'Open case' : 'Save' ?></button>
    <a class="btn btn-secondary" href="cases.php">Cancel</a>
  </form>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

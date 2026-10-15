<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();
$pdo = db();
$errors = []; $success = '';
$allowed = ['crimes','cases','suspects','evidence','officers','victims'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? null)) $errors[] = 'Invalid session.';
  else {
    $do = $_POST['do'] ?? '';
    if ($do === 'request') {
      $t = $_POST['table_name'] ?? ''; $rid = trim($_POST['record_id'] ?? ''); $reason = trim($_POST['reason'] ?? '');
      if (!in_array($t, $allowed, true) || $rid === '' || $reason === '') $errors[] = 'Table, record ID and reason required.';
      else { $pdo->prepare('INSERT INTO delete_requests (table_name, record_id, reason, requested_by) VALUES (:t,:r,:re,:u)')->execute([':t' => $t, ':r' => $rid, ':re' => substr($reason,0,255), ':u' => $_SESSION['user_id'] ?? null]); audit('DELETE_REQUEST', $rid, 'success', $t); $success = 'Delete request submitted for approval.'; }
    } elseif ($do === 'review' && can('manage_users')) {
      $id = (int)($_POST['id'] ?? 0); $st = $_POST['status'] ?? '';
      if (!in_array($st, ['Approved','Rejected'], true)) $errors[] = 'Invalid decision.';
      else {
        $s = $pdo->prepare('SELECT * FROM delete_requests WHERE id = :i LIMIT 1'); $s->execute([':i' => $id]); $dr = $s->fetch();
        if (!$dr) $errors[] = 'Request not found.';
        else {
          $pdo->prepare('UPDATE delete_requests SET status = :s, reviewed_by = :u, reviewed_at = NOW() WHERE id = :i')->execute([':s' => $st, ':u' => $_SESSION['user_id'] ?? null, ':i' => $id]);
          if ($st === 'Approved' && in_array($dr['table_name'], $allowed, true)) {
            $pk = ['crimes'=>'crime_id','cases'=>'case_id','suspects'=>'suspect_id','evidence'=>'evidence_id','officers'=>'officer_id','victims'=>'victim_id'][$dr['table_name']];
            try { $pdo->prepare("DELETE FROM `{$dr['table_name']}` WHERE `$pk` = :r LIMIT 1")->execute([':r' => $dr['record_id']]); } catch (Throwable $t2) { $errors[] = 'Approved but delete failed (linked records?).'; }
          }
          audit('DELETE_REVIEW', $dr['record_id'], 'success', $st);
          $success = 'Request ' . $st . '.';
        }
      }
    }
  }
}
$rows = $pdo->query('SELECT * FROM delete_requests ORDER BY created_at DESC LIMIT 200')->fetchAll();
$pageTitle = 'Delete Requests'; $activeNav = 'reports';
require __DIR__ . '/includes/header.php';
?>
<h1>Delete Requests</h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" autofocus><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
<div class="card mb-3"><div class="card-body"><h2 class="h5">Request deletion (approval required)</h2><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="request"><div class="row g-2"><div class="col-md-3"><label class="form-label" for="t">Table</label><select class="form-select" id="t" name="table_name"><?php foreach (['crimes','cases','suspects','evidence','officers','victims'] as $t): ?><option><?= $t ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label" for="rid">Record ID</label><input class="form-control" id="rid" name="record_id" required maxlength="20"></div><div class="col-md-4"><label class="form-label" for="rs">Reason</label><input class="form-control" id="rs" name="reason" required maxlength="255"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-warning" type="submit">Request</button></div></div></form></div></div>
<div class="table-responsive"><table class="table table-striped"><caption>Requests (<?= count($rows) ?>)</caption><thead><tr><th scope="col">ID</th><th scope="col">Table</th><th scope="col">Record</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= e($r['table_name']) ?></td><td><?= e($r['record_id']) ?></td><td><?= e($r['status']) ?></td><td><?php if ($r['status'] === 'Pending' && can('manage_users')): ?><form method="post" class="d-inline" aria-label="Review request <?= (int)$r['id'] ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="review"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-success" name="status" value="Approved" type="submit">Approve</button> <button class="btn btn-sm btn-secondary" name="status" value="Rejected" type="submit">Reject</button></form><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>

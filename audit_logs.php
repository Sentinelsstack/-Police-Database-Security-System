<?php
// audit_logs.php - Supervisor/Admin only, filter by user/date/action
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();
require_perm('view_audit');

$pdo = db();
$userF = trim($_GET['user'] ?? '');
$actionF = trim($_GET['action'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
if ($from !== '' && !valid_date($from)) $from = '';
if ($to !== '' && !valid_date($to)) $to = '';

$sql = 'SELECT a.log_id, a.user_id, u.username, a.action, a.record_affected, a.ip_address, a.status, a.description, a.created_at
        FROM audit_log a LEFT JOIN users u ON u.user_id = a.user_id WHERE 1=1';
$params = [];
if ($userF !== '') { $sql .= ' AND (u.username LIKE :u OR a.user_id LIKE :u2)'; $params[':u'] = '%' . $userF . '%'; $params[':u2'] = '%' . $userF . '%'; }
if ($actionF !== '') { $sql .= ' AND a.action LIKE :a'; $params[':a'] = '%' . $actionF . '%'; }
if ($from !== '') { $sql .= ' AND DATE(a.created_at) >= :f'; $params[':f'] = $from; }
if ($to !== '') { $sql .= ' AND DATE(a.created_at) <= :t'; $params[':t'] = $to; }
$sql .= ' ORDER BY a.log_id DESC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Audit Logs';
$activeNav = 'audit';
require __DIR__ . '/includes/header.php';
?>
<h1>Audit Logs</h1>
<p class="text-muted">Tamper-resistant: no edit or delete interface exists. All logins, record changes and role actions are recorded with user, IP and timestamp.</p>
<?php $chain = audit_verify_chain(500); ?>
<div class="alert <?= $chain['ok'] ? 'alert-success' : 'alert-danger' ?>" role="status">Hash chain: <?= $chain['ok'] ? 'OK' : 'BROKEN' ?> (<?= (int)$chain['checked'] ?> checked<?php if (!$chain['ok']): ?> — bad: <?= e(implode(',', $chain['broken'])) ?><?php endif; ?>). <a href="audit_logs.php?verify=1&amp;user=<?= e($userF) ?>&amp;action=<?= e($actionF) ?>">Re-verify</a></div>
<div class="card mb-4"><div class="card-body">
<form method="get" action="audit_logs.php">
  <div class="row g-3">
    <div class="col-md-3"><label class="form-label" for="user">User / ID</label><input class="form-control" id="user" name="user" value="<?= e($userF) ?>" maxlength="50"></div>
    <div class="col-md-3"><label class="form-label" for="action">Action contains</label><input class="form-control" id="action" name="action" value="<?= e($actionF) ?>" maxlength="80" placeholder="LOGIN, CRIME_CREATE..."></div>
    <div class="col-md-2"><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="col-md-2"><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
  </div>
</form>
</div></div>
<p role="status"><?= count($rows) ?> event(s).</p>
<div class="table-responsive"><table class="table table-striped">
<caption>Audit trail (newest first)</caption>
<thead><tr><th scope="col">Time</th><th scope="col">User</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">IP</th><th scope="col">Status</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="6">No events match.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr><td><?= e((string)$r['created_at']) ?></td><td><?= e((string)($r['username'] ?? $r['user_id'] ?? '-')) ?></td><td><?= e($r['action']) ?></td><td><?= e((string)($r['record_affected'] ?? '-')) ?></td><td><?= e((string)($r['ip_address'] ?? '-')) ?></td><td><?= e($r['status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
// dashboard.php - role-based overview
declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
require_login();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';

$stats = ['crimes' => 0, 'cases' => 0, 'suspects' => 0, 'officers' => 0, 'evidence' => 0, 'users' => 0, 'audit' => 0, 'victims' => 0];
$recent = []; $byType = []; $byStatus = []; $byMonth = []; $upcoming = [];
try {
  $pdo = db();
  foreach (['crimes' => 'crimes', 'cases' => 'cases', 'suspects' => 'suspects', 'officers' => 'officers', 'evidence' => 'evidence', 'users' => 'users', 'audit' => 'audit_log', 'victims' => 'victims'] as $k => $t) {
    try { $stats[$k] = (int)$pdo->query("SELECT COUNT(*) AS c FROM `$t`")->fetch()['c']; } catch (Throwable $t2) { $stats[$k] = 0; }
  }
  if (can('view_audit')) {
    $recent = $pdo->query('SELECT action, record_affected, status, created_at FROM audit_log ORDER BY log_id DESC LIMIT 8')->fetchAll();
  }
  $byType = $pdo->query('SELECT crime_type, COUNT(*) c FROM crimes GROUP BY crime_type ORDER BY c DESC LIMIT 8')->fetchAll();
  $byStatus = $pdo->query('SELECT status, COUNT(*) c FROM crimes GROUP BY status')->fetchAll();
  $byMonth = $pdo->query("SELECT DATE_FORMAT(date_reported,'%Y-%m') m, COUNT(*) c FROM crimes WHERE date_reported >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY m ORDER BY m")->fetchAll();
  try { $upcoming = $pdo->query('SELECT case_number, next_hearing, custody_status FROM cases WHERE next_hearing IS NOT NULL AND next_hearing >= CURDATE() ORDER BY next_hearing LIMIT 5')->fetchAll(); } catch (Throwable $t2) { $upcoming = []; }
} catch (Throwable $t) {
  echo '<div class="alert alert-danger" role="alert">Could not load statistics.</div>';
}
$role = $_SESSION['role_name'] ?? '';
?>
<h1>Dashboard</h1>
<p class="text-muted">Welcome, <?= e($_SESSION['username'] ?? '') ?>. Role: <span class="badge bg-primary"><?= e($role) ?></span></p>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h5">Crimes</h2><p class="display-6"><?= (int)$stats['crimes'] ?></p><a href="crimes.php">Manage crimes</a></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h5">Cases</h2><p class="display-6"><?= (int)$stats['cases'] ?></p><a href="cases.php">Manage cases</a></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h5">Suspects</h2><p class="display-6"><?= (int)$stats['suspects'] ?></p><a href="suspects.php">Manage suspects</a></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h5">Officers</h2><p class="display-6"><?= (int)$stats['officers'] ?></p><a href="officers.php">Manage officers</a></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h5">Evidence</h2><p class="display-6"><?= (int)$stats['evidence'] ?></p><a href="evidence.php">Manage evidence</a></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h5">Reports</h2><p><a href="reports.php">Reports</a></p><p><a href="change_password.php">Change my password</a></p></div></div></div>
</div>

<?php if (can('manage_users')): ?>
<div class="card mb-4"><div class="card-body"><h2 class="h5">Administration</h2><p class="mb-0"><a href="users.php">Manage users &amp; roles</a> (<?= (int)$stats['users'] ?> users)</p></div></div>
<?php elseif (can('view_users')): ?>
<div class="card mb-4"><div class="card-body"><h2 class="h5">Oversight</h2><p class="mb-0"><a href="users.php">View users</a> (read-only for Supervisor)</p></div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h5">Crimes by type</h2><canvas id="chType" height="180" aria-label="Crimes by type chart"></canvas><ul class="small mt-2 mb-0"><?php foreach ($byType as $b): ?><li><?= e($b['crime_type']) ?>: <?= (int)$b['c'] ?></li><?php endforeach; ?></ul></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h5">Crimes by status</h2><canvas id="chStatus" height="180" aria-label="Crimes by status chart"></canvas><ul class="small mt-2 mb-0"><?php foreach ($byStatus as $b): ?><li><?= e($b['status']) ?>: <?= (int)$b['c'] ?></li><?php endforeach; ?></ul></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h2 class="h5">Last 6 months</h2><canvas id="chMonth" height="180" aria-label="Crimes per month chart"></canvas>
  <?php if ($upcoming): ?><h3 class="h6 mt-3">Upcoming hearings</h3><ul class="small mb-0"><?php foreach ($upcoming as $u): ?><li><?= e($u['case_number']) ?> — <?= e((string)$u['next_hearing']) ?> (<?= e((string)($u['custody_status'] ?? '')) ?>)</li><?php endforeach; ?></ul><?php endif; ?>
  </div></div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" nonce="<?= e(csp_nonce()) ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>">
try {
  const typeLabels = <?= json_encode(array_column($byType, 'crime_type')) ?>; const typeData = <?= json_encode(array_map('intval', array_column($byType, 'c'))) ?>;
  if (document.getElementById('chType')) new Chart(document.getElementById('chType'), {type:'bar', data:{labels:typeLabels, datasets:[{data:typeData, backgroundColor:'#185ADB'}]}, options:{plugins:{legend:{display:false}}}});
  const stLabels = <?= json_encode(array_column($byStatus, 'status')) ?>; const stData = <?= json_encode(array_map('intval', array_column($byStatus, 'c'))) ?>;
  if (document.getElementById('chStatus')) new Chart(document.getElementById('chStatus'), {type:'doughnut', data:{labels:stLabels, datasets:[{data:stData, backgroundColor:['#0A1931','#185ADB','#C9A227','#5A6B84']}]}});
  const mLabels = <?= json_encode(array_column($byMonth, 'm')) ?>; const mData = <?= json_encode(array_map('intval', array_column($byMonth, 'c'))) ?>;
  if (document.getElementById('chMonth')) new Chart(document.getElementById('chMonth'), {type:'line', data:{labels:mLabels, datasets:[{data:mData, borderColor:'#0A1931', backgroundColor:'#E8EEFB', fill:true}]}, options:{plugins:{legend:{display:false}}}});
} catch(e) {}
</script>

<?php if ($recent): ?>
<h2 class="h5">Recent audit activity</h2>
<div class="table-responsive"><table class="table table-striped">
<caption>Latest 8 audit events</caption>
<thead><tr><th scope="col">Action</th><th scope="col">Record</th><th scope="col">Status</th><th scope="col">Time</th></tr></thead>
<tbody><?php foreach ($recent as $r): ?><tr><td><?= e($r['action']) ?></td><td><?= e((string)($r['record_affected'] ?? '-')) ?></td><td><?= e($r['status']) ?></td><td><?= e((string)$r['created_at']) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<p><a href="audit_logs.php">View all audit logs</a></p>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>

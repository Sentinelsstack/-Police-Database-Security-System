<?php
// backup.php - Admin-only backup instructions + DB health (no web exec of mysqldump)
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();
require_perm('manage_users');

$pdo = db();
$counts = [];
foreach (['users','officers','crimes','suspects','victims','cases','evidence','audit_log','login_history'] as $t) {
  try { $counts[$t] = (int)$pdo->query("SELECT COUNT(*) c FROM `$t`")->fetch()['c']; } catch (Throwable $t2) { $counts[$t] = 0; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
  $fn = substr(trim($_POST['filename'] ?? ''), 0, 255);
  $notes = substr(trim($_POST['notes'] ?? ''), 0, 255);
  $ver = isset($_POST['verified']) ? 1 : 0;
  $rc = json_encode($counts);
  $pdo->prepare('INSERT INTO backup_logs (backup_type, filename, row_counts, verified, notes, created_by) VALUES (\'manual\',:f,:r,:v,:n,:u)')->execute([':f' => $fn ?: null, ':r' => $rc, ':v' => $ver, ':n' => $notes ?: null, ':u' => $_SESSION['user_id'] ?? null]);
  audit('BACKUP_LOG', $fn ?: 'manual', 'success', null);
}
$logs = $pdo->query('SELECT * FROM backup_logs ORDER BY id DESC LIMIT 20')->fetchAll();

$pageTitle = 'Backup';
$activeNav = 'reports';
require __DIR__ . '/includes/header.php';
?>
<h1>Backup &amp; Recovery</h1>
<div class="alert alert-info" role="status">Full backup = database dump + <code>uploads/</code> folder copy. Test restores regularly. Prefer the scheduled CLI runner over manual dumps.</div>
<div class="row g-3">
<div class="col-md-6"><div class="card"><div class="card-body">
<h2 class="h5">1. Automated (recommended)</h2>
<pre><code>C:\xampp\php\php.exe docs\backup_run.php</code></pre>
<p class="small text-muted">Uses the least-privilege app credentials from <code>config/.env</code> (never root). Schedule daily via Windows Task Scheduler; dumps land outside the webroot.</p>
<h2 class="h5 mt-3">2. Manual dump (if needed)</h2>
<pre><code>C:\xampp\mysql\bin\mysqldump.exe -u police_app -p --port=3307 -h 127.0.0.1 --single-transaction --routines police_db_security &gt; backup-YYYYMMDD.sql</code></pre>
<h2 class="h5 mt-3">3. Restore (test)</h2>
<pre><code>C:\xampp\mysql\bin\mysql.exe -u police_app -p --port=3307 -h 127.0.0.1 police_db_security &lt; backup-YYYYMMDD.sql</code></pre>
</div></div></div>
<div class="col-md-6"><div class="card"><div class="card-body">
<h2 class="h5">Current row counts</h2>
<div class="table-responsive"><table class="table table-sm"><caption>Live table sizes</caption><tbody>
<?php foreach ($counts as $t => $c): ?><tr><th scope="row"><?= e($t) ?></th><td><?= (int)$c ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="text-muted small">Schedule: weekly full dump + daily audit_log export during operation. Keep 3 copies (local + external + offsite). Verify restores monthly.</p>
<p><a href="delete_requests.php">Delete approvals</a></p>
<h2 class="h5 mt-3">Log backup / restore test</h2>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div class="mb-2"><label class="form-label" for="bf">Filename</label><input class="form-control" id="bf" name="filename" maxlength="255" placeholder="backup-20260911.sql"></div><div class="mb-2"><label class="form-label" for="bn">Notes</label><input class="form-control" id="bn" name="notes" maxlength="255" placeholder="Full dump + uploads copy"></div><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="bv" name="verified"><label class="form-check-label" for="bv">Restore tested OK</label></div><button class="btn btn-primary btn-sm" type="submit">Log backup</button></form>
<h2 class="h5 mt-3">Recent logs (<?= count($logs) ?>)</h2>
<ul class="small"><?php foreach ($logs as $l): ?><li><?= e((string)$l['created_at']) ?> — <?= e((string)($l['filename'] ?? 'manual')) ?> <?= $l['verified'] ? '[verified]' : '[not verified]' ?> <?= e((string)($l['notes'] ?? '')) ?></li><?php endforeach; ?></ul>
</div></div></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

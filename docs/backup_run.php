<?php
// docs/backup_run.php - scheduled CLI backup (mysqldump + uploads).
// Windows Task Scheduler example (daily 02:00):
//   schtasks /Create /TN "PoliceDB Backup" /SC DAILY /ST 02:00 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\finalyearproject\Joshua\police_db\docs\backup_run.php"
// Optional args: --uploads-dir=D:\backup\police-uploads
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}
require __DIR__ . '/../includes/security.php';

$outDir = dirname(__DIR__, 2) . '/backups'; // sibling of app root - outside webroot
$uploadsSrc = dirname(__DIR__) . '/uploads';
$uploadsDst = null;
foreach ($argv as $a) {
  if (str_starts_with($a, '--uploads-dir=')) $uploadsDst = substr($a, 14);
}
if ($uploadsDst === null) $uploadsDst = dirname(__DIR__, 2) . '/backups/uploads';

if (!is_dir($outDir)) { mkdir($outDir, 0750, true); }
if (!is_dir($uploadsDst)) { mkdir($uploadsDst, 0750, true); }

$cfg = db_config();
$date = date('Ymd-His');
$dumpFile = $outDir . '/police_db_security-' . $date . '.sql';

// mysqldump path: XAMPP default, override with MYSQLDUMP_BIN env
$dumpBin = env('MYSQLDUMP_BIN', 'C:\xampp\mysql\bin\mysqldump.exe');
if (!is_file($dumpBin)) {
  fwrite(STDERR, "mysqldump not found: {$dumpBin} (set MYSQLDUMP_BIN in env)\n");
  exit(1);
}

$cmd = sprintf(
  '"%s" --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers --result-file="%s" %s',
  $dumpBin,
  $cfg['host'],
  $cfg['port'],
  $cfg['user'],
  $cfg['pass'],
  $dumpFile,
  $cfg['name']
);
// Avoid password on process list where possible: use MYSQL_PWD
putenv('MYSQL_PWD=' . $cfg['pass']);
$cmdSafe = sprintf(
  '"%s" --host=%s --port=%s --user=%s --single-transaction --routines --triggers --result-file="%s" %s',
  $dumpBin,
  $cfg['host'],
  $cfg['port'],
  $cfg['user'],
  $dumpFile,
  $cfg['name']
);
exec($cmdSafe . ' 2>&1', $out, $code);
if ($code !== 0 || !is_file($dumpFile) || filesize($dumpFile) === 0) {
  fwrite(STDERR, "mysqldump failed (exit {$code}): " . implode("\n", $out) . "\n");
  app_log('error', 'backup_run dump failed exit=' . $code);
  exit(1);
}

// Copy uploads (photos)
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsSrc, FilesystemIterator::SKIP_DOTS));
$copied = 0;
foreach ($rii as $file) {
  if ($file->isDir()) continue;
  $rel = substr($file->getPathname(), strlen($uploadsSrc) + 1);
  if ($rel === '.htaccess' || $rel === '.gitkeep') continue;
  $dest = rtrim($uploadsDst, '/\\') . DIRECTORY_SEPARATOR . $rel;
  $destDir = dirname($dest);
  if (!is_dir($destDir)) mkdir($destDir, 0750, true);
  if (copy($file->getPathname(), $dest)) $copied++;
}

// Log to DB (best effort)
try {
  $pdo = db();
  $counts = [];
  foreach (['users','officers','crimes','suspects','victims','cases','evidence','audit_log','login_history'] as $t) {
    try { $counts[$t] = (int)$pdo->query("SELECT COUNT(*) c FROM `$t`")->fetch()['c']; } catch (Throwable $e2) { $counts[$t] = 0; }
  }
  $pdo->prepare("INSERT INTO backup_logs (backup_type, filename, row_counts, verified, notes) VALUES ('scheduled',:f,:r,0,:n)")
      ->execute([':f' => basename($dumpFile), ':r' => json_encode($counts), ':n' => "uploads copied: {$copied}"]);
  audit('BACKUP_SCHEDULED', basename($dumpFile), 'success', "uploads={$copied}");
} catch (Throwable $t) {
  app_log('warn', 'backup_run DB log failed: ' . $t->getMessage());
}

echo "Backup OK: {$dumpFile} (uploads copied: {$copied})\n";

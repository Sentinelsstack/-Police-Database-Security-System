<?php
// reports.php - filters + print-friendly + CSV export (allowlisted, no SSRF)
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();
require_perm('generate_reports');

$pdo = db();
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$format = $_GET['format'] ?? 'html';
if (!in_array($format, ['html', 'csv', 'pdf'], true)) $format = 'html';
if ($type !== '' && !in_array($type, crime_types(), true)) $type = '';
if ($status !== '' && !in_array($status, crime_statuses(), true)) $status = '';
if ($from !== '' && !valid_date($from)) $from = '';
if ($to !== '' && !valid_date($to)) $to = '';

$sql = 'SELECT c.crime_id, c.crime_type, c.location, c.date_reported, c.status, o.name AS officer,
        (SELECT COUNT(*) FROM cases WHERE crime_id = c.crime_id) AS case_count
        FROM crimes c LEFT JOIN officers o ON o.officer_id = c.reporting_officer_id WHERE 1=1';
$params = [];
if ($type !== '') { $sql .= ' AND c.crime_type = :t'; $params[':t'] = $type; }
if ($status !== '') { $sql .= ' AND c.status = :s'; $params[':s'] = $status; }
if ($from !== '') { $sql .= ' AND c.date_reported >= :f'; $params[':f'] = $from; }
if ($to !== '') { $sql .= ' AND c.date_reported <= :t2'; $params[':t2'] = $to; }
$sql .= ' ORDER BY c.date_reported DESC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($format === 'csv') {
  audit('REPORT_EXPORT', 'crimes', 'success', count($rows) . ' rows');
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="crime-report-' . date('Ymd-His') . '.csv"');
  header('X-Content-Type-Options: nosniff');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Crime ID', 'Type', 'Location', 'Date Reported', 'Status', 'Officer', 'Cases']);
  foreach ($rows as $r) {
    fputcsv($out, [$r['crime_id'], $r['crime_type'], $r['location'], $r['date_reported'], $r['status'], $r['officer'] ?? '-', $r['case_count']]);
  }
  fclose($out);
  exit;
}

if ($format === 'pdf') {
  audit('REPORT_EXPORT_PDF', 'crimes', 'success', count($rows) . ' rows');
  $lines = ['POLICE DATABASE - CRIME REPORT', date('Y-m-d H:i') . ' | ' . count($rows) . ' records', ''];
  foreach ($rows as $r) { $lines[] = substr(($r['crime_id'] ?? '') . ' | ' . ($r['crime_type'] ?? '') . ' | ' . ($r['location'] ?? '') . ' | ' . ($r['date_reported'] ?? '') . ' | ' . ($r['status'] ?? ''), 0, 110); }
  $content = '';
  $offsets = [];
  $objs = [];
  $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
  $objs[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
  $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
  $text = 'BT /F1 10 Tf 40 780 Td 13 TL ';
  foreach ($lines as $ln) { $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ln); $text .= '(' . $safe . ') Tj T* '; }
  $text .= 'ET';
  $objs[5] = '<< /Length ' . strlen($text) . ' >> stream ' . $text . ' endstream';
  $objs[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>';
  $pdf = "%PDF-1.4\n";
  foreach ([1,2,3,4,5] as $n) { $offsets[$n] = strlen($pdf); $pdf .= $n . " 0 obj\n" . $objs[$n] . "\nendobj\n"; }
  $xref = strlen($pdf);
  $pdf .= "xref\n0 6\n0000000000 65535 f \n";
  for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
  $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="crime-report-' . date('Ymd-His') . '.pdf"');
  header('X-Content-Type-Options: nosniff');
  echo $pdf;
  exit;
}

$pageTitle = 'Reports';
$activeNav = 'reports';
require __DIR__ . '/includes/header.php';
?>
<h1>Crime Reports</h1>
<div class="card mb-4 no-print"><div class="card-body">
<form method="get" action="reports.php">
  <div class="row g-3">
    <div class="col-md-3"><label class="form-label" for="type">Type</label><select class="form-select" id="type" name="type"><option value="">-- all --</option><?php foreach (crime_types() as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">-- all --</option><?php foreach (crime_statuses() as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="col-md-2"><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="col-md-2 d-flex align-items-end gap-2">
      <button class="btn btn-primary" type="submit" name="format" value="html">Filter</button>
      <button class="btn btn-outline-secondary" type="submit" name="format" value="csv">CSV</button>
      <button class="btn btn-outline-dark" type="submit" name="format" value="pdf">PDF</button>
    </div>
  </div>
</form>
</div></div>

<div class="d-flex justify-content-between align-items-center mb-2">
  <p class="mb-0" role="status"><?= count($rows) ?> record(s) found.</p>
  <button class="btn btn-sm btn-outline-primary no-print" onclick="window.print()">Print report</button>
</div>
<div class="table-responsive"><table class="table table-striped">
<caption>Filtered crime report</caption>
<thead><tr><th scope="col">ID</th><th scope="col">Type</th><th scope="col">Location</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col">Officer</th><th scope="col">Cases</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="7">No records match the filters.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['crime_id']) ?></td><td><?= e($r['crime_type']) ?></td><td><?= e($r['location']) ?></td><td><?= e((string)$r['date_reported']) ?></td><td><?= e($r['status']) ?></td><td><?= e((string)($r['officer'] ?? '-')) ?></td><td><?= (int)$r['case_count'] ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="text-muted small no-print">CSV export is audit-logged. See <a href="backup.php">backup instructions</a>.</p>
<?php require __DIR__ . '/includes/footer.php'; ?>

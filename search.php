<?php
// search.php - global structured search (crimeID / type / status / suspect name / case no)
declare(strict_types=1);
require_once __DIR__ . '/includes/crud.php';
require_login();
require_perm('view_crime');

$q = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';
$results = ['crimes' => [], 'suspects' => [], 'cases' => []];
$searched = ($q !== '' || $type !== '' || $status !== '');

if ($searched) {
  try {
    $pdo = db();
    // Crimes: by ID, type, status, location
    $sql = 'SELECT crime_id, crime_type, location, date_reported, status FROM crimes WHERE 1=1';
    $params = [];
    if ($q !== '') { $sql .= ' AND (crime_id LIKE :q OR location LIKE :q2)'; $params[':q'] = '%' . $q . '%'; $params[':q2'] = '%' . $q . '%'; }
    if ($type !== '' && in_array($type, crime_types(), true)) { $sql .= ' AND crime_type = :t'; $params[':t'] = $type; }
    if ($status !== '' && in_array($status, crime_statuses(), true)) { $sql .= ' AND status = :s'; $params[':s'] = $status; }
    $sql .= ' ORDER BY date_created DESC LIMIT 50';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results['crimes'] = $stmt->fetchAll();
    // Suspects: by name or ID
    if ($q !== '') {
      $stmt = $pdo->prepare('SELECT suspect_id, full_name, gender FROM suspects WHERE full_name LIKE :q OR suspect_id LIKE :q2 ORDER BY date_created DESC LIMIT 50');
      $stmt->execute([':q' => '%' . $q . '%', ':q2' => '%' . $q . '%']);
      $results['suspects'] = $stmt->fetchAll();
      // Cases: by case number
      $stmt = $pdo->prepare('SELECT cs.case_id, cs.case_number, cs.investigation_status, c.crime_type FROM cases cs JOIN crimes c ON c.crime_id = cs.crime_id WHERE cs.case_number LIKE :q ORDER BY cs.date_created DESC LIMIT 50');
      $stmt->execute([':q' => '%' . $q . '%']);
      $results['cases'] = $stmt->fetchAll();
    }
  } catch (Throwable $t) {
    $searchError = 'Search failed. Please try again.';
  }
}

$pdo2 = db();
if (($_POST['do'] ?? '') === 'save_search' && csrf_check($_POST['csrf'] ?? null)) {
  $nm = substr(trim($_POST['sname'] ?? ''), 0, 80);
  if ($nm !== '' && $searched) {
    $qp = http_build_query(['q' => $q, 'type' => $type, 'status' => $status]);
    $pdo2->prepare('INSERT INTO saved_searches (user_id, name, query_params) VALUES (:u,:n,:q)')->execute([':u' => $_SESSION['user_id'] ?? null, ':n' => $nm, ':q' => substr($qp, 0, 255)]);
    audit('SEARCH_SAVE', $nm, 'success', null);
  }
}
$saved = $pdo2->prepare('SELECT * FROM saved_searches WHERE user_id = :u ORDER BY id DESC LIMIT 10');
$saved->execute([':u' => $_SESSION['user_id'] ?? null]);
$savedRows = $saved->fetchAll();
$pageTitle = 'Search';
$activeNav = 'search';
require __DIR__ . '/includes/header.php';
?>
<h1>Search</h1>
<?php if (!empty($searchError)): ?><div class="alert alert-danger" role="alert"><?= e($searchError) ?></div><?php endif; ?>
<div class="card mb-4"><div class="card-body">
<form method="get" action="search.php" role="search" aria-label="Structured record search">
  <div class="row g-3">
    <div class="col-md-5"><label class="form-label" for="q">Keyword (crime ID, location, suspect name, case number)</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="100"></div>
    <div class="col-md-3"><label class="form-label" for="type">Crime type</label><select class="form-select" id="type" name="type"><option value="">-- all --</option><?php foreach (crime_types() as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">-- all --</option><?php foreach (crime_statuses() as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Search</button></div>
  </div>
</form>
</div></div>

<?php if ($savedRows): ?><div class="mb-3"><h2 class="h6">Saved searches</h2><?php foreach ($savedRows as $sv): ?><a class="btn btn-sm btn-outline-secondary me-1 mb-1" href="search.php?<?= e($sv['query_params']) ?>"><?= e($sv['name']) ?></a><?php endforeach; ?></div><?php endif; ?>
<?php if ($searched): ?>
<form method="post" class="mb-3"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="save_search"><div class="input-group" style="max-width:24rem"><input class="form-control" name="sname" maxlength="80" placeholder="Save this search as…" required><button class="btn btn-outline-primary" type="submit">Save</button></div></form>
<h2 class="h5">Crimes (<?= count($results['crimes']) ?>)</h2>
<div class="table-responsive"><table class="table table-striped"><caption>Matching crimes</caption>
<thead><tr><th scope="col">ID</th><th scope="col">Type</th><th scope="col">Location</th><th scope="col">Status</th><th scope="col">Open</th></tr></thead>
<tbody><?php if (!$results['crimes']): ?><tr><td colspan="5">No matching crimes.</td></tr><?php endif; ?>
<?php foreach ($results['crimes'] as $r): ?><tr><td><?= e($r['crime_id']) ?></td><td><?= e($r['crime_type']) ?></td><td><?= e($r['location']) ?></td><td><?= e($r['status']) ?></td><td><a href="crimes.php?action=view&amp;id=<?= e($r['crime_id']) ?>">View</a></td></tr><?php endforeach; ?></tbody></table></div>

<h2 class="h5">Suspects (<?= count($results['suspects']) ?>)</h2>
<div class="table-responsive"><table class="table table-striped"><caption>Matching suspects</caption>
<thead><tr><th scope="col">ID</th><th scope="col">Name</th><th scope="col">Open</th></tr></thead>
<tbody><?php if (!$results['suspects']): ?><tr><td colspan="3">No matching suspects.</td></tr><?php endif; ?>
<?php foreach ($results['suspects'] as $r): ?><tr><td><?= e($r['suspect_id']) ?></td><td><?= e($r['full_name']) ?></td><td><a href="suspects.php?action=view&amp;id=<?= e($r['suspect_id']) ?>">View</a></td></tr><?php endforeach; ?></tbody></table></div>

<h2 class="h5">Cases (<?= count($results['cases']) ?>)</h2>
<div class="table-responsive"><table class="table table-striped"><caption>Matching cases</caption>
<thead><tr><th scope="col">Case No</th><th scope="col">Crime</th><th scope="col">Status</th><th scope="col">Open</th></tr></thead>
<tbody><?php if (!$results['cases']): ?><tr><td colspan="4">No matching cases.</td></tr><?php endif; ?>
<?php foreach ($results['cases'] as $r): ?><tr><td><?= e($r['case_number']) ?></td><td><?= e($r['crime_type']) ?></td><td><?= e($r['investigation_status']) ?></td><td><a href="cases.php?action=view&amp;id=<?= e($r['case_id']) ?>">View</a></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

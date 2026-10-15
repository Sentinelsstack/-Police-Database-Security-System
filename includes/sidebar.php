<?php
// includes/sidebar.php - requires $activeNav
$nav = [
  'dashboard' => ['Dashboard', 'dashboard.php'],
  'search' => ['Search', 'search.php'],
  'crimes' => ['Crimes', 'crimes.php'],
  'suspects' => ['Suspects', 'suspects.php'],
  'victims' => ['Victims', 'victims.php'],
  'cases' => ['Cases', 'cases.php'],
  'officers' => ['Officers', 'officers.php'],
  'evidence' => ['Evidence', 'evidence.php'],
  'reports' => ['Reports', 'reports.php'],
  'audit' => ['Audit Logs', 'audit_logs.php'],
  'users' => ['Users', 'users.php'],
  'delete_requests' => ['Delete Approvals', 'delete_requests.php'],
];
?>
<nav class="sidebar" aria-label="Main">
  <ul class="nav flex-column">
    <?php foreach ($nav as $key => [$label, $href]): ?>
      <li class="nav-item">
        <a class="nav-link <?= $activeNav === $key ? 'active" aria-current="page' : '' ?>" href="<?= $href ?>"><?= htmlspecialchars($label) ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="sidebar-note">All access is logged. Use Search for structured retrieval.</p>
</nav>

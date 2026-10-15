<?php
// 404 - Not Found (accessible)
http_response_code(404);
$pageTitle = 'Not Found';
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>
<h1>404 - Page not found</h1>
<div class="alert alert-warning" role="alert">The page you requested does not exist.</div>
<p><a href="dashboard.php">Back to dashboard</a> | <a href="search.php">Search records</a></p>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
// 403 - Forbidden (accessible)
http_response_code(403);
$pageTitle = 'Forbidden';
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>
<h1>403 - Forbidden</h1>
<div class="alert alert-danger" role="alert">You do not have permission to access this page. This attempt has been noted.</div>
<p><a href="dashboard.php">Back to dashboard</a> | <a href="login.php">Login</a></p>
<?php require __DIR__ . '/includes/footer.php'; ?>

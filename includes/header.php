<?php
// includes/header.php - accessible professional layout + security headers + CSP
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
force_https_redirect();
ensure_session();
send_security_headers();

$pageTitle = $pageTitle ?? 'Police Database Security System';
$currentUser = $_SESSION['username'] ?? null;
$currentRole = $_SESSION['role_name'] ?? null;
$activeNav = $activeNav ?? '';
$cspNonce = csp_nonce();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - Police DB</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link href="assets/css/custom.css?v=3" rel="stylesheet">
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>
<header class="topbar" role="banner">
  <div class="container-fluid d-flex align-items-center justify-content-between py-2">
    <div class="d-flex align-items-center gap-2">
      <span class="badge-shield" aria-hidden="true">NPF</span>
      <div>
        <strong class="brand-title">Police Database Security System</strong><br>
        <small class="text-muted">Confidential - Authorised use only</small>
      </div>
    </div>
    <div class="d-flex align-items-center gap-3">
      <?php if ($currentUser): ?>
        <span>Logged in as <strong><?= htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8') ?></strong>
        <?php if ($currentRole): ?><span class="badge bg-primary"><?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></span>
        <a class="btn btn-sm btn-outline-light" href="logout.php">Logout</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<div class="layout">
<?php require __DIR__ . '/sidebar.php'; ?>
<main id="main" class="content" tabindex="-1">
<div class="container-fluid py-4">

<?php
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function page_header(string $title = 'Payslip'): void {
    if (class_exists('Auth')) { Auth::start(); }
    $loggedIn = isset($_SESSION['user']);
    $u = $_SESSION['user'] ?? null;
    $isAdmin = class_exists('Auth') ? Auth::isAdmin() : false;

    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $hostNoPort = explode(':', $host)[0];
    $authApps = 'http://' . $hostNoPort . ':90/apps.php';
?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#fff;}
    .navbar-brand{font-weight:700;}
    .table thead th{font-size:.75rem; text-transform:uppercase; letter-spacing:.06em;}
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4 border-bottom">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">Payslip</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($loggedIn): ?>
          <li class="nav-item"><a class="nav-link" href="upload.php">Feltöltés</a></li>
          <li class="nav-item"><a class="nav-link" href="employees.php">Dolgozók</a></li>
          <li class="nav-item"><a class="nav-link" href="employees_import.php">CSV import</a></li>
          <li class="nav-item"><a class="nav-link" href="log.php">Log/Státusz</a></li>
          <li class="nav-item"><a class="nav-link" href="employee_pdfs.php">Dolgozó PDF-ek</a></li>
          <?php if ($isAdmin): ?>
            <li class="nav-item"><a class="nav-link" href="divisions.php">Divíziók</a></li>
            <li class="nav-item"><a class="nav-link text-danger" href="reset.php">Reset</a></li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>

      <div class="d-flex align-items-center gap-2">
        <?php if ($loggedIn): ?>
          <span class="navbar-text me-2">Bejelentkezve: <?= h($u['username'] ?? '') ?></span>
          <a class="btn btn-sm btn-outline-primary" href="<?= h($authApps) ?>">Rendszerek</a>
          <a class="btn btn-sm btn-outline-secondary" href="logout.php">Kilépés</a>
        <?php else: ?>
          <a class="btn btn-sm btn-outline-primary" href="login.php">Belépés</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<div class="container">
<?php
}

function page_footer(): void { ?>
</div>
<script src="assets/bootstrap/bootstrap.bundle.min.js"></script>
<footer class="border-top mt-5 py-3">
  <div class="container small text-muted">
    Perfect-Phone 2026 &ndash; Payslip
  </div>
</footer>
</body>
</html>
<?php } 

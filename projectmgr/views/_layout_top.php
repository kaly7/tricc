<?php
use App\Auth;

Auth::start();

$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$hostNoPort = explode(':', (string)$host)[0];
$authApps   = 'http://' . $hostNoPort . ':90/apps.php';
$authLogout = 'http://' . $hostNoPort . ':90/logout.php';

$moduleKey = Auth::currentModuleKey();
$moduleQS  = ($moduleKey === 'vehicles') ? '?module=vehicles' : '?module=projectmgr';
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= ($moduleKey === 'vehicles') ? 'Járművek' : 'ProjectMgr' ?></title>
  <link href="/assets/bootstrap/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4 border-bottom">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/index.php<?= htmlspecialchars($moduleQS) ?>">
      <?= ($moduleKey === 'vehicles') ? 'Járművek' : 'ProjectMgr' ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
<?php if (Auth::check()): ?>

  <?php if ($moduleKey === 'vehicles'): ?>
    <li class="nav-item"><a class="nav-link" href="/vehicles.php?module=vehicles">Járművek</a></li>
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" href="#" id="navVehicleSettings" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        Jármű beállítások
      </a>
      <ul class="dropdown-menu" aria-labelledby="navVehicleSettings">
        <li><a class="dropdown-item" href="/vehicle_types.php?module=vehicles">Jármű típusok</a></li>
        <li><a class="dropdown-item" href="/vehicle_body_types.php?module=vehicles">Karosszéria típusok</a></li>
        <li><a class="dropdown-item" href="/vehicle_colors.php?module=vehicles">Színek</a></li>
        <li><a class="dropdown-item" href="/vehicle_euro_classes.php?module=vehicles">EURO osztályok</a></li>
        <li><a class="dropdown-item" href="/vehicle_vignette_types.php?module=vehicles">Matrica típusok</a></li>
      </ul>
    </li>
  <?php else: ?>
    <li class="nav-item"><a class="nav-link" href="/pm_projects.php">Projektek</a></li>
    <li class="nav-item"><a class="nav-link" href="/vehicles.php">Járművek</a></li>

<li class="nav-item dropdown">
  <a class="nav-link dropdown-toggle" href="#" id="navVehicleSettings" role="button" data-bs-toggle="dropdown" aria-expanded="false">
    Jármű beállítások
  </a>
  <ul class="dropdown-menu" aria-labelledby="navVehicleSettings">
    <li><a class="dropdown-item" href="/vehicle_types.php">Jármű típusok</a></li>
    <li><a class="dropdown-item" href="/vehicle_body_types.php">Karosszéria típusok</a></li>
    <li><a class="dropdown-item" href="/vehicle_colors.php">Színek</a></li>
    <li><a class="dropdown-item" href="/vehicle_euro_classes.php">EURO osztályok</a></li>
    <li><a class="dropdown-item" href="/vehicle_vignette_types.php">Matrica típusok</a></li>
  </ul>
</li>

    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" href="#" id="navTimesheet" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        Timesheet
      </a>
      <ul class="dropdown-menu" aria-labelledby="navTimesheet">
        <li><a class="dropdown-item" href="/ts_calendar.php">Naptár</a></li>
        <li><a class="dropdown-item" href="/ts_events.php">Munkavégzés események</a></li>
        <li><a class="dropdown-item" href="/ts_workers.php">Kollégák</a></li>
        <li><a class="dropdown-item" href="/ts_work_types.php">Munkavégzés típusok</a></li>
        <li><a class="dropdown-item" href="/ts_qualifications.php">Szakképesítések</a></li>
        <li><a class="dropdown-item" href="/ts_permits.php">Engedélyek</a></li>
        <li><a class="dropdown-item" href="/ts_work_tools.php">Munkaeszközök</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="/ts_worker_status_types.php">Dolgozói státusz típusok</a></li>
        <li><a class="dropdown-item" href="/ts_worker_days.php">Dolgozói napi státuszok</a></li>
      </ul>
    </li>
  <?php endif; ?>

<?php endif; ?>
      </ul>

      <div class="d-flex align-items-center gap-2">
        <?php if (Auth::check()): $u=Auth::user(); ?>
          <span class="navbar-text me-2">Bejelentkezve: <?=htmlspecialchars((string)($u['name'] ?? ''), ENT_QUOTES, 'UTF-8')?></span>
          <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($authApps, ENT_QUOTES, 'UTF-8') ?>">Rendszerek</a>
          <a class="btn btn-sm btn-outline-secondary" href="/logout.php">Vissza</a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($authLogout, ENT_QUOTES, 'UTF-8') ?>">Kilépés</a>
        <?php else: ?>
          <a class="btn btn-sm btn-outline-primary" href="/login.php">Belépés</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<div class="container">

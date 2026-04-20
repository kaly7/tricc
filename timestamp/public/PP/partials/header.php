<?php
require __DIR__ . '/../includes/init.php';
$user = current_user($pdo);
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>PP Kezelő</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>.row-colored{transition:background-color .2s} textarea{min-height:100px}</style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-body-tertiary mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= htmlspecialchars(base_url()); ?>index.php">PP Kezelő</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($user): ?>
          <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars(base_url()); ?>jobs_list.php">Tételek</a></li>
          <?php if (is_admin($pdo)): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Admin</a>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="<?= htmlspecialchars(base_url()); ?>admin/users.php">Felhasználók</a></li>
                <li><a class="dropdown-item" href="<?= htmlspecialchars(base_url()); ?>admin/cities.php">Települések</a></li>
                <li><a class="dropdown-item" href="<?= htmlspecialchars(base_url()); ?>admin/statuses.php">PP státuszok</a></li>
                <li><a class="dropdown-item" href="<?= htmlspecialchars(base_url()); ?>admin/settings.php">Színezés / beállítások</a></li>
              </ul>
            </li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav ms-auto">
        <?php if ($user): ?>
          <li class="nav-item"><span class="navbar-text me-3"><?= htmlspecialchars($user['name']) ?></span></li>
          <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars(base_url()); ?>logout.php">Kilépés</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars(base_url()); ?>login.php">Belépés</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<div class="container">

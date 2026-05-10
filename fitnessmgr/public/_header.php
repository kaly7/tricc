<?php
require_once __DIR__ . '/../app/auth.php';
require_login();
$u     = current_user();
$title = $title ?? (string)config()['app_name'];
$page  = $page  ?? '';
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> – Fitness napló</title>
  <link rel="stylesheet" href="<?= e(asset_url('assets/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-success mb-4">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= e(base_url('dashboard.php')) ?>">🥗 Fitness napló</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link <?= $page==='dashboard' ? 'active fw-semibold' : '' ?>" href="<?= e(base_url('dashboard.php')) ?>">
            📊 Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page==='food' ? 'active fw-semibold' : '' ?>" href="<?= e(base_url('food_diary.php')) ?>">
            🍽️ Étkezési napló
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page==='exercise' ? 'active fw-semibold' : '' ?>" href="<?= e(base_url('exercise_diary.php')) ?>">
            🏃 Edzés
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page==='weight' ? 'active fw-semibold' : '' ?>" href="<?= e(base_url('weight_log.php')) ?>">
            ⚖️ Súlynapló
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page==='stats' ? 'active fw-semibold' : '' ?>" href="<?= e(base_url('stats.php')) ?>">
            📈 Statisztikák
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page==='goals' ? 'active fw-semibold' : '' ?>" href="<?= e(base_url('goals.php')) ?>">
            🎯 Profil
          </a>
        </li>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <span class="text-white-50 small"><?= e($u['name'] ?? $u['username'] ?? '') ?></span>
        <a class="btn btn-outline-light btn-sm" href="<?= e(base_url('logout.php')) ?>">Kilép</a>
      </div>
    </div>
  </div>
</nav>

<div class="container">
  <?php if ($m = flash_get('ok')): ?><div class="alert alert-success alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button><?= e($m) ?></div><?php endif; ?>
  <?php if ($m = flash_get('err')): ?><div class="alert alert-danger alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button><?= e($m) ?></div><?php endif; ?>

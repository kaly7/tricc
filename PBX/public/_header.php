<?php
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/auth.php';
$u = current_user();
$title = $title ?? (string)config()['app_name'];
$page = $page ?? '';
$isAdmin = ($u['role'] ?? '') === 'admin';
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>

  <link rel="stylesheet" href="<?= e(asset_url('assets/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4 border-bottom">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= e(base_url('index.php')) ?>"><?= e(config()['app_name']) ?></a>

    <!-- span class="badge bg-secondary ms-2 d-none d-md-inline"><?= e($page) ?></span -->

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <!-- li class="nav-item"><a class="nav-link" href="<?= e(base_url('index.php')) ?>">Kezdőlap</a></li -->
        <li class="nav-item"><a class="nav-link" href="<?= e(base_url('manufacturers.php')) ?>">Gyártók</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(base_url('catalog_items.php')) ?>">Eszköz-típusok</a></li>
        <!-- li class="nav-item"><a class="nav-link" href="<?= e(base_url('pbx_systems.php')) ?>">Központok</a></li -->
        <li class="nav-item"><a class="nav-link" href="<?= e(base_url('ip_calc.php')) ?>">IP kalkulátor</a></li>
      </ul>

      <?php if ($u): ?>
        <div class="d-flex align-items-center gap-2">
          <span class="text-secondary small">
            Bejelentkezve: <strong><?= e(($u['name'] ?? '') ?: ($u['email'] ?? '') ?: ($u['username'] ?? '')) ?></strong>
            (<span class="text-muted"><?= e($u['role'] ?? '') ?></span>)
          </span>
          <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('logout.php')) ?>">Kijelentkezés</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container">
  <?php if ($m = flash_get('ok')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
  <?php if ($m = flash_get('err')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

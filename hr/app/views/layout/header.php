<?php
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$loggedIn = !empty($user);
$u = $user ?? null;
$isAdmin = ($u['role'] ?? '') === 'admin';
?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title ?? 'HR') ?></title>

  <link href="/assets/bootstrap/bootstrap.min.css" rel="stylesheet">

  <style>
    body{background:#fff;}
    .navbar-brand{font-weight:700;}
    .table thead th{font-size:.75rem; text-transform:uppercase; letter-spacing:.06em;}
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4 border-bottom">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= $loggedIn ? '/employees' : '/login' ?>">HR</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($loggedIn): ?>
          <li class="nav-item"><a class="nav-link" href="/employees">Dolgozók</a></li>
          <li class="nav-item"><a class="nav-link" href="/documents">Dokumentumok</a></li>

          <?php if ($isAdmin): ?>
            <!-- li class="nav-item"><a class="nav-link" href="/users">Felhasználók</a></li -->
            <li class="nav-item"><a class="nav-link" href="/fields">Mezők</a></li>
            <li class="nav-item"><a class="nav-link" href="/divisions">Divíziók</a></li>
            <li class="nav-item"><a class="nav-link" href="/doctypes">Dokumentumtípusok</a></li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>

      <div class="d-flex align-items-center">
        <?php if ($loggedIn): ?>
          <span class="navbar-text me-3">Bejelentkezve: <?= h($u['name'] ?? '') ?></span>

          <form method="post" action="/logout" class="m-0">
            <input type="hidden" name="_csrf" value="<?= h($_SESSION['_csrf'] ?? '') ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Kilépés</button>
          </form>
        <?php else: ?>
          <a class="btn btn-sm btn-outline-primary" href="/login">Belépés</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<div class="container">

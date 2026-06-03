<?php
// $title, $active_page kell
$flash = get_flash();
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tricc Admin — <?= htmlspecialchars($title ?? '') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
.navbar-brand { font-weight: 700; letter-spacing: -0.5px; }
.navbar-brand span { color: #0d6efd; }
body { background: #f0f2f5; }
</style>
</head>
<body>
<nav class="navbar navbar-expand navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="users.php">Tri<span>cc</span> Admin</a>
    <ul class="navbar-nav me-auto">
      <li class="nav-item">
        <a class="nav-link <?= ($active_page??'') === 'users' ? 'active' : '' ?>" href="users.php">
          <i class="bi bi-people"></i> Felhasználók
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= ($active_page??'') === 'invites' ? 'active' : '' ?>" href="invites.php">
          <i class="bi bi-envelope-plus"></i> Meghívók
        </a>
      </li>
    </ul>
    <span class="navbar-text me-3 text-white-50 small">
      <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['tricc_admin']['name']) ?>
    </span>
    <a href="logout.php" class="btn btn-outline-secondary btn-sm">Kilépés</a>
  </div>
</nav>
<div class="container">
<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible">
    <?= htmlspecialchars($flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

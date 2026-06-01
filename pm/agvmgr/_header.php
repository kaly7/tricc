<?php
$_agv_ver = file_exists(__DIR__ . '/version.txt') ? trim(file_get_contents(__DIR__ . '/version.txt')) : '';
$_agv_page = $page ?? '';
$_nav = [
    'index'  => ['url' => 'index.php',  'label' => 'Dashboard',    'admin' => false],
    'agvs'   => ['url' => 'agvs.php',   'label' => 'AGV-k',        'admin' => false],
    'map'    => ['url' => 'map.php',    'label' => 'Térkép',       'admin' => false],
    'events' => ['url' => 'events.php', 'label' => 'Eseménynapló', 'admin' => false],
];
$_nav_admin = [
    'admin'  => ['url' => 'admin.php',  'label' => 'AGV beállítások'],
    'omron'  => ['url' => 'omron.php',  'label' => 'Omron átadás'],
    'users'  => ['url' => 'users.php',  'label' => 'Felhasználók'],
];
$_admin_pages = array_keys($_nav_admin);
$_dropdown_active = in_array($_agv_page, $_admin_pages);
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="styles.css?v=<?= $_agv_ver ?: time() ?>">
<title>AGV Manager<?= isset($title) ? ' – ' . e($title) : '' ?></title>
</head>
<body>
<header class="pm-header">
  <div class="pm-header-inner">
    <div class="pm-header-logos">
      <img src="../img/honeywell_logo.svg" class="logo-honeywell" alt="Honeywell">
    </div>
    <span class="pm-header-title">AGV Manager</span>
    <div class="pm-header-user">
      <?php if (agv_logged_in()): ?>
        <span class="user-badge"><?= e($_SESSION['agv_user']) ?></span>
        <a href="users.php" class="button_mentes" style="font-size:13px;padding:7px 14px;">Jelszó</a>
        <a href="logout.php" class="button_mentes" style="font-size:13px;padding:7px 16px;">Kilépés</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if (agv_logged_in()): ?>
<nav class="agv-nav">
  <?php foreach ($_nav as $key => $n): ?>
    <a href="<?= e($n['url']) ?>" class="agv-nav-link <?= $_agv_page === $key ? 'active' : '' ?>">
      <?= e($n['label']) ?>
    </a>
  <?php endforeach; ?>

  <?php if (!empty($_SESSION['agv_admin'])): ?>
  <div class="agv-nav-dropdown <?= $_dropdown_active ? 'active' : '' ?>">
    <button class="agv-nav-link agv-nav-dropdown-btn <?= $_dropdown_active ? 'active' : '' ?>">
      Rendszer <span class="agv-nav-caret">▾</span>
    </button>
    <div class="agv-nav-dropdown-menu">
      <?php foreach ($_nav_admin as $key => $n): ?>
        <a href="<?= e($n['url']) ?>" class="agv-nav-dropdown-item <?= $_agv_page === $key ? 'active' : '' ?>">
          <?= e($n['label']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</nav>
<?php endif; ?>

<div class="agv-content">

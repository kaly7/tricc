<?php
$_agv_ver = file_exists(__DIR__ . '/version.txt') ? trim(file_get_contents(__DIR__ . '/version.txt')) : '';
$_agv_page = $page ?? '';
$_nav = [
    'index'  => ['url' => 'index.php',  'label' => 'Dashboard',       'admin' => false],
    'agvs'   => ['url' => 'agvs.php',   'label' => 'AGV-k',           'admin' => false],
    'omron'  => ['url' => 'omron.php',  'label' => 'Omron átadás',    'admin' => true],
    'admin'  => ['url' => 'admin.php',  'label' => 'Beállítások',     'admin' => true],
];
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
        <a href="logout.php" class="button_mentes" style="font-size:13px;padding:7px 16px;">Kilépés</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if (agv_logged_in()): ?>
<nav class="agv-nav">
  <?php foreach ($_nav as $key => $n): ?>
    <?php if ($n['admin'] && empty($_SESSION['agv_admin'])) continue; ?>
    <a href="<?= e($n['url']) ?>" class="agv-nav-link <?= $_agv_page === $key ? 'active' : '' ?>">
      <?= e($n['label']) ?>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="agv-content">

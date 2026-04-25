<?php
// Változók, amiket a befoglaló oldal állíthat be a require előtt:
//   $title      (string)  – <title> tartalom
//   $head_extra (string)  – extra CSS blokk a <head>-be
$_mj_page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title ?? 'MJ-Ajánlat-PKS') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<?php if (!empty($head_extra)) echo $head_extra; ?>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom py-1 mb-0">
  <div class="container-fluid px-4">
    <a class="navbar-brand fw-bold py-0" href="/index.php" style="font-size:1rem">MJ-Ajánlat</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mjNav" aria-label="Menü">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="mjNav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-0">
        <li class="nav-item">
          <a class="nav-link py-1 <?= $_mj_page === 'index.php' ? 'active fw-semibold' : '' ?>" href="/index.php">Projektek</a>
        </li>
        <li class="nav-item">
          <a class="nav-link py-1 <?= $_mj_page === 'egysegarak.php' ? 'active fw-semibold' : '' ?>" href="/egysegarak.php">Egységárak</a>
        </li>
        <li class="nav-item">
          <a class="nav-link py-1 <?= $_mj_page === 'katalogus.php' ? 'active fw-semibold' : '' ?>" href="/katalogus.php">Katalógus</a>
        </li>
      </ul>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="navbar-text small py-0">
          Bejelentkezve: <strong><?= h($mj_user['full_name'] ?? 'Felhasználó') ?></strong>
        </span>
        <a class="btn btn-sm btn-outline-info py-0" href="/help.php" title="Kézikönyv" target="_blank">?</a>
        <a class="btn btn-sm btn-outline-secondary py-0" href="<?= h(build_url((int)$config['auth_port'], '/apps.php')) ?>">Rendszerek</a>
        <a class="btn btn-sm btn-outline-secondary py-0" href="<?= h(build_url((int)$config['auth_port'], '/logout.php')) ?>">Kilépés</a>
      </div>
    </div>
  </div>
</nav>

<div class="container-fluid py-3 px-4">

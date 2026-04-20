<!-- warehousemgr kommentelt forrás: Közös fejléc / navigáció minden oldalon. | Itt áll össze a menü, az aktív menüpont kiemelése és a felhasználói fejléc. -->
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title ?? 'Raktárkezelő') ?></title>
  <link href="/assets/bootstrap/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/app.css" rel="stylesheet">
  <style>
    html, body { height: 100%; }
    body { min-height: 100vh; display: flex; flex-direction: column; }
    main.pp-main { flex: 1 0 auto; }
    footer.pp-footer { flex-shrink: 0; }
  </style>
</head>
<body>
<?php
  // A menü az aktuális scriptnév alapján kap aktív állapotot,
  // így az oldalak URL-je alapján egyszerűen kiemelhető a megfelelő csoport.
  $currentPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  $currentScript = basename($currentPath ?: '/');
  $navIsActive = static function (array $scripts) use ($currentScript): bool {
      return in_array($currentScript, $scripts, true);
  };
?>
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-4">
  <div class="container-fluid wm-shell">
    <a class="navbar-brand fw-bold" href="/">Raktárkezelő</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $navIsActive(['materials.php', 'material_identifiers.php', 'identifier_staging.php', 'identifier_staging_mobile.php']) ? 'active' : '' ?>" href="#" id="navMaterials" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Anyagok
          </a>
          <ul class="dropdown-menu" aria-labelledby="navMaterials">
            <li><a class="dropdown-item <?= $navIsActive(['materials.php']) ? 'active' : '' ?>" href="/materials.php">Anyagtörzs</a></li>
            <?php if (function_exists('warehouse_material_identifier_feature_ready') && warehouse_material_identifier_feature_ready($config)): ?>
            <li><a class="dropdown-item <?= $navIsActive(['material_identifiers.php']) ? 'active' : '' ?>" href="/material_identifiers.php">Azonosítók</a></li>
            <li><a class="dropdown-item <?= $navIsActive(['identifier_staging.php']) ? 'active' : '' ?>" href="/identifier_staging.php">Ideiglenes beolvasás</a></li>
            <li><a class="dropdown-item <?= $navIsActive(['identifier_staging_mobile.php']) ? 'active' : '' ?>" href="<?= h(warehouse_mobile_scanner_url('/identifier_staging_mobile.php')) ?>">Mobil szkenner</a></li>
            <?php endif; ?>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $navIsActive(['warehouses.php', 'stock.php', 'partners.php']) ? 'active' : '' ?>" href="#" id="navWarehouses" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Raktárak
          </a>
          <ul class="dropdown-menu" aria-labelledby="navWarehouses">
            <li><a class="dropdown-item <?= $navIsActive(['warehouses.php']) ? 'active' : '' ?>" href="/warehouses.php">Raktárak</a></li>
            <li><a class="dropdown-item <?= $navIsActive(['stock.php']) ? 'active' : '' ?>" href="/stock.php">Raktárkészlet</a></li>
            <?php if (warehouse_module_admin($config)): ?>
            <li><a class="dropdown-item <?= $navIsActive(['partners.php']) ? 'active' : '' ?>" href="/partners.php">Partnerek</a></li>
            <?php endif; ?>
          </ul>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $navIsActive(['transfers.php']) ? 'active' : '' ?>" href="/transfers.php">Átadások</a>
        </li>

        <?php if (warehouse_module_admin($config)): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $navIsActive(['stock_movements.php', 'audit_log.php', 'warehouse_archives.php', 'system_reset.php']) ? 'active' : '' ?>" href="#" id="navAdmin" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Admin
          </a>
          <ul class="dropdown-menu" aria-labelledby="navAdmin">
            <li><a class="dropdown-item <?= $navIsActive(['stock_movements.php']) ? 'active' : '' ?>" href="/stock_movements.php">Mozgások</a></li>
            <li><a class="dropdown-item <?= $navIsActive(['audit_log.php']) ? 'active' : '' ?>" href="/audit_log.php">Napló</a></li>
            <li><a class="dropdown-item <?= $navIsActive(['warehouse_archives.php']) ? 'active' : '' ?>" href="/warehouse_archives.php">Archívumok</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger fw-semibold <?= $navIsActive(['system_reset.php']) ? 'active' : '' ?>" href="/system_reset.php">Teljes ürítés</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
      <span class="navbar-text me-3">Bejelentkezve: <strong><?= h($user['full_name'] ?? 'Felhasználó') ?></strong></span>
      <a class="btn btn-sm btn-outline-secondary me-2" href="<?= h(build_url((int)$config['auth_port'], '/apps.php')) ?>">Rendszerek</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(build_url((int)$config['auth_port'], '/logout.php')) ?>">Kilépés</a>
    </div>
  </div>
</nav>
<main class="pp-main">
  <div class="container-fluid wm-shell">

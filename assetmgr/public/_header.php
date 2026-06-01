<?php
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/warehouses.php';
$u = current_user();
$title = $title ?? (string)config()['app_name'];
$page = $page ?? '';
$isAdmin = ($u['role'] ?? '') === 'admin';
$hasWarehouseAccess = false;
try { $hasWarehouseAccess = warehouse_is_admin($u); } catch (Throwable $e) { $hasWarehouseAccess = false; }

// Pending inbox count (átadásra vár) - csak ha van HR employee id + status mező
$pendingCount = 0;
try {
  $empId = (int)($u['hr_employee_id'] ?? 0);
  if ($empId <= 0) $empId = (int)($_SESSION['user']['hr_employee_id'] ?? 0);
  if ($empId <= 0) $empId = (int)($_SESSION['hr_employee_id'] ?? 0);
  if ($empId <= 0) $empId = (int)($_SESSION['auth_user']['hr_employee_id'] ?? 0);

  if ($empId > 0) {
    $pdo = db();
    $hasStatus = false;
    foreach ($pdo->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
      if ((string)$c['Field'] === 'status') { $hasStatus = true; break; }
    }
    if ($hasStatus) {
      $st = $pdo->prepare("SELECT COUNT(*) FROM asset_assignments WHERE to_employee_id=? AND status='pending'");
      $st->execute([$empId]);
      $pendingCount = (int)($st->fetchColumn() ?: 0);
    }
  }
} catch (Throwable $e) {
  $pendingCount = 0;
}

// QR demó: iOS-hez HTTPS kell, ezért a linket 9443-ra tesszük.
// Host meghatározása (Host header alapján)
$host = $_SERVER['HTTP_HOST'] ?? '';
$host = preg_replace('/:\d+$/', '', $host); // port lecsípése
$qrUrl = 'https://'.$host.':9443/qr_demo.php';
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
    <a class="navbar-brand fw-bold" href="<?= e(base_url('assets.php')) ?>"><?= e(config()['app_name']) ?></a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="<?= e(base_url('my_assets.php')) ?>">Nálam lévő eszközök</a></li>
        <li class="nav-item">
          <a class="nav-link" href="<?= e(base_url('inbox.php')) ?>">
            Átadásra vár
            <?php if ($pendingCount > 0): ?>
              <span class="badge bg-danger ms-1"><?= (int)$pendingCount ?></span>
            <?php endif; ?>
          </a>
        </li>

        <!-- Technológiai demó menüpont (HTTPS:9443) -->
        <!-- li class="nav-item"><a class="nav-link" href="<?= e($qrUrl) ?>">QR teszt</a></li -->

        <?php if ($isAdmin):
          $navInspRed = 0;
          try {
            $navPdo = db();
            $navLatest = "(SELECT next_date FROM asset_inspections WHERE asset_id=a.id ORDER BY inspection_date DESC, id DESC LIMIT 1)";
            $navSt = $navPdo->query("SELECT COUNT(*) FROM assets a WHERE a.is_deleted=0 AND a.inspection_required=1 AND $navLatest < CURDATE()");
            $navInspRed = (int)($navSt->fetchColumn() ?: 0);
          } catch (Throwable $e) {}
        ?>
          <li class="nav-item"><a class="nav-link" href="<?= e(base_url('assets.php')) ?>">Eszközök<?php if ($navInspRed > 0): ?> <span class="badge bg-danger ms-1"><?= $navInspRed ?></span><?php endif; ?></a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(base_url('categories.php')) ?>">Kategóriák</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(base_url('pending_transfers.php')) ?>">Függő átadások</a></li>
        <?php endif; ?>
        <?php if ($isAdmin || $hasWarehouseAccess): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="warehouseMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">Raktár</a>
            <ul class="dropdown-menu" aria-labelledby="warehouseMenu">
              <?php if ($isAdmin): ?><li><a class="dropdown-item" href="<?= e(base_url('warehouses.php')) ?>">Raktárak</a></li><?php endif; ?>
              <li><a class="dropdown-item" href="<?= e(base_url('warehouse_stock.php')) ?>">Raktárkészlet</a></li>
              <li><a class="dropdown-item" href="<?= e(base_url('warehouse_intake.php')) ?>">Raktárba vétel</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>

      <?php if ($u): ?>
        <div class="d-flex align-items-center gap-2">
          <span class="text-secondary small">
            Bejelentkezve: <strong><?= e(($u['name'] ?? '') ?: ($u['email'] ?? '') ?: ($u['username'] ?? '')) ?></strong>
            (<span class="text-muted"><?= e($u['role'] ?? '') ?></span>)
          </span>
          <a class="btn btn-outline-info btn-sm" href="<?= e(base_url('docs/assetmgr_kezikonyv.html')) ?>" target="_blank" title="Felhasználói kézikönyv">?</a>
          <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('account.php')) ?>">Fiók</a>
          <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('logout.php')) ?>">Kijelentkezés</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</nav>
<?php if (!empty($_SESSION['user_id'])) require_once '/var/www/html/_common/easter/easter_init.php'; ?>
<div class="container">
  <?php if ($m = flash_get('ok')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
  <?php if ($m = flash_get('err')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

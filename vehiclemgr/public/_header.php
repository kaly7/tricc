<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
$u       = current_user();
$title   = $title ?? (string)config()['app_name'];
$page    = $page ?? '';
$isAdmin = ($u['role'] ?? '') === 'admin';

// Bejövő átadások száma
$pendingCount = 0;
try {
  $myEmpId = my_employee_id();
  if ($myEmpId > 0) {
    $st = db()->prepare("SELECT COUNT(*) FROM vehicle_transfers vt JOIN vehicle_assignments va ON va.id=vt.assignment_id WHERE vt.to_employee_id=? AND vt.status='pending' AND vt.type='transfer_to_employee'");
    $st->execute([$myEmpId]);
    $pendingCount = (int)$st->fetchColumn();
  }
} catch (Throwable $e) {}

// Admin: függő visszaadások száma
$returnCount = 0;
if ($isAdmin) {
  try {
    $st = db()->query("SELECT COUNT(*) FROM vehicle_transfers WHERE type='return_to_fleet' AND status='pending'");
    $returnCount = (int)$st->fetchColumn();
  } catch (Throwable $e) {}
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> – <?= e(config()['app_name']) ?></title>
  <link rel="stylesheet" href="<?= e(asset_url('assets/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="<?= e(base_url('index.php')) ?>">
      🚗 <?= e(config()['app_name']) ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link<?= $page==='my_vehicles' ? ' active' : '' ?>" href="<?= e(base_url('my_vehicles.php')) ?>">Járműveim</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?= $page==='inbox' ? ' active' : '' ?>" href="<?= e(base_url('inbox.php')) ?>">
            Bejövő
            <?php if ($pendingCount > 0): ?><span class="badge bg-danger ms-1"><?= $pendingCount ?></span><?php endif; ?>
          </a>
        </li>
        <?php if ($isAdmin): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= str_starts_with($page, 'admin') ? ' active' : '' ?>" href="#" data-bs-toggle="dropdown">
            Admin
            <?php if ($returnCount > 0): ?><span class="badge bg-warning text-dark ms-1"><?= $returnCount ?></span><?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-dark">
            <li><a class="dropdown-item" href="<?= e(base_url('admin_vehicles.php')) ?>">Járművek &amp; kiosztás</a></li>
            <li><a class="dropdown-item" href="<?= e(base_url('admin_returns.php')) ?>">
              Visszaadások
              <?php if ($returnCount > 0): ?><span class="badge bg-warning text-dark ms-1"><?= $returnCount ?></span><?php endif; ?>
            </a></li>
            <li><a class="dropdown-item" href="<?= e(base_url('admin_missing_checklists.php')) ?>">Hiányzó checklistek</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(base_url('admin_log.php')) ?>">Napló</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
      <?php if ($u): ?>
      <div class="d-flex align-items-center gap-2">
        <span class="text-light small">
          <strong><?= e($u['name'] ?? $u['username'] ?? '') ?></strong>
          <span class="text-secondary">(<?= e($u['role'] ?? '') ?>)</span>
        </span>
        <a class="btn btn-outline-light btn-sm" href="<?= e(base_url('logout.php')) ?>">Kilépés</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container pb-5">
<?php
$_ok  = flash_get('ok');
$_err = flash_get('err');
if ($_ok):  ?><div class="alert alert-success alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button><?= e($_ok) ?></div><?php endif;
if ($_err): ?><div class="alert alert-danger alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button><?= e($_err) ?></div><?php endif;

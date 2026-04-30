<?php
require_once __DIR__ . '/../app/auth.php';
$u       = current_user();
$isAdmin = ($u['role'] ?? '') === 'admin';
$page    = $page ?? '';
$title   = ($title ?? '') ?: config()['app_name'];
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> – Napiterv</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { background: #f0f2f5; }
    .navbar-brand { font-weight: 700; letter-spacing: -.3px; }

    /* ── Gantt tábla ── */
    .wp-table { border-collapse: separate; border-spacing: 2px; width: 100%; background: #ced4da;
        table-layout: fixed; }
    .wp-table th, .wp-table td { border: none; padding: 0; }
    .wp-table thead th { background: #212529; color: #fff; text-align: center; white-space: nowrap;
        position: sticky; top: 0; z-index: 2; padding: 5px 8px; font-size: .80rem; line-height: 1.3; }
    .wp-table thead th:first-child { text-align: left; z-index: 4; position: sticky; left: 0; top: 0;
        width: 150px; }
    .wp-table thead th.today-col { background: #0d6efd; }
    .wp-table thead th.we-col    { background: #495057; }
    .wp-table tbody td.emp-col   { background: #f8f9fa; font-size: .80rem; font-weight: 600;
        white-space: nowrap; width: 150px; position: sticky; left: 0; z-index: 1;
        border-right: none; padding: 4px 8px; vertical-align: middle; }
    .wp-table tbody td.day-cell  { background: #fff; height: 90px;
        padding: 7px 5px; vertical-align: top; }
    .wp-table tbody td.today-cell { background: #eff6ff; }
    .wp-table tbody tr:hover td  { filter: brightness(.97); }
    .wp-table tbody tr:hover td.emp-col { filter: brightness(.95); }

    /* Feladat sávok – flex elosztás */
    .day-cell-flex { display: flex; flex-direction: column; height: 100%; gap: 3px; }
    .tl-task       { flex: 1; min-height: 0; border-radius: 5px; padding: 3px 10px;
        display: flex; align-items: center; gap: 8px; overflow: hidden; white-space: nowrap;
        font-size: .76rem; box-shadow: 0 1px 3px rgba(0,0,0,.15); cursor: default;
        position: relative; }
    /* Sraffozás átfedő feladatoknál */
    .tl-task.overlap::after { content: ''; position: absolute; inset: 0; border-radius: inherit;
        background: repeating-linear-gradient(45deg,
          transparent 0px, transparent 5px,
          rgba(0,0,0,.18) 5px, rgba(0,0,0,.18) 7px);
        pointer-events: none; }
    .tl-task-time  { font-weight: 700; font-size: .68rem; flex-shrink: 0; opacity: .9; }
    .tl-task-title { overflow: hidden; text-overflow: ellipsis; font-weight: 600; }
    .tl-task-loc   { font-size: .65rem; opacity: .8; flex-shrink: 0; }
    .tl-edit-lnk   { color: inherit; opacity: .6; text-decoration: none; flex-shrink: 0;
        margin-left: auto; padding-left: 4px; font-size: .75rem; }
    .tl-edit-lnk:hover { opacity: 1; }

    <?= $head_extra ?? '' ?>
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-3">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold" href="<?= base_url('index.php') ?>">📅 Napiterv</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#wpNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="wpNav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link<?= $page==='index' ? ' active fw-semibold' : '' ?>" href="<?= base_url('index.php') ?>">Heti terv</a>
        </li>
        <?php if ($u): ?>
        <li class="nav-item">
          <a class="nav-link<?= $page==='my_tasks' ? ' active fw-semibold' : '' ?>" href="<?= base_url('my_tasks.php') ?>">Saját feladataim</a>
        </li>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= str_starts_with($page, 'admin') ? ' active fw-semibold' : '' ?>" href="#" data-bs-toggle="dropdown">Admin</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= base_url('admin_tasks.php') ?>">Feladatok kezelése</a></li>
            <li><a class="dropdown-item" href="<?= base_url('admin_task_edit.php') ?>">+ Új feladat</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= base_url('admin_locations.php') ?>">Helyszínek &amp; színek</a></li>
            <li><a class="dropdown-item" href="<?= base_url('admin_employees.php') ?>">Dolgozók kezelése</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= base_url('admin_kiosk.php') ?>">🖥 Kiosk beállítások</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <?php if ($u):
          $authPort = (int)(config()['auth_center_port'] ?? 90);
          $authHost = explode(':', $_SERVER['HTTP_HOST'])[0];
          $appsUrl  = 'http://' . $authHost . ':' . $authPort . '/apps.php';
        ?>
          <span class="text-muted small"><?= e($u['name']) ?></span>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e($appsUrl) ?>">Rendszerek</a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('logout.php') ?>">Kilépés</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<div class="container-fluid px-3">
<?php
$_ok  = flash_get('ok');
$_err = flash_get('err');
if ($_ok):  ?><div class="alert alert-success alert-dismissible mb-3"><button class="btn-close" data-bs-dismiss="alert"></button><?= e($_ok) ?></div><?php endif;
if ($_err): ?><div class="alert alert-danger  alert-dismissible mb-3"><button class="btn-close" data-bs-dismiss="alert"></button><?= e($_err) ?></div><?php endif;

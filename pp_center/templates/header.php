<?php $flash = flash_get(); $currentPath = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'index.php'); ?>
<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? cfg('app.name')) ?> – <?= e(cfg('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('assets/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/app.css')) ?>">
</head>
<body>
<nav class="navbar navbar-light pp-navbar sticky-top">
    <div class="container-xxl pp-navbar-inner">
        <a class="navbar-brand pp-navbar-brand" href="<?= e(app_url('index.php')) ?>">
            <span class="pp-brand-badge">PP</span>
            <span class="pp-brand-title">Perfect-Phone Esp32 Control Center</span>
        </a>
        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
            <?php if (!empty($currentUserName ?? '')): ?>
                <span class="text-secondary small">Bejelentkezve: <strong><?= e($currentUserName) ?></strong></span>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(auth_center_url('/apps.php')) ?>">Rendszerek</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(auth_center_url('/logout.php')) ?>">Kilépés</a>
        </div>
    </div>
</nav>

<main class="pp-main">
    <div class="container-xxl py-4">
        <div class="pp-subnav pp-subnav-buttons">
            <a class="<?= $currentPath === 'index.php' ? 'active' : '' ?>" href="<?= e(app_url('index.php')) ?>">Áttekintés</a>
            <a class="<?= ($currentPath === 'devices.php' || $currentPath === 'device.php' || $currentPath === 'device_charts.php') ? 'active' : '' ?>" href="<?= e(app_url('devices.php')) ?>">Eszközök</a>
            <a class="<?= $currentPath === 'alerts.php' ? 'active' : '' ?>" href="<?= e(app_url('alerts.php')) ?>">Riasztások</a>
            <a class="<?= $currentPath === 'telemetry.php' ? 'active' : '' ?>" href="<?= e(app_url('telemetry.php')) ?>">Telemetria</a>
            <a class="<?= $currentPath === 'queue.php' ? 'active' : '' ?>" href="<?= e(app_url('queue.php')) ?>">Queue</a>
            <a class="<?= $currentPath === 'bridge.php' ? 'active' : '' ?>" href="<?= e(app_url('bridge.php')) ?>">Bridge</a>
            <a class="<?= $currentPath === 'logs.php' ? 'active' : '' ?>" href="<?= e(app_url('logs.php')) ?>">Logok</a>
            <a class="<?= $currentPath === 'payloads.php' ? 'active' : '' ?>" href="<?= e(app_url('payloads.php')) ?>">Payloadok</a>
            <a class="<?= $currentPath === 'help.php' ? 'active' : '' ?>" href="<?= e(app_url('help.php')) ?>">Súgó</a>
        </div>

        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

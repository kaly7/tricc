<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title ?? 'Munkaidő') ?></title>
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
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-4">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/index.php">Munkaidő</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nav" class="collapse navbar-collapse">
      <?php
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $groupsActive = strpos($script, 'groups.php') !== false || strpos($script, 'group_members.php') !== false || strpos($script, 'group_leaders.php') !== false;
        $settingsActive = strpos($script, 'color_rules.php') !== false || strpos($script, 'absence_types.php') !== false || strpos($script, 'holidays.php') !== false || strpos($script, 'templates.php') !== false || strpos($script, 'vehicles.php') !== false;
      ?>
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link<?= (strpos($script, 'index.php') !== false) ? ' active' : '' ?>" href="/index.php">Naptár</a></li>
        <li class="nav-item"><a class="nav-link<?= (strpos($script, 'report.php') !== false) ? ' active' : '' ?>" href="/report.php">Riport</a></li>
        <?php if (tracker_is_admin($config) || tracker_is_group_leader($config)): ?>
        <li class="nav-item"><a class="nav-link<?= (strpos($script, 'team_dashboard.php') !== false) ? ' active' : '' ?>" href="/team_dashboard.php">Csapatom</a></li>
        <?php endif; ?>
        <?php if (tracker_is_admin($config)): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $groupsActive ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Csoportok</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item<?= strpos($script, 'groups.php') !== false ? ' active' : '' ?>" href="/groups.php">Csoportok</a></li>
            <li><a class="dropdown-item<?= strpos($script, 'group_members.php') !== false ? ' active' : '' ?>" href="/group_members.php">Csoporttagok</a></li>
            <li><a class="dropdown-item<?= strpos($script, 'group_leaders.php') !== false ? ' active' : '' ?>" href="/group_leaders.php">Csoportvezetők</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $settingsActive ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Beállítások</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item<?= strpos($script, 'templates.php') !== false ? ' active' : '' ?>" href="/templates.php">Sablonok</a></li>
            <li><a class="dropdown-item<?= strpos($script, 'color_rules.php') !== false ? ' active' : '' ?>" href="/color_rules.php">Nap színek</a></li>
            <li><a class="dropdown-item<?= strpos($script, 'absence_types.php') !== false ? ' active' : '' ?>" href="/absence_types.php">Távollét típusok</a></li>
            <li><a class="dropdown-item<?= strpos($script, 'holidays.php') !== false ? ' active' : '' ?>" href="/holidays.php">Ünnepnapok</a></li>
            <li><a class="dropdown-item<?= strpos($script, 'vehicles.php') !== false ? ' active' : '' ?>" href="/vehicles.php">Járművek</a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link<?= (strpos($script, 'audit.php') !== false) ? ' active' : '' ?>" href="/audit.php">Napló</a></li>
        <?php endif; ?>
      </ul>
      <span class="navbar-text me-3">Bejelentkezve: <strong><?= h($user['resolved_employee_name'] ?? $user['full_name'] ?? 'Felhasználó') ?></strong></span>
      <a class="btn btn-sm btn-outline-secondary me-2" href="<?= h(build_url((int)$config['auth_port'], '/apps.php')) ?>">Rendszerek</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h(build_url((int)$config['auth_port'], '/logout.php')) ?>">Kilépés</a>
    </div>
  </div>
</nav>
<main class="pp-main">
  <div class="container">

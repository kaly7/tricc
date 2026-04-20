<?php
require_once __DIR__ . '/../functions.php';
$u = current_user();
$path = $_SERVER['SCRIPT_NAME'] ?? '';

function navLink(string $href, string $label, string $path): string {
    $active = (basename($path) === ltrim($href,'/')) ? 'active' : '';
    return '<a class="'.$active.'" href="'.$href.'">'.h($label).'</a>';
}

// Theme resolution: from session -> user -> default 'modern'
$theme = $_SESSION['theme'] ?? ($u['theme'] ?? 'modern');
$allowed = ['modern','light','dark','industrial','playful'];
if (!in_array($theme, $allowed, true)) { $theme = 'modern'; }
$themeHref = '/themes/theme-' . $theme . '.css';
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Timesheet</title>
  <link rel="stylesheet" href="<?= h($themeHref) ?>">
  <script defer src="/app.js"></script>
</head>
<body>
<nav>
  <div class="container nav-inner">
    <div class="brand">⏱️ Timesheet</div>
    <button class="burger" aria-label="Menü">☰</button>
    <div class="nav-links">
      <?= navLink('/calendar.php','Naptár', $path) ?>
      <?php if (is_admin()): ?>
        <?= navLink('/admin_users.php','Felhasználók', $path) ?>
        <?= navLink('/projects.php','Projektek', $path) ?>
        <?= navLink('/reports.php','Riportok', $path) ?>
        <?= navLink('/locks.php','Lezárások', $path) ?>
      <?php endif; ?>
      <?php if ($u): ?>
        <?= navLink('/profile.php','Profil', $path) ?>
        <a href="/logout.php">Kilépés</a>
        <span class="badge small"><?= h(($u['full_name'] ?? '') ?: $u['username']) ?> · <?= h($u['role']) ?></span>
      <?php else: ?>
        <a href="/login.php">Belépés</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<div class="container">

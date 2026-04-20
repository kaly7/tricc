<?php
// Auth Center header (Brand: Perfect-Phone)
// Robustly detects logged-in user from session to avoid "eltűnt" userbar issues.
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title ?? 'Perfect-Phone', ENT_QUOTES, 'UTF-8') ?></title>
  <link href="/assets/bootstrap/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/app.css" rel="stylesheet">
  <style>
    /* Sticky footer layout */
    html, body { height: 100%; }
    body { min-height: 100vh; display: flex; flex-direction: column; }
    main.pp-main { flex: 1 0 auto; }
    footer.pp-footer { flex-shrink: 0; }
  </style>
</head>
<body>

<?php
  // Try to resolve user display name from multiple known session keys
  $displayName = '';

  if (isset($user) && is_array($user)) {
    $displayName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? '');
  }

  if ($displayName === '' && isset($_SESSION)) {
    if (!empty($_SESSION['full_name'])) $displayName = (string)$_SESSION['full_name'];
    else if (!empty($_SESSION['username'])) $displayName = (string)$_SESSION['username'];
    else if (!empty($_SESSION['user']['name'])) $displayName = (string)$_SESSION['user']['name'];
    else if (!empty($_SESSION['user']['full_name'])) $displayName = (string)$_SESSION['user']['full_name'];
    else if (!empty($_SESSION['user']['username'])) $displayName = (string)$_SESSION['user']['username'];
  }

  // Determine logged-in status from session keys (very tolerant)
  $isLoggedIn = false;
  if (isset($_SESSION)) {
    if (!empty($_SESSION['user_id'])) $isLoggedIn = true;
    if (!empty($_SESSION['uid'])) $isLoggedIn = true;
    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) $isLoggedIn = true;
    if (!empty($_SESSION['logged_in'])) $isLoggedIn = true;
    if ($displayName !== '') $isLoggedIn = true;
  }

  // If somehow logged in but name missing, show fallback
  if ($isLoggedIn && $displayName === '') {
    $displayName = 'Felhasználó';
  }
?>

<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-4">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/apps.php">Perfect-Phone</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto"></ul>

      <?php if ($isLoggedIn): ?>
        <span class="navbar-text me-3">
          Bejelentkezve: <strong><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></strong>
        </span>

        <!-- Admin link restored -->
        <a class="btn btn-sm btn-outline-primary me-2" href="/admin/users.php">Admin / Jelszó</a>

        <a class="btn btn-sm btn-outline-secondary" href="/apps.php">Rendszerek</a>
        <a class="btn btn-sm btn-outline-secondary ms-2" href="/logout.php">Kilépés</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<main class="pp-main">
  <div class="container">

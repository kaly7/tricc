<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';

$title = 'Belépés';
$return = (string)($_GET['return'] ?? '/assets.php');
$return = $return === '' ? '/assets.php' : $return;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim((string)($_POST['username'] ?? ''));
  $p = (string)($_POST['password'] ?? '');
  $return = (string)($_POST['return'] ?? '/assets.php');
  if (attempt_login($u, $p)) {
    header('Location: ' . $return);
    exit;
  }
  $error = 'Hibás felhasználónév vagy jelszó, vagy nincs jogosultság az Eszköz nyilvántartóhoz.';
}

?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 520px;">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 mb-3">Eszköz nyilvántartó – Belépés</h1>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="return" value="<?= htmlspecialchars($return) ?>">
        <div class="mb-3">
          <label class="form-label">Felhasználónév</label>
          <input class="form-control" name="username" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Jelszó</label>
          <input class="form-control" type="password" name="password" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Belépés</button>
      </form>

      <div class="text-muted mt-3" style="font-size: 0.9rem;">
        Belépés ugyanazzal a felhasználóval/jelszóval, mint az Auth Centerben.
      </div>
    </div>
  </div>
</div>
</body>
</html>

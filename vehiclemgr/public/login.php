<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';

start_session();
if (current_user()) { redirect('index.php'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = trim((string)($_POST['username'] ?? ''));
  $pass = (string)($_POST['password'] ?? '');
  if (attempt_login($user, $pass)) {
    $return = (string)($_GET['return'] ?? '');
    $return = $return && str_starts_with($return, '/') ? $return : base_url('index.php');
    header('Location: ' . $return);
    exit;
  }
  $err = 'Hibás felhasználónév vagy jelszó, vagy nincs hozzáférésed ehhez a modulhoz.';
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bejelentkezés – <?= e(config()['app_name']) ?></title>
  <link rel="stylesheet" href="<?= e(asset_url('assets/bootstrap/bootstrap.min.css')) ?>">
</head>
<body class="bg-light">
<div class="container" style="max-width:420px; margin-top:10vh;">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="mb-1 text-center">🚗 <?= e(config()['app_name']) ?></h4>
      <p class="text-muted text-center small mb-4">Bejelentkezés</p>
      <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label">Felhasználónév</label>
          <input type="text" name="username" class="form-control" autofocus autocomplete="username" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Jelszó</label>
          <input type="password" name="password" class="form-control" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Bejelentkezés</button>
      </form>
    </div>
  </div>
</div>
<script src="<?= e(asset_url('assets/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>

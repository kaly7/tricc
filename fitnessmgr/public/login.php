<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

if (current_user()) { redirect('dashboard.php'); }

$error  = '';
$return = trim($_GET['return'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = trim($_POST['username'] ?? '');
  $pass = $_POST['password'] ?? '';
  if (attempt_login($user, $pass)) {
    $dest = ($return !== '' && strpos($return, '//') === false) ? $return : base_url('dashboard.php');
    header('Location: ' . $dest);
    exit;
  }
  $error = 'Hibás felhasználónév vagy jelszó.';
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bejelentkezés – Fitness napló</title>
  <link rel="stylesheet" href="<?= e(asset_url('assets/bootstrap/bootstrap.min.css')) ?>">
</head>
<body class="bg-light">
<div class="d-flex align-items-center justify-content-center" style="min-height:100vh">
  <div class="card shadow-sm" style="width:360px">
    <div class="card-body p-4">
      <h4 class="card-title text-center mb-1">🥗 Fitness napló</h4>
      <p class="text-center text-muted small mb-4">Személyi egészség nyilvántartó</p>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <div class="mb-3">
          <label class="form-label">Felhasználónév</label>
          <input type="text" name="username" class="form-control" autofocus autocomplete="username" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Jelszó</label>
          <input type="password" name="password" class="form-control" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-success w-100">Bejelentkezés</button>
      </form>
    </div>
  </div>
</div>
<script src="<?= e(asset_url('assets/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>

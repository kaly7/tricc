<?php
session_start();
if (isset($_SESSION['tricc_admin'])) { header('Location: users.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg  = require __DIR__ . '/../config.php';
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($user === $cfg['admin_user'] && $pass === $cfg['admin_pass']) {
        $_SESSION['tricc_admin'] = ['name' => $user];
        header('Location: users.php'); exit;
    }
    $error = 'Hibás felhasználónév vagy jelszó.';
}
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tricc Admin — Belépés</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
body { background: #f0f2f5; }
.login-card { max-width: 400px; margin: 100px auto; }
.brand { font-size: 1.8rem; font-weight: 700; letter-spacing: -1px; }
.brand span { color: #0d6efd; }
</style>
</head>
<body>
<div class="login-card">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <div class="text-center mb-4">
        <div class="brand">Tri<span>cc</span></div>
        <div class="text-muted small">Admin panel</div>
      </div>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label fw-semibold">Email</label>
          <input type="email" name="username" class="form-control" autofocus required
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold">Jelszó</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Belépés</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>

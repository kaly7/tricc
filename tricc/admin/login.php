<?php
session_start();
if (isset($_SESSION['tricc_admin'])) { header('Location: users.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require '_db.php';
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $st = tricc_db()->prepare("SELECT id, name, password, is_admin, is_active FROM users WHERE email=?");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($pass, $u['password'])) {
        $error = 'Hibás email vagy jelszó.';
    } elseif (!$u['is_active']) {
        $error = 'Ez a fiók le van tiltva.';
    } elseif (!$u['is_admin']) {
        $error = 'Admin jogosultság szükséges.';
    } else {
        $_SESSION['tricc_admin'] = ['id' => $u['id'], 'name' => $u['name']];
        header('Location: users.php'); exit;
    }
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
          <input type="email" name="email" class="form-control" autofocus required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
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

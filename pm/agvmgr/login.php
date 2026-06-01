<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_session_start();

if (!empty($_SESSION['agv_user'])) {
    header('Location: index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $db   = agv_db();
    $stmt = $db->prepare("SELECT id, username, password, is_admin FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $user);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && password_verify($pass, $row['password'])) {
        $_SESSION['agv_user']  = $row['username'];
        $_SESSION['agv_uid']   = $row['id'];
        $_SESSION['agv_admin'] = (bool)$row['is_admin'];
        header('Location: index.php'); exit;
    }
    $error = 'Hibás felhasználónév vagy jelszó.';
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?= time() ?>">
<title>AGV Manager – Belépés</title>
</head>
<body class="login-page">

<div class="login-brand">
  <img src="../img/honeywell_logo.svg" class="logo-hw" alt="">
  <h1>AGV <span>Manager</span></h1>
</div>

<div class="login-card">
  <?php if ($error): ?>
    <p class="login-error">&#10007; <?= e($error) ?></p>
  <?php endif; ?>
  <form method="post">
    <div class="login-fields">
      <div>
        <label for="username">Felhasználói név</label>
        <input type="text" id="username" name="username" autocomplete="username" autofocus>
      </div>
      <div>
        <label for="password">Jelszó</label>
        <input type="password" id="password" name="password" autocomplete="current-password">
      </div>
    </div>
    <button type="submit" class="button_mentes" style="width:100%;">Belépés</button>
  </form>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>

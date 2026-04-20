<?php
require __DIR__ . '/partials/header.php';
$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify();
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  $stmt = $pdo->prepare("SELECT id, password_hash, is_active FROM users WHERE email = ?");
  $stmt->execute([$email]);
  $u = $stmt->fetch();
  if ($u && $u['is_active'] && password_verify($pass, $u['password_hash'])) {
    $_SESSION['user_id'] = $u['id'];
    redirect('jobs_list.php');
  } else { $error = 'Hibás e-mail/jelszó vagy inaktív felhasználó.'; }
}
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card"><div class="card-body">
      <h1 class="h4 mb-3">Belépés</h1>
      <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">E-mail</label><input class="form-control" type="email" name="email" required></div>
        <div class="mb-3"><label class="form-label">Jelszó</label><input class="form-control" type="password" name="password" required></div>
        <button class="btn btn-primary">Belépés</button>
      </form>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>

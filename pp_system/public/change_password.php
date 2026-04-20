<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../src/helpers.php';
$u = current_user();
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jelszó módosítása</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<style>
html, body {
  height: 100%;
}

body {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

main {
  flex: 1;
}


footer {
  text-align: center;
  padding: 1rem 0;
  font-size: 0.9rem;
  color: #666;
  position: relative;
}

footer::before {
  content: "";
  display: block;
  height: 2px;
  background: linear-gradient(to right, transparent, #555, transparent);
  margin: 0 0 1rem 0; /* vonal és szöveg közötti távolság */
}
</style>


</head>
<body class="bg-light">
<!-- nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <div class="d-flex align-items-center gap-2">
      <span class="navbar-text text-white-50 small"><?=h($u['name'])?> (<?=h($u['role'])?>)</span>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav -->

<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <div class="d-flex align-items-center gap-2">


<?php if (is_admin()): ?>


      <a class="btn btn-sm btn-outline-light" href="admin_users.php">Felhasználók</a>
      <a class="btn btn-sm btn-outline-light" href="admin_dicts.php">Törzsek</a>
      <a class="btn btn-sm btn-outline-light" href="admin_emails.php">E-mail sablonok</a>
<?php endif; ?>

      <span class="navbar-text text-white-50 small"><?=h($u['name'])?> (<?=h($u['role'])?>)</span>
      <a class="btn btn-sm btn-outline-light" href="change_password.php">Jelszó</a>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav>

<main class="container my-3">

<div class="container" style="max-width:640px">
  <h1 class="h5 mb-3">Jelszó módosítása</h1>

  <?php if($msg==='ok'): ?>
    <div class="alert alert-success">Jelszó sikeresen megváltoztatva.</div>
  <?php elseif($err==='badcur'): ?>
    <div class="alert alert-danger">A jelenlegi jelszó nem megfelelő.</div>
  <?php elseif($err==='short'): ?>
    <div class="alert alert-danger">Az új jelszó túl rövid (min. 8 karakter).</div>
  <?php elseif($err==='mismatch'): ?>
    <div class="alert alert-danger">Az új jelszavak nem egyeznek.</div>
  <?php elseif($err==='bad'): ?>
    <div class="alert alert-danger">Hibás kérés.</div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" action="actions/user_change_password.php" class="row g-3">
        <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
        <div class="col-12">
          <label class="form-label">Jelenlegi jelszó</label>
          <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="col-12">
          <label class="form-label">Új jelszó (min. 8 karakter)</label>
          <input type="password" name="new_password" class="form-control" minlength="8" required>
        </div>
        <div class="col-12">
          <label class="form-label">Új jelszó még egyszer</label>
          <input type="password" name="new_password_confirm" class="form-control" minlength="8" required>
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Jelszó módosítása</button>
          <a class="btn btn-secondary" href="records.php">Mégse</a>
        </div>
      </form>
    </div>
  </div>
</div>
</main>
<footer>
© Perfect Phone Munka Nyilvántartó
</footer>
</body>
</html>
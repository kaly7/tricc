<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect(); if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';
require_once __DIR__.'/../src/helpers.php';
$u = current_user();
$rows = db()->query('SELECT u.id,u.email,u.name,u.is_active,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.created_at DESC')->fetchAll();
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Felhasználók</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
</head>
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

<body>
<!-- nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
  </div>
</nav -->

<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-light" href="admin_users.php">Felhasználók</a>
      <a class="btn btn-sm btn-outline-light" href="admin_dicts.php">Törzsek</a>
      <a class="btn btn-sm btn-outline-light" href="admin_emails.php">E-mail sablonok</a>
      <span class="navbar-text text-white-50 small"><?=h($u['name'])?> (<?=h($u['role'])?>)</span>
      <a class="btn btn-sm btn-outline-light" href="change_password.php">Jelszó</a>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav>

<main class="container my-3">


<div class="container">


<?php if(($_GET['msg'] ?? '') === 'passwd_changed'): ?>
  <div class="alert alert-success">Jelszó sikeresen megváltoztatva.</div>
<?php elseif(($_GET['err'] ?? '') === 'short'): ?>
  <div class="alert alert-danger">A jelszó túl rövid (min. 8 karakter).</div>
<?php elseif(($_GET['err'] ?? '') === 'bad'): ?>
  <div class="alert alert-danger">Hibás kérés.</div>
<?php endif; ?>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card"><div class="card-header">Új felhasználó</div><div class="card-body">
        <form method="post" action="actions/admin_user_create.php" class="row g-2">
          <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
          <div class="col-md-6"><label class="form-label">Név</label><input class="form-control" name="name" required></div>
          <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" required></div>
          <div class="col-md-6"><label class="form-label">Jelszó</label><input type="text" class="form-control" name="password" required></div>
          <div class="col-md-6"><label class="form-label">Szerep</label>
            <select class="form-select" name="role_id"><option value="2">user</option><option value="1">admin</option></select>
          </div>
          <div class="col-12"><button class="btn btn-success">Létrehozás</button></div>
        </form>
      </div></div>
    </div>


<div class="container mt-4">

  <?php if(($_GET['err'] ?? '') === 'self'): ?>
    <div class="alert alert-warning">⚠ Saját fiók nem inaktiválható vagy törölhető.</div>
  <?php elseif(($_GET['err'] ?? '') === 'ref'): ?>
    <div class="alert alert-danger">❌ A felhasználó nem törölhető, mert vannak hozzá kapcsolt tételek vagy naplóbejegyzések. Használd inkább az inaktiválást.</div>
  <?php endif; ?>

  <h1>Felhasználók kezelése</h1>
    <div class="col-md-6">
      <div class="card"><div class="card-header">Lista</div><div class="card-body p-0">
        <table class="table table-sm mb-0">
          <!--- <thead><tr><th>Név</th><th>Email</th><th>Szerep</th><th>Aktív</th></tr></thead> --->

<thead>
  <tr>
    <th>Név</th>
    <th>Email</th>
    <th>Szerep</th>
    <th>Aktív</th>
    <th>Műveletek</th>
  </tr>
</thead>

         <!---  <tbody>
            <?php foreach($rows as $r): ?>
              <tr><td><?=h($r['name'])?></td><td><?=h($r['email'])?></td><td><?=h($r['role_name'])?></td><td><?=$r['is_active']?'igen':'nem'?></td></tr>
            <?php endforeach; ?>
          </tbody>
	    --->

<tbody>
<?php foreach($rows as $r): ?>
  <tr>
    <td><?=h($r['name'])?></td>
    <td><?=h($r['email'])?></td>
    <td><?=h($r['role_name'])?></td>
    <td><?=$r['is_active']?'igen':'nem'?></td>
    <td class="d-flex gap-1">
      <?php if ($r['id'] != current_user()['id']): ?>
        <form method="post" action="actions/admin_user_toggle_active.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="id" value="<?=$r['id']?>">
          <?php if($r['is_active']): ?>
            <button class="btn btn-sm btn-warning">Inaktivál</button>
          <?php else: ?>
            <button class="btn btn-sm btn-success">Aktivál</button>
          <?php endif; ?>
        </form>

        <form method="post" action="actions/admin_user_delete.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd? Csak akkor lehetséges, ha nincs hivatkozás a felhasználóra.');">
          <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="id" value="<?=$r['id']?>">
          <button class="btn btn-sm btn-danger">Törlés</button>
        </form>


<!-- Jelszó módosító űrlap -->
  <form method="post" action="actions/admin_user_change_password.php" class="d-inline ms-1">
    <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
    <input type="hidden" name="id" value="<?=$r['id']?>">
    <input type="password" name="new_password" class="form-control form-control-sm d-inline-block" placeholder="Új jelszó" style="width:140px" required>
    <button class="btn btn-sm btn-outline-primary ms-1">Jelszó változtatása</button>
  </form>



      <?php else: ?>
        <span class="text-muted small">(Saját fiók)</span>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</tbody>
    


        </table>
      </div></div>
    </div>
  </div>
</div>
</main>
<footer>
© Perfect Phone Munka Nyilvántartó
</footer>

</body></html>

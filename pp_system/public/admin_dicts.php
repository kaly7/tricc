<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect(); if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';
require_once __DIR__.'/../src/helpers.php';
$u = current_user();


$statuses = db()->query('SELECT id,name,color_hex FROM pp_status ORDER BY name')->fetchAll();
$cities   = db()->query('SELECT id,name FROM cities ORDER BY name')->fetchAll();
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Törzsek</title>
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
  <div class="row g-3">
    <div class="col-md-6">
      <div class="card"><div class="card-header">PP státuszok</div><div class="card-body">
        <form class="row g-2 align-items-center mb-3" method="post" action="actions/status_create.php">
          <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
          <div class="col-6"><input class="form-control" name="name" placeholder="Megnevezés" required></div>
          <div class="col-4">
            <input type="color" class="form-control form-control-color w-100" name="color_hex" value="#E3F2FD">
          </div>
          <div class="col-2"><button class="btn btn-success w-100">Hozzáad</button></div>
        </form>
        <?php foreach($statuses as $s): ?>
          <form class="row g-2 align-items-center mb-2" method="post" action="actions/status_update.php">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="id" value="<?=$s['id']?>">
            <div class="col-6"><input class="form-control" name="name" value="<?=h($s['name'])?>"></div>
            <div class="col-4">
              <input type="color" class="form-control form-control-color w-100" name="color_hex" value="<?=h($s['color_hex'])?>">
            </div>
            <div class="col-2"><button class="btn btn-primary w-100">Mentés</button></div>
          </form>
        <?php endforeach; if(!$statuses): ?><em>Nincs státusz</em><?php endif; ?>
      </div></div>
    </div>
    <div class="col-md-6">

<?php
$city_err = $_GET['city_err'] ?? '';
$city_msg = $_GET['city_msg'] ?? '';
$dup_name = $_GET['name'] ?? '';
?>

<?php if ($city_msg === 'created'): ?>
  <div class="alert alert-success">✅ Település hozzáadva.</div>
<?php elseif ($city_msg === 'deleted'): ?>
  <div class="alert alert-success">✅ Település törölve.</div>
<?php endif; ?>

<?php if ($city_err === 'dup'): ?>
  <div class="alert alert-danger">❌ Már létezik ilyen település: <strong><?=h($dup_name)?></strong></div>
<?php elseif ($city_err === 'inuse'): ?>
  <div class="alert alert-danger">❌ Nem törölhető: a település használatban van tételeknél.</div>
<?php elseif ($city_err === 'empty'): ?>
  <div class="alert alert-warning">⚠ Kérlek, adj meg egy településnevet.</div>
<?php elseif ($city_err === 'bad'): ?>
  <div class="alert alert-danger">❌ Hibás kérés.</div>
<?php elseif ($city_err === 'unknown'): ?>
  <div class="alert alert-danger">❌ Ismeretlen hiba történt.</div>
<?php endif; ?>

      <div class="card"><div class="card-header">Városok</div><div class="card-body">

        <form class="row g-2 align-items-center mb-3" method="post" action="actions/city_create.php">
          <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
          <div class="col-8"><input class="form-control" name="name" placeholder="Város neve" required></div>
          <div class="col-4"><button class="btn btn-success w-100">Hozzáad</button></div>
        </form>



<?php foreach($cities as $c): ?>
  <div class="row g-2 align-items-center mb-2">
    <!-- MENTÉS FORM (saját form) -->
    <div class="col-8">
      <form method="post" action="actions/city_update.php" class="d-flex gap-2">
        <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="id" value="<?=$c['id']?>">
        <input class="form-control" name="name" value="<?=h($c['name'])?>">
        <button class="btn btn-primary">Mentés</button>
      </form>
    </div>

    <!-- TÖRLÉS FORM (külön form) -->
    <div class="col-4">
      <form method="post" action="actions/city_delete.php"
            onsubmit="return confirm('Biztosan törlöd? Csak akkor lehet, ha nincs használatban.');"
            class="d-inline">
        <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="id" value="<?=$c['id']?>">
        <button class="btn btn-danger">Törlés</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>



      </div></div>
    </div>
  </div>
</div>
</main>
<footer>
© Perfect Phone Munka Nyilvántartó
</footer>

</body></html>

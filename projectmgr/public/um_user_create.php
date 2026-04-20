<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers; use App\Csrf;

Auth::start(); Middleware::requireAuth(); Auth::requireRole(1);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $pdo = Db::pdo();
  $st = $pdo->prepare('INSERT INTO users (name,email,password_hash,role_id,is_active) VALUES (?,?,?,?,?)');
  $st->execute([
    trim($_POST['name']??''),
    trim($_POST['email']??''),
    password_hash($_POST['password']??'', PASSWORD_DEFAULT),
    (int)($_POST['role_id'] ?? 2),
    (int)($_POST['is_active'] ?? 1),
  ]);
  Helpers::flash('ok','Felhasználó létrehozva');
  header('Location: /um_users.php'); exit;
}
?>
<div class="card p-4">
  <h1 class="h5 mb-3">Új felhasználó (UM)</h1>
  <form method="post">
    <?= Csrf::field() ?>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Név</label>
        <input name="name" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">E-mail</label>
        <input name="email" type="email" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Jelszó</label>
        <input name="password" type="password" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Szerep</label>
        <select name="role_id" class="form-select">
          <option value="1">admin</option>
          <option value="2">user</option>
          <option value="3">viewer</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Aktív</label>
        <select name="is_active" class="form-select">
          <option value="1">Igen</option>
          <option value="0">Nem</option>
        </select>
      </div>
    </div>
    <div class="mt-3">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-secondary" href="/um_users.php">Mégse</a>
    </div>
  </form>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';

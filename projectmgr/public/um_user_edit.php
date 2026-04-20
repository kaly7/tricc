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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
  $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
} else {
  $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
}
if (!$id) { http_response_code(400); exit('Hibás vagy hiányzó ID'); }

$pdo = Db::pdo();

if ($method === 'POST') {
  if (!Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $role_id = (int)($_POST['role_id'] ?? 2);
  $is_active = (int)($_POST['is_active'] ?? 1);
  $pwd = trim($_POST['password'] ?? '');

  if ($pwd !== '') {
    $st = $pdo->prepare('UPDATE users SET name=?, email=?, password_hash=?, role_id=?, is_active=? WHERE id=?');
    $st->execute([$name,$email,password_hash($pwd,PASSWORD_DEFAULT),$role_id,$is_active,$id]);
  } else {
    $st = $pdo->prepare('UPDATE users SET name=?, email=?, role_id=?, is_active=? WHERE id=?');
    $st->execute([$name,$email,$role_id,$is_active,$id]);
  }
  Helpers::flash('ok','Módosítva');
  header('Location: /um_users.php'); exit;
}

$st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$st->bindValue(1, $id, PDO::PARAM_INT);
$st->execute();
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { http_response_code(404); exit('Nincs ilyen felhasználó'); }
$active = (int)($u['is_active'] ?? 1);
?>
<div class="card p-4">
  <h1 class="h5 mb-3">Felhasználó szerkesztése (UM)</h1>
  <form method="post" action="/um_user_edit.php?id=<?= (int)$u['id'] ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Név</label>
        <input name="name" class="form-control" value="<?=htmlspecialchars($u['name'])?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">E-mail</label>
        <input name="email" type="email" class="form-control" value="<?=htmlspecialchars($u['email'])?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Új jelszó (opcionális)</label>
        <input name="password" type="password" class="form-control" placeholder="Ha nem változik, hagyd üresen">
      </div>
      <div class="col-md-3">
        <label class="form-label">Szerep</label>
        <select name="role_id" class="form-select">
          <?php foreach ([1=>'admin',2=>'user',3=>'viewer'] as $rid=>$rn): ?>
            <option value="<?=$rid?>" <?=$rid==(int)$u['role_id']?'selected':''?>><?=$rn?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Aktív</label>
        <select name="is_active" class="form-select">
          <option value="1" <?= $active===1 ? 'selected' : '' ?>>Igen</option>
          <option value="0" <?= $active===0 ? 'selected' : '' ?>>Nem</option>
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

<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/UserService.php';
require dirname(__DIR__).'/app/Logger.php';

use App\Auth; use App\Middleware; use App\Helpers; use App\Csrf; use App\UserService; use App\Logger;

Auth::start(); Middleware::requireAuth(); Auth::requireRole(1);

// ID felvétel KÉRÉS TÍPUSA szerint, szigorúan validálva
$editId = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $editId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
} else {
  $editId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
}

Logger::write('user_edit enter method='.$_SERVER['REQUEST_METHOD'].' GET_id='.(string)($_GET['id'] ?? '').' POST_id='.(string)($_POST['id'] ?? '').' parsed_id='.(string)$editId);

if (!$editId) { http_response_code(400); exit('Hiányzó vagy hibás azonosító'); }

// Mentés
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  try {
    $pwd = trim($_POST['password'] ?? '');
    UserService::update(
      $editId,
      trim($_POST['name'] ?? ''),
      trim($_POST['email'] ?? ''),
      $pwd?:null,
      (int)($_POST['role_id'] ?? 2),
      (int)($_POST['is_active'] ?? 1)
    );
    Helpers::flash('ok','Módosítva');
    header('Location: /users.php'); exit;
  } catch (\Throwable $e) {
    Helpers::flash('err','Hiba mentés közben: '.$e->getMessage());
    Logger::write('user_edit save error: '.$e->getMessage());
  }
}

// Betöltés
$u = UserService::find($editId);
if (!$u) { http_response_code(404); exit('Nincs ilyen felhasználó'); }
$active = isset($u['is_active']) ? (int)$u['is_active'] : 1;

require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';
?>
<div class="card p-4">
  <h1 class="h5 mb-3">Felhasználó szerkesztése</h1>
  <form method="post" action="/user_edit.php?id=<?= (int)$u['id'] ?>">
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
      <a class="btn btn-secondary" href="/users.php">Mégse</a>
    </div>
  </form>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';

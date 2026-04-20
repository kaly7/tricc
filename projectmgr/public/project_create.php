<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/ProjectService.php';

use App\Auth; use App\Middleware; use App\Helpers; use App\Csrf; use App\ProjectService;

Auth::start(); Middleware::requireAuth();

$cfg = require dirname(__DIR__).'/config/config.php';
$me  = Auth::user();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $code = preg_replace('~[^A-Za-z0-9\-_]~','', trim($_POST['code'] ?? ''));
  $name = trim($_POST['name'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  if (!$code || !$name) { Helpers::flash('err','Kód és Név kötelező'); header('Location: /project_create.php'); exit; }
  ProjectService::create((int)$me['id'], $code, $name, $desc, $cfg['upload_root']);
  Helpers::flash('ok','Projekt létrehozva és könyvtárszerkezet elkészítve');
  header('Location: /projects.php'); exit;
}

require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';
?>
<div class="card p-4">
  <h1 class="h5 mb-3">Új projekt</h1>
  <form method="post">
    <?= Csrf::field() ?>
    <div class="mb-3">
      <label class="form-label">Projekt kód (pl. PROJ-2025-001)</label>
      <input name="code" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Név</label>
      <input name="name" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Leírás</label>
      <textarea name="description" class="form-control" rows="3"></textarea>
    </div>
    <button class="btn btn-primary">Létrehozás</button>
    <a class="btn btn-secondary" href="/projects.php">Mégse</a>
  </form>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';

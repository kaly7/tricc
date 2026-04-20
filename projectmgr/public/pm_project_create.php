<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers;

Auth::start(); Middleware::requireAuth();

$cfg = require dirname(__DIR__).'/config/config.php';
$me = Auth::user();
$pdo = Db::pdo();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $number = preg_replace('~[^A-Za-z0-9\-_]~','', trim($_POST['number'] ?? ''));
  $name   = trim($_POST['name'] ?? '');
  $desc   = trim($_POST['description'] ?? '');
  $start  = trim($_POST['start_date'] ?? '') ?: null;

  if (!$number || !$name) { \App\Helpers::flash('err','Projekt szám és név kötelező'); header('Location: /pm_project_create.php'); exit; }

  $rootRel = $number;
  $uploadRoot = rtrim($cfg['upload_root'],'/');
  $absRoot = $uploadRoot.'/'.$rootRel;

  // Create project row (keep code == number for backward-compat)
  $st = $pdo->prepare('INSERT INTO projects (number, code, name, description, start_date, root_dir, owner_user_id) VALUES (?,?,?,?,?,?,?)');
  $st->execute([$number, $number, $name, $desc, $start, $rootRel, (int)$me['id']]);
  $pid = (int)$pdo->lastInsertId();

  // Build directories from per-project overrides if any, otherwise from global template
  $tpl = $pdo->prepare('SELECT path FROM project_dir_templates WHERE project_id=? ORDER BY sort, path');
  $tpl->execute([$pid]);
  $dirs = $tpl->fetchAll(PDO::FETCH_COLUMN);
  if (!$dirs) {
    $dirs = $pdo->query('SELECT path FROM dir_templates ORDER BY sort, path')->fetchAll(PDO::FETCH_COLUMN);
  }
  if (!is_dir($absRoot)) {
    @mkdir($absRoot, 0775, true);
  }
  foreach ($dirs as $d) {
    $p = $absRoot.'/'.preg_replace("~[\\/]+~", "/", $d);
    @mkdir($p, 0775, true);
  }

  \App\Helpers::flash('ok','Projekt létrehozva és könyvtárak elkészítve');
  header('Location: /pm_projects.php'); exit;
}
?>
<div class="card p-4">
  <h1 class="h5 mb-3">Új projekt</h1>
  <form method="post">
    <?= \App\Csrf::field() ?>
    <div class="mb-3">
      <label class="form-label">Projekt száma (pl. PROJ-2025-001)</label>
      <input name="number" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Név</label>
      <input name="name" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Indítás dátuma</label>
      <input name="start_date" type="date" class="form-control">
    </div>
    <div class="mb-3">
      <label class="form-label">Leírás</label>
      <textarea name="description" class="form-control" rows="3"></textarea>
    </div>
    <button class="btn btn-primary">Létrehozás</button>
    <a class="btn btn-secondary" href="/pm_projects.php">Mégse</a>
  </form>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';

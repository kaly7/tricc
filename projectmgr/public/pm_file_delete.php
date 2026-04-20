<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/app/Activity.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers; use App\Activity;

Auth::start(); Middleware::requireAuth();
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Method Not Allowed'); }
if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }

$project_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$rel = trim($_POST['dir'] ?? '', '/');
$name = basename($_POST['name'] ?? '');
if (!$project_id || $name==='') { http_response_code(400); exit('Hibás paraméter'); }

$pdo = Db::pdo();
// project
$st = $pdo->prepare('SELECT root_dir FROM projects WHERE id=?');
$st->execute([$project_id]);
$rootRel = $st->fetchColumn();
if (!$rootRel) { http_response_code(404); exit('Projekt nem található'); }

$cfg = require dirname(__DIR__).'/config/config.php';
$uploadRoot = rtrim($cfg['upload_root'],'/');
$absRoot = $uploadRoot.'/'.$rootRel;
$absDir = rtrim($absRoot.($rel?'/'.$rel:''),'/');
$absFile = $absDir.'/'.$name;
if (strpos(realpath($absFile) ?: $absFile, realpath($absRoot) ?: $absRoot) !== 0) {
  http_response_code(400); exit('Érvénytelen elérési út');
}
if (is_file($absFile)) { @unlink($absFile); }

// delete meta row if exists
$del = $pdo->prepare('DELETE FROM project_files WHERE project_id=? AND rel_dir=? AND filename=?');
$del->execute([$project_id, $rel, $name]);

Activity::log($project_id, (int)Auth::user()['id'], 'file.delete', ['dir'=>$rel,'name'=>$name]);

Helpers::flash('ok','Fájl törölve');
header('Location: /pm_files.php?id='.$project_id.'&dir='.urlencode($rel));

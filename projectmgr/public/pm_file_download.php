<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();

$project_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$rel = trim($_GET['dir'] ?? '', '/');
$name = basename($_GET['name'] ?? '');
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
if (!is_file($absFile)) { http_response_code(404); exit('Fájl nem található'); }

$mime = mime_content_type($absFile) ?: 'application/octet-stream';
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($absFile));
header('Content-Disposition: attachment; filename="'.basename($absFile).'"');
readfile($absFile);

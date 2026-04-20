<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Activity.php';

use App\Auth; use App\Middleware; use App\Db; use App\Activity;

header('Content-Type: application/json; charset=utf-8');
Auth::start(); Middleware::requireAuth();
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['error'=>'Method Not Allowed']); exit; }
if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); echo json_encode(['error'=>'CSRF hiba']); exit; }

$pdo = Db::pdo();

$project_id = (int)($_POST['project_id'] ?? 0);
$relSafe = trim((string)($_POST['dir'] ?? ''), '/');
$desc = trim((string)($_POST['description'] ?? ''));
if ($project_id<=0) { http_response_code(400); echo json_encode(['error'=>'Hibás projekt']); exit; }

// validate project
$st = $pdo->prepare('SELECT * FROM projects WHERE id=?');
$st->execute([$project_id]);
$proj = $st->fetch(PDO::FETCH_ASSOC);
if (!$proj) { http_response_code(404); echo json_encode(['error'=>'Projekt nem található']); exit; }

$cfg = require dirname(__DIR__).'/config/config.php';
$uploadRoot = rtrim($cfg['upload_root'],'/');

$absRoot = $uploadRoot.'/'.$proj['root_dir'];
$absDir = rtrim($absRoot . ($relSafe ? '/'.$relSafe : ''), '/');

// ensure path inside project root
$base = realpath($absRoot) ?: $absRoot;
$target = realpath($absDir) ?: $absDir;
if (strpos($target, $base) !== 0) { $relSafe=''; $absDir=$absRoot; }

if (!is_dir($absDir)) { @mkdir($absDir, 0775, true); }

if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) {
  $err = 'Feltöltési hiba';
  $code = $_FILES['file']['error'] ?? 0;
  if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) $err = 'A fájl túl nagy (PHP limit)';
  http_response_code(400);
  echo json_encode(['error'=>$err]); exit;
}

$orig = $_FILES['file']['name'];
$name = basename($orig);
$tmp  = $_FILES['file']['tmp_name'];
$size = (int)$_FILES['file']['size'];
$mime = $_FILES['file']['type'] ?? null;

$dest = $absDir.'/'.$name;
if (!move_uploaded_file($tmp, $dest)) {
  http_response_code(500); echo json_encode(['error'=>'Mentési hiba']); exit;
}

// save to DB
$st = $pdo->prepare('INSERT INTO project_files (project_id, rel_dir, filename, mime, size, description, uploaded_by) VALUES (?,?,?,?,?,?,?)');
$st->execute([$project_id, $relSafe, $name, $mime, $size, $desc, (int)Auth::user()['id']]);
Activity::log($project_id, (int)Auth::user()['id'], 'file.upload', ['dir'=>$relSafe,'name'=>$name,'size'=>$size]);

echo json_encode(['ok'=>true, 'redirect'=>'/pm_files.php?id='.$project_id.'&dir='.urlencode($relSafe)], JSON_UNESCAPED_UNICODE);

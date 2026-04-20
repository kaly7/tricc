<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Activity.php';

use App\Auth; use App\Middleware; use App\Db; use App\Activity;

Auth::start(); Middleware::requireAuth();
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Method Not Allowed'); }
if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }

$pdo = Db::pdo();

$project_id = (int)($_POST['project_id'] ?? 0);
$op = $_POST['op'] ?? '';
$cur = trim((string)($_POST['cur'] ?? ''), '/');
$name = trim((string)($_POST['name'] ?? ''));
$newname = trim((string)($_POST['newname'] ?? ''));

$validOps = ['mkdir','rmdir','rename'];
if ($project_id<=0 || !in_array($op,$validOps,true)) { http_response_code(400); exit('Hibás kérés'); }

// project
$st = $pdo->prepare('SELECT * FROM projects WHERE id=?');
$st->execute([$project_id]);
$proj = $st->fetch(PDO::FETCH_ASSOC);
if (!$proj) { http_response_code(404); exit('Projekt nem található'); }

$cfg = require dirname(__DIR__).'/config/config.php';
$root = rtrim($cfg['upload_root'],'/').'/'.$proj['root_dir'];
$base = realpath($root) ?: $root;

function sanitize_part($s){
  $s = trim($s);
  if ($s === '' ) return '';
  if (strpos($s,'/')!==false || strpos($s,'\\')!==false) return '';
  if ($s === '.' || $s === '..') return '';
  if (!preg_match('~^[A-Za-z0-9 _.\-]{1,128}$~', $s)) return '';
  return $s;
}

$curRel = $cur;
$absCur = rtrim($base . ($curRel ? '/'.$curRel : ''), '/');
$absCurReal = realpath($absCur) ?: $absCur;
if (strpos($absCurReal, $base)!==0) { http_response_code(400); exit('Érvénytelen útvonal'); }

if ($op === 'mkdir') {
  $part = sanitize_part($name);
  if ($part==='') { http_response_code(422); exit('Érvénytelen könyvtárnév'); }
  $target = $absCurReal.'/'.$part;
  if (file_exists($target)) { http_response_code(409); exit('Már létezik ilyen könyvtár/fájl'); }
  if (!@mkdir($target, 0775, false)) { http_response_code(500); exit('Könyvtár létrehozás hiba'); }
  Activity::log($project_id, (int)Auth::user()['id'], 'dir.mkdir', ['cur'=>$curRel, 'name'=>$part]);
  header('Location: /pm_files.php?id='.$project_id.'&dir='.urlencode(trim($curRel.'/'.$part,'/')));
  exit;
}

if ($op === 'rmdir') {
  if ($curRel === '') { http_response_code(400); exit('A gyökér nem törölhető'); }
  $isEmpty = true;
  if ($handle = @opendir($absCurReal)) {
    while (($entry = readdir($handle)) !== false) {
      if ($entry==='.' || $entry==='..') continue;
      $isEmpty = false; break;
    }
    closedir($handle);
  }
  if (!$isEmpty) { http_response_code(409); exit('A könyvtár nem üres'); }
  if (!@rmdir($absCurReal)) { http_response_code(500); exit('Könyvtár törlés hiba'); }
  Activity::log($project_id, (int)Auth::user()['id'], 'dir.rmdir', ['cur'=>$curRel]);
  $parent = trim(dirname('/'.$curRel), '/');
  header('Location: /pm_files.php?id='.$project_id.'&dir='.urlencode($parent==='.'?'':$parent));
  exit;
}

if ($op === 'rename') {
  if ($curRel === '') { http_response_code(400); exit('A gyökér nem nevezhető át'); }
  $part = sanitize_part($newname);
  if ($part==='') { http_response_code(422); exit('Érvénytelen új név'); }
  $parent = trim(dirname('/'.$curRel), '/');
  $absParent = rtrim($base . ($parent ? '/'.$parent : ''), '/');
  $target = $absParent.'/'.$part;
  if (file_exists($target)) { http_response_code(409); exit('A cél már létezik'); }
  if (!@rename($absCurReal, $target)) { http_response_code(500); exit('Átnevezés hiba'); }
  Activity::log($project_id, (int)Auth::user()['id'], 'dir.rename', ['old'=>$curRel, 'new'=>trim($parent.'/'.$part,'/')]);
  header('Location: /pm_files.php?id='.$project_id.'&dir='.urlencode(trim($parent.'/'.$part,'/')));
  exit;
}

http_response_code(400); exit('Ismeretlen művelet');

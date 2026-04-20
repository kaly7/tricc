<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
use App\Db; use App\Auth; use App\Middleware;

Auth::start(); Middleware::requireAuth();

$vehicle_id = (int)($_GET['vehicle_id'] ?? 0);
$img_id     = (int)($_GET['img_id'] ?? 0);
if ($vehicle_id<=0 || $img_id<=0) { http_response_code(400); exit; }

$pdo = Db::pdo();
$st = $pdo->prepare("SELECT file_path, mime FROM vehicle_images WHERE id=? AND vehicle_id=?");
$st->execute([$img_id,$vehicle_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if(!$row){ http_response_code(404); exit; }

$abs = dirname(__DIR__).'/'.(string)$row['file_path'];
if(!is_file($abs)){ http_response_code(404); exit; }

$mime = (string)($row['mime'] ?? '');

// Ha nincs GD, adjuk vissza a nyerset
if (!function_exists('imagecreatefromjpeg')) {
  header('Content-Type: '.$mime);
  readfile($abs);
  exit;
}

// Csak jpeg/png/webp
$img = null;
if ($mime==='image/jpeg') $img = @imagecreatefromjpeg($abs);
elseif ($mime==='image/png') $img = @imagecreatefrompng($abs);
elseif ($mime==='image/webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($abs);

if(!$img){
  header('Content-Type: '.$mime);
  readfile($abs);
  exit;
}

$w = imagesx($img); $h = imagesy($img);
$tw = 480; // thumb width
$th = (int)max(1, round($h * ($tw / max(1,$w))));
$thumb = imagecreatetruecolor($tw, $th);

// háttér fehér
$white = imagecolorallocate($thumb, 255,255,255);
imagefill($thumb, 0,0, $white);

imagecopyresampled($thumb, $img, 0,0,0,0, $tw,$th, $w,$h);

header('Content-Type: image/jpeg');
imagejpeg($thumb, null, 80);

imagedestroy($thumb);
imagedestroy($img);
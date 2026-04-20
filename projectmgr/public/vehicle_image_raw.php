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
$st = $pdo->prepare("SELECT file_path, mime, orig_name FROM vehicle_images WHERE id=? AND vehicle_id=?");
$st->execute([$img_id,$vehicle_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if(!$row){ http_response_code(404); exit; }

$abs = dirname(__DIR__).'/'.(string)$row['file_path'];
if(!is_file($abs)){ http_response_code(404); exit; }

header('Content-Type: '.($row['mime'] ?: 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="'.basename((string)$row['orig_name']).'"');
readfile($abs);
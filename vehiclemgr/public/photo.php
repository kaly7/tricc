<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();

$filename = basename((string)($_GET['f'] ?? ''));
$thumb    = isset($_GET['thumb']);

if (!preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|webp|heic|heif)$/i', $filename)) {
  http_response_code(404); exit;
}

$path = __DIR__ . '/../storage/photos/' . $filename;
if (!is_file($path)) { http_response_code(404); exit; }

$ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime = match($ext) { 'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg' };

if ($thumb && function_exists('imagecreatefromjpeg')) {
  // Egyszerű thumbnail (max 200x200)
  $src = match($ext) {
    'png'  => @imagecreatefrompng($path),
    'webp' => @imagecreatefromwebp($path),
    default => @imagecreatefromjpeg($path),
  };
  if ($src) {
    $w = imagesx($src); $h = imagesy($src);
    $max = 200;
    if ($w > $max || $h > $max) {
      $ratio = min($max/$w, $max/$h);
      $nw = (int)round($w * $ratio);
      $nh = (int)round($h * $ratio);
      $dst = imagecreatetruecolor($nw, $nh);
      imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
      imagedestroy($src);
      $src = $dst;
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    match($ext) { 'png' => imagepng($src), 'webp' => imagewebp($src), default => imagejpeg($src, null, 85) };
    imagedestroy($src);
    exit;
  }
}

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
readfile($path);

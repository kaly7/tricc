<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
use App\Db; use App\Auth; use App\Middleware;

Auth::start(); Middleware::requireAuth();
$u = Auth::user();
if (!$u || (int)$u['role_id']!==1) { http_response_code(403); exit('Nincs jogosultság'); }

$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
if ($vehicle_id<=0) { http_response_code(400); exit('Hiányzó jármű'); }

if (empty($_FILES['files']['tmp_name'])) { header('Location: /vehicle.php?id='.$vehicle_id); exit; }

$allowed = ['image/jpeg','image/png','image/webp'];
$baseDir = dirname(__DIR__).'/storage/vehicles/images/'.$vehicle_id;
if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);

$pdo = Db::pdo();

foreach ($_FILES['files']['tmp_name'] as $i=>$tmp) {
  if (!is_uploaded_file($tmp)) continue;

  $mime = mime_content_type($tmp) ?: '';
  if (!in_array($mime, $allowed, true)) continue;

  $orig = (string)($_FILES['files']['name'][$i] ?? 'image');
  $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  $ext  = preg_replace('/[^a-z0-9]+/i','', $ext);

  $fname = bin2hex(random_bytes(12)).($ext?'.'.$ext:'');
  $abs   = $baseDir.'/'.$fname;

  if (!move_uploaded_file($tmp, $abs)) continue;

  $rel = 'storage/vehicles/images/'.$vehicle_id.'/'.$fname;
  $st = $pdo->prepare("INSERT INTO vehicle_images
    (vehicle_id, file_path, orig_name, mime, size, created_by)
    VALUES (?,?,?,?,?,?)");
  $st->execute([$vehicle_id, $rel, $orig, $mime, filesize($abs), (int)$u['id']]);

  // Audit log
  try {
    $imgId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO audit_log (user_id, entity_type, entity_id, action, changed_fields)
                   VALUES (?,?,?,?,?)")
        ->execute([
          (int)$u['id'],
          'vehicle_image',
          $imgId,
          'upload',
          json_encode([
            'vehicle_id' => $vehicle_id,
            'file'       => $orig,
            'mime'       => $mime,
            'size'       => filesize($abs),
          ], JSON_UNESCAPED_UNICODE)
        ]);
  } catch (Throwable $e) {
    // never block upload on audit issues
  }
}

header('Location: /vehicle.php?id='.$vehicle_id);
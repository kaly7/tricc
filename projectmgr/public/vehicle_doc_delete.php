<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
use App\Db; use App\Auth; use App\Middleware;

Auth::start(); Middleware::requireAuth();
$u = Auth::user();
if (!$u || (int)$u['role_id']!==1) { http_response_code(403); exit('Nincs jogosultság'); }

$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
$doc_id = (int)($_POST['doc_id'] ?? 0);
if ($vehicle_id<=0 || $doc_id<=0) { header('Location: /vehicle.php?id='.$vehicle_id); exit; }

$pdo = Db::pdo();
$st = $pdo->prepare("SELECT file_path, orig_name, mime, size FROM vehicle_documents WHERE id=? AND vehicle_id=? AND doc_type='registration'");
$st->execute([$doc_id,$vehicle_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if ($row) {
  // Audit log (delete)
  try {
    $pdo->prepare("INSERT INTO audit_log (user_id, entity_type, entity_id, action, changed_fields)
                   VALUES (?,?,?,?,?)")
        ->execute([
          (int)$u['id'],
          'vehicle_doc_registration',
          $doc_id,
          'delete',
          json_encode([
            'vehicle_id' => $vehicle_id,
            'file'       => $row['orig_name'] ?? '',
            'mime'       => $row['mime'] ?? '',
            'size'       => $row['size'] ?? null,
          ], JSON_UNESCAPED_UNICODE)
        ]);
  } catch (Throwable $e) {
    // never block delete on audit issues
  }

  $abs = dirname(__DIR__).'/'.(string)$row['file_path'];
  if (is_file($abs)) @unlink($abs);
  $pdo->prepare("DELETE FROM vehicle_documents WHERE id=?")->execute([$doc_id]);
}
header('Location: /vehicle.php?id='.$vehicle_id);
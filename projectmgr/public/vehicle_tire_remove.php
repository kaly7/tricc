<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();
$u = Auth::user();
$isAdmin = isset($u['role_id']) && (int)$u['role_id']===1;
if (!$isAdmin) { http_response_code(403); exit('Nincs jogosultság.'); }

if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Method not allowed'); }
if (!\App\Csrf::check($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('CSRF hiba.'); }

$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
$installation_id = (int)($_POST['installation_id'] ?? 0);
$removed_date = trim((string)($_POST['removed_date'] ?? ''));
$removed_km = (int)($_POST['removed_km'] ?? 0);
$archive_tire = isset($_POST['archive_tire']) ? 1 : 0;
$storage_loc_id = ($_POST['removed_storage_location_id'] ?? '')==='' ? null : (int)$_POST['removed_storage_location_id'];

if ($vehicle_id<=0 || $installation_id<=0 || $removed_date==='' || $removed_km<0) {
  Helpers::flash('danger','Hiányzó mezők.');
  header('Location: /vehicle.php?id='.$vehicle_id.'#tab_tires'); exit;
}
$d = date_create($removed_date);
if (!$d) {
  Helpers::flash('danger','Hibás dátum.');
  header('Location: /vehicle.php?id='.$vehicle_id.'#tab_tires'); exit;
}
$removed_date_sql = $d->format('Y-m-d');

try {
  $pdo->beginTransaction();
  $st = $pdo->prepare("SELECT tire_id FROM vehicle_tire_installations WHERE id=? AND vehicle_id=? AND removed_date IS NULL");
  $st->execute([$installation_id, $vehicle_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new Exception('Nem található aktív felhelyezés.');

  $pdo->prepare("UPDATE vehicle_tire_installations SET removed_date=?, removed_km=?, removed_storage_location_id=? WHERE id=?")
      ->execute([$removed_date_sql, $removed_km, $storage_loc_id, $installation_id]);

  if ($archive_tire) {
    $pdo->prepare("UPDATE vehicle_tires SET is_archived=1 WHERE id=? AND vehicle_id=?")->execute([(int)$row['tire_id'], $vehicle_id]);
  }

  $pdo->prepare("UPDATE vehicles SET odometer_km = GREATEST(odometer_km, ?) WHERE id=?")->execute([$removed_km, $vehicle_id]);

  try {
    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, changed_fields, user_id, created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute(['vehicle_tire_install', $installation_id, 'update',
                   json_encode(['vehicle_id'=>$vehicle_id,'removed_date'=>$removed_date_sql,'removed_km'=>$removed_km,'archive_tire'=>$archive_tire,'storage_location_id'=>$storage_loc_id], JSON_UNESCAPED_UNICODE),
                   (int)$u['id']]);
  } catch (Throwable $e) {}

  $pdo->commit();
  Helpers::flash('success','Levétel mentve.');
} catch (Throwable $e) {
  $pdo->rollBack();
  Helpers::flash('danger','Hiba: '.$e->getMessage());
}

header('Location: /vehicle.php?id='.$vehicle_id.'#tab_tires');

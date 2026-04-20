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
$axle_no = (int)($_POST['axle_no'] ?? 0);
$position_no = (int)($_POST['position_no'] ?? 0);
$tire_id = (int)($_POST['tire_id'] ?? 0);
$installed_date = trim((string)($_POST['installed_date'] ?? ''));
$installed_km = (int)($_POST['installed_km'] ?? 0);
$archive_old = isset($_POST['archive_old']) ? 1 : 0;

if ($vehicle_id<=0 || $axle_no<=0 || $position_no<=0 || $tire_id<=0 || $installed_date==='' || $installed_km<0) {
  Helpers::flash('danger','Hiányzó mezők.');
  header('Location: /vehicle.php?id='.$vehicle_id.'#tab_tires'); exit;
}

$d = date_create($installed_date);
if (!$d) {
  Helpers::flash('danger','Hibás dátum.');
  header('Location: /vehicle.php?id='.$vehicle_id.'#tab_tires'); exit;
}
$installed_date_sql = $d->format('Y-m-d');

try {
  $pdo->beginTransaction();

  $ax = $pdo->prepare("SELECT wheels_count FROM vehicle_axles WHERE vehicle_id=? AND axle_no=?");
  $ax->execute([$vehicle_id, $axle_no]);
  $axr = $ax->fetch(PDO::FETCH_ASSOC);
  if (!$axr) throw new Exception('Nincs ilyen tengely konfiguráció.');
  $maxPos = ((int)$axr['wheels_count']===4) ? 4 : 2;
  if ($position_no > $maxPos) throw new Exception('Érvénytelen pozíció a tengelyen.');

  $cur = $pdo->prepare("SELECT id, tire_id FROM vehicle_tire_installations
                        WHERE vehicle_id=? AND axle_no=? AND position_no=? AND removed_date IS NULL
                        ORDER BY id DESC LIMIT 1");
  $cur->execute([$vehicle_id, $axle_no, $position_no]);
  $c = $cur->fetch(PDO::FETCH_ASSOC);
  if ($c) {
    $pdo->prepare("UPDATE vehicle_tire_installations SET removed_date=?, removed_km=? WHERE id=?")
        ->execute([$installed_date_sql, $installed_km, (int)$c['id']]);

    if ($archive_old) {
      $pdo->prepare("UPDATE vehicle_tires SET is_archived=1 WHERE id=? AND vehicle_id=?")
          ->execute([(int)$c['tire_id'], $vehicle_id]);
    }
  }

  $pdo->prepare("INSERT INTO vehicle_tire_installations (vehicle_id, axle_no, position_no, tire_id, installed_date, installed_km, created_by)
                 VALUES (?,?,?,?,?,?,?)")
      ->execute([$vehicle_id, $axle_no, $position_no, $tire_id, $installed_date_sql, $installed_km, (int)$u['id']]);
  $iid = (int)$pdo->lastInsertId();

  $pdo->prepare("UPDATE vehicles SET odometer_km = GREATEST(odometer_km, ?) WHERE id=?")->execute([$installed_km, $vehicle_id]);

  try {
    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, changed_fields, user_id, created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute(['vehicle_tire_install', $iid, 'create',
                   json_encode(['vehicle_id'=>$vehicle_id,'axle_no'=>$axle_no,'position_no'=>$position_no,'tire_id'=>$tire_id,'installed_date'=>$installed_date_sql,'installed_km'=>$installed_km,'archive_old'=>$archive_old], JSON_UNESCAPED_UNICODE),
                   (int)$u['id']]);
  } catch (Throwable $e) {}

  $pdo->commit();
  Helpers::flash('success','Gumi felhelyezve / cserélve.');
} catch (Throwable $e) {
  $pdo->rollBack();
  Helpers::flash('danger','Hiba: '.$e->getMessage());
}

header('Location: /vehicle.php?id='.$vehicle_id.'#tab_tires');

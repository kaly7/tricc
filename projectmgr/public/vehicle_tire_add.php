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
$tire_kind = (string)($_POST['tire_kind'] ?? 'general');
$brand = trim((string)($_POST['brand'] ?? ''));
$tire_model = trim((string)($_POST['tire_model'] ?? ''));
$tire_size = trim((string)($_POST['tire_size'] ?? ''));
$dot_code = trim((string)($_POST['dot_code'] ?? ''));
$purchased_date = trim((string)($_POST['purchased_date'] ?? ''));
$purchased_km = ($_POST['purchased_km'] ?? '')!=='' ? (int)$_POST['purchased_km'] : null;
$notes = trim((string)($_POST['notes'] ?? ''));

$kinds = ['winter','summer','allseason','general'];
if (!in_array($tire_kind, $kinds, true)) $tire_kind = 'general';

$pd = null;
if ($purchased_date !== '') {
  $d = date_create($purchased_date);
  if (!$d) {
    Helpers::flash('danger','Hibás vásárlás dátum.');
    header('Location: /vehicle.php?id='.$vehicle_id.'#tab_tires'); exit;
  }
  $pd = $d->format('Y-m-d');
}

if ($vehicle_id<=0 || $brand==='' || $tire_size==='') {
  Helpers::flash('danger','Hiányzó mezők (márka és méret kötelező).');
  header('Location: /vehicle.php?id='.$vehicle_id.'#tab_tires'); exit;
}

try {
  $st = $pdo->prepare("INSERT INTO vehicle_tires (vehicle_id, tire_kind, brand, tire_model, tire_size, dot_code, purchased_date, purchased_km, notes, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?)");
  $st->execute([$vehicle_id, $tire_kind, $brand, $tire_model, $tire_size, $dot_code, $pd, $purchased_km, ($notes===''?null:$notes), (int)$u['id']]);
  $tid = (int)$pdo->lastInsertId();

  try {
    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, changed_fields, user_id, created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute(['vehicle_tire', $tid, 'create',
                   json_encode(['vehicle_id'=>$vehicle_id,'tire_kind'=>$tire_kind,'brand'=>$brand,'tire_size'=>$tire_size,'dot_code'=>$dot_code], JSON_UNESCAPED_UNICODE),
                   (int)$u['id']]);
  } catch (Throwable $e) {}

  Helpers::flash('success','Gumi felvéve.');
} catch (Throwable $e) {
  Helpers::flash('danger','Mentési hiba: '.$e->getMessage());
}

header('Location: /vehicle.php?id='.$vehicle_id.'#tab_tires');

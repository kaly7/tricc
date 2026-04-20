<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';

use App\Auth; use App\Middleware; use App\Db; use App\Csrf;

Auth::start(); Middleware::requireAuth();
$u = Auth::user();
$isAdmin = isset($u['role_id']) && (int)$u['role_id']===1;
if (!$isAdmin) { http_response_code(403); exit('Nincs jogosultság.'); }

if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Location: /vehicles.php'); exit; }
if (!Csrf::check($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('CSRF hiba'); }

$id = (int)($_POST['id'] ?? 0);
if ($id<=0) { header('Location: /vehicles.php'); exit; }

$pdo = Db::pdo();

$sql = "UPDATE vehicles SET
  registration_doc_no=?,
  tech_valid_until=?,
  euro_class_id=?,
  body_type_id=?,
  seats=?,
  curb_weight_kg=?,
  gross_weight_kg=?,
  color_id=?,
  power_kw=?,
  manufacture_year=?,
  vignette_type_id=?,
  vignette_valid_until=?,
  hugo_enabled=?
WHERE id=?";
$vals = [
  (trim((string)($_POST['registration_doc_no'] ?? '')) !== '') ? trim((string)($_POST['registration_doc_no'] ?? '')) : null,
  (($_POST['tech_valid_until'] ?? '') !== '') ? $_POST['tech_valid_until'] : null,
  (($_POST['euro_class_id'] ?? '') !== '') ? (int)$_POST['euro_class_id'] : null,
  (($_POST['body_type_id'] ?? '') !== '') ? (int)$_POST['body_type_id'] : null,
  (($_POST['seats'] ?? '') !== '') ? (int)$_POST['seats'] : null,
  (($_POST['curb_weight_kg'] ?? '') !== '') ? (int)$_POST['curb_weight_kg'] : null,
  (($_POST['gross_weight_kg'] ?? '') !== '') ? (int)$_POST['gross_weight_kg'] : null,
  (($_POST['color_id'] ?? '') !== '') ? (int)$_POST['color_id'] : null,
  (($_POST['power_kw'] ?? '') !== '') ? (int)$_POST['power_kw'] : null,
  (($_POST['manufacture_year'] ?? '') !== '') ? (int)$_POST['manufacture_year'] : null,
  (($_POST['vignette_type_id'] ?? '') !== '') ? (int)$_POST['vignette_type_id'] : null,
  (($_POST['vignette_valid_until'] ?? '') !== '') ? $_POST['vignette_valid_until'] : null,
  (int)($_POST['hugo_enabled'] ?? 0),
  $id
];

$st = $pdo->prepare($sql);
$st->execute($vals);

// Audit (best-effort) – vehicle_id is embedded so it appears in vehicle log tab (LIKE filter)
try {
  $changed = [
    'vehicle_id'=>$id,
    'registration_doc_no'=>$vals[0],
    'tech_valid_until'=>$vals[1],
    'euro_class_id'=>$vals[2],
    'body_type_id'=>$vals[3],
    'seats'=>$vals[4],
    'curb_weight_kg'=>$vals[5],
    'gross_weight_kg'=>$vals[6],
    'color_id'=>$vals[7],
    'power_kw'=>$vals[8],
    'manufacture_year'=>$vals[9],
    'vignette_type_id'=>$vals[10],
    'vignette_valid_until'=>$vals[11],
    'hugo_enabled'=>$vals[12]
  ];
  $pdo->prepare("INSERT INTO audit_log (user_id, entity_type, entity_id, action, changed_fields) VALUES (?,?,?,?,?)")
      ->execute([(int)$u['id'], 'vehicle', $id, 'update', json_encode($changed, JSON_UNESCAPED_UNICODE)]);
} catch (Throwable $e) {}

header('Location: /vehicle.php?id='.$id.'&extra=open');

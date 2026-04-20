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
$service_date = trim((string)($_POST['service_date'] ?? ''));
$odometer_km = (int)($_POST['odometer_km'] ?? 0);
$reset_oil = isset($_POST['reset_oil']) ? 1 : 0;
$reset_service = isset($_POST['reset_service']) ? 1 : 0;
$description = trim((string)($_POST['description'] ?? ''));
$materials = trim((string)($_POST['materials'] ?? ''));
$labor_cost = (float)str_replace(',', '.', (string)($_POST['labor_cost'] ?? '0'));
$material_cost = (float)str_replace(',', '.', (string)($_POST['material_cost'] ?? '0'));
$vendor_name = trim((string)($_POST['vendor_name'] ?? ''));
$vendor_address = trim((string)($_POST['vendor_address'] ?? ''));
$invoice_no = trim((string)($_POST['invoice_no'] ?? ''));

if ($vehicle_id<=0 || $service_date==='' || $odometer_km<0 || $description==='') {
  Helpers::flash('danger','Hiányzó mezők (dátum, km óra, leírás).');
  header('Location: /vehicle.php?id='.$vehicle_id.'#tab_service'); exit;
}

$dt = date_create($service_date);
if (!$dt) {
  Helpers::flash('danger','Hibás dátum.');
  header('Location: /vehicle.php?id='.$vehicle_id.'#tab_service'); exit;
}
$service_date_sql = date_format($dt,'Y-m-d');

// Invoice upload (optional)
$invoice_path = null; $invoice_orig_name=null; $invoice_mime=null; $invoice_size=null;

if (!empty($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] !== UPLOAD_ERR_NO_FILE) {
  if ($_FILES['invoice_file']['error'] !== UPLOAD_ERR_OK) {
    Helpers::flash('danger','Számla feltöltési hiba (kód: '.$_FILES['invoice_file']['error'].').');
    header('Location: /vehicle.php?id='.$vehicle_id.'#tab_service'); exit;
  }
  $tmp = $_FILES['invoice_file']['tmp_name'];
  $invoice_size = (int)($_FILES['invoice_file']['size'] ?? 0);
  $invoice_orig_name = (string)($_FILES['invoice_file']['name'] ?? 'invoice');
  $invoice_mime = (string)($_FILES['invoice_file']['type'] ?? '');

  $allowed = ['application/pdf','image/jpeg','image/png','image/webp'];
  if ($invoice_mime && !in_array($invoice_mime, $allowed, true)) {
    Helpers::flash('danger','Csak PDF/JPG/PNG/WEBP tölthető fel számlának.');
    header('Location: /vehicle.php?id='.$vehicle_id.'#tab_service'); exit;
  }

  $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $invoice_orig_name);
  $safe = trim($safe, '_');
  if ($safe==='') $safe='invoice';
  $ext = pathinfo($safe, PATHINFO_EXTENSION);
  $stamp = date('Ymd_His');
  $fname = $stamp.'_'.bin2hex(random_bytes(4)).($ext?('.'.$ext):'');

  $dir = dirname(__DIR__).'/storage/vehicle_invoices/'.$vehicle_id;
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  $dest = $dir.'/'.$fname;

  if (!move_uploaded_file($tmp, $dest)) {
    Helpers::flash('danger','Nem sikerült a számla mentése a szerverre.');
    header('Location: /vehicle.php?id='.$vehicle_id.'#tab_service'); exit;
  }
  $invoice_path = 'storage/vehicle_invoices/'.$vehicle_id.'/'.$fname;
}

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("INSERT INTO vehicle_service_entries
    (vehicle_id, service_date, odometer_km, reset_oil, reset_service, description, materials, labor_cost, material_cost,
     vendor_name, vendor_address, invoice_no, invoice_path, invoice_orig_name, invoice_mime, invoice_size, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $st->execute([
    $vehicle_id, $service_date_sql, $odometer_km, $reset_oil, $reset_service,
    $description, ($materials===''?null:$materials),
    $labor_cost, $material_cost,
    $vendor_name, $vendor_address, $invoice_no,
    $invoice_path, $invoice_orig_name, $invoice_mime, $invoice_size,
    (int)$u['id']
  ]);
  $entry_id = (int)$pdo->lastInsertId();

  $pdo->prepare("UPDATE vehicles SET odometer_km = GREATEST(odometer_km, ?) WHERE id=?")->execute([$odometer_km, $vehicle_id]);

  if ($reset_oil) {
    $pdo->prepare("UPDATE vehicles SET last_oil_km=?, last_oil_date=? WHERE id=?")->execute([$odometer_km, $service_date_sql, $vehicle_id]);
  }
  if ($reset_service) {
    $pdo->prepare("UPDATE vehicles SET last_service_km=?, last_service_date=? WHERE id=?")->execute([$odometer_km, $service_date_sql, $vehicle_id]);
  }

  try {
    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, changed_fields, user_id, created_at)
                   VALUES (?,?,?,?,?,NOW())")
        ->execute(['vehicle_service', $entry_id, 'create',
                   json_encode(['vehicle_id'=>$vehicle_id,'service_date'=>$service_date_sql,'odometer_km'=>$odometer_km,'reset_oil'=>$reset_oil,'reset_service'=>$reset_service,'vendor_name'=>$vendor_name], JSON_UNESCAPED_UNICODE),
                   (int)$u['id']]);
  } catch (Throwable $e) { }

  $pdo->commit();
  Helpers::flash('success','Szerviz bejegyzés mentve.');
} catch (Throwable $e) {
  $pdo->rollBack();
  Helpers::flash('danger','Mentési hiba: '.$e->getMessage());
}

header('Location: /vehicle.php?id='.$vehicle_id.'#tab_service');

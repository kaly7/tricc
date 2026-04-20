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

if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Method not allowed'); }
if (!\App\Csrf::check($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('CSRF hiba.'); }

$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
$reported_date = trim((string)($_POST['reported_date'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));

if ($vehicle_id<=0 || $reported_date==='' || $description==='') {
  Helpers::flash('danger','Hiányzó mezők.');
  header('Location: /vehicle.php?id='.$vehicle_id.'#tab_issues'); exit;
}

$d = date_create($reported_date);
if (!$d) {
  Helpers::flash('danger','Hibás dátum.');
  header('Location: /vehicle.php?id='.$vehicle_id.'#tab_issues'); exit;
}
$reported_sql = $d->format('Y-m-d');

try {
  $st = $pdo->prepare("INSERT INTO vehicle_issues (vehicle_id, reported_date, description, created_by) VALUES (?,?,?,?)");
  $st->execute([$vehicle_id, $reported_sql, $description, (int)$u['id']]);
  $iid = (int)$pdo->lastInsertId();

  try {
    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, changed_fields, user_id, created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute(['vehicle_issue', $iid, 'create',
                   json_encode(['vehicle_id'=>$vehicle_id,'reported_date'=>$reported_sql], JSON_UNESCAPED_UNICODE),
                   (int)$u['id']]);
  } catch (Throwable $e) {}

  Helpers::flash('success','Hiba rögzítve.');
} catch (Throwable $e) {
  Helpers::flash('danger','Mentési hiba: '.$e->getMessage());
}

header('Location: /vehicle.php?id='.$vehicle_id.'#tab_issues');

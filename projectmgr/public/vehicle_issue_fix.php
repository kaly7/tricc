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
$issue_id = (int)($_POST['issue_id'] ?? 0);
$fixed_date = trim((string)($_POST['fixed_date'] ?? ''));
$fixed_sql = null;
if ($fixed_date !== '') {
  $d = date_create($fixed_date);
  if (!$d) {
    Helpers::flash('danger','Hibás dátum.');
    header('Location: /vehicle.php?id='.$vehicle_id.'#tab_issues'); exit;
  }
  $fixed_sql = $d->format('Y-m-d');
}

try {
  $st = $pdo->prepare("UPDATE vehicle_issues SET fixed_date=? WHERE id=? AND vehicle_id=?");
  $st->execute([$fixed_sql, $issue_id, $vehicle_id]);

  try {
    $pdo->prepare("INSERT INTO audit_log (entity_type, entity_id, action, changed_fields, user_id, created_at) VALUES (?,?,?,?,?,NOW())")
        ->execute(['vehicle_issue', $issue_id, 'update',
                   json_encode(['vehicle_id'=>$vehicle_id,'fixed_date'=>$fixed_sql], JSON_UNESCAPED_UNICODE),
                   (int)$u['id']]);
  } catch (Throwable $e) {}

  Helpers::flash('success','Mentve.');
} catch (Throwable $e) {
  Helpers::flash('danger','Mentési hiba: '.$e->getMessage());
}

header('Location: /vehicle.php?id='.$vehicle_id.'#tab_issues');

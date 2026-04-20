<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php'; require_once __DIR__.'/../../src/helpers.php';
$id = (int)($_POST['id'] ?? 0);
$st = db()->prepare('SELECT * FROM records WHERE id=?'); $st->execute([$id]); $old = $st->fetch();
if(!$old){ header('Location: ../records.php'); exit; }

/*$payload = [
  'eventus'     => substr(trim($_POST['eventus'] ?? $old['eventus']),0,15),
  'pp_status_id'=> (int)($_POST['pp_status_id'] ?? $old['pp_status_id']),
  'issued_at'   => ($_POST['issued_at'] ?? $old['issued_at']),
  'city_id'     => (int)($_POST['city_id'] ?? $old['city_id']),
  'address'     => substr(trim($_POST['address'] ?? $old['address']),0,190),
  'operation'   => substr(trim($_POST['operation'] ?? $old['operation']),0,120),
  'long_desc'   => ($_POST['long_desc'] ?? $old['long_desc']),
  'archived'    => isset($_POST['archived']) ? 1 : 0
];
*/

$payload = [
  'eventus'     => $old['eventus'], // NEM módosítható editben
  'pp_status_id'=> (int)($_POST['pp_status_id'] ?? $old['pp_status_id']),
  'issued_at'   => ($_POST['issued_at'] ?? $old['issued_at']),
  'city_id'     => (int)($_POST['city_id'] ?? $old['city_id']),
  'address'     => substr(trim($_POST['address'] ?? $old['address']),0,190),
  'operation'   => substr(trim($_POST['operation'] ?? $old['operation']),0,120),
  'long_desc'   => ($_POST['long_desc'] ?? $old['long_desc']),
  'archived'    => isset($_POST['archived']) ? 1 : 0
];

$due = calc_due($payload['issued_at']);
$payload['due_at']=$due;

$sql = 'UPDATE records SET eventus=?, pp_status_id=?, issued_at=?, due_at=?, city_id=?, address=?, operation=?, long_desc=?, archived=?, updated_by=? WHERE id=?';
$params = [
  $payload['eventus'],$payload['pp_status_id'],$payload['issued_at'],$payload['due_at'],$payload['city_id'],$payload['address'],$payload['operation'],$payload['long_desc'],$payload['archived'],current_user()['id'],$id
];
db()->prepare($sql)->execute($params);

// Build mapping for human-readable logging
$mapStatus = [];
foreach (db()->query('SELECT id,name FROM pp_status') as $_s) { $mapStatus[(int)$_s['id']] = (string)$_s['name']; }
$mapCity = [];
foreach (db()->query('SELECT id,name FROM cities') as $_c) { $mapCity[(int)$_c['id']] = (string)$_c['name']; }
function pretty_val($field, $val, $mapStatus, $mapCity){
  if ($val === null) return '';
  switch ($field) {
    case 'pp_status_id': $id=(int)$val; return $mapStatus[$id] ?? (string)$val;
    case 'city_id':      $id=(int)$val; return $mapCity[$id] ?? (string)$val;
    case 'archived':     return ((string)$val==='1' || $val===1) ? 'igen' : 'nem';
    default:             return (string)$val;
  }
}

// Napló
$st2 = db()->prepare('SELECT * FROM records WHERE id=?'); $st2->execute([$id]); $new=$st2->fetch();
$fields = ['eventus','pp_status_id','issued_at','due_at','city_id','address','operation','long_desc','archived'];
$ast = db()->prepare('INSERT INTO record_changes (record_id,changed_by,field,old_value,new_value) VALUES (?,?,?,?,?)');
foreach($fields as $f){
  $ov_raw = $old[$f] ?? '';
  $nv_raw = $new[$f] ?? '';
  $ov = pretty_val($f, $ov_raw, $mapStatus, $mapCity);
  $nv = pretty_val($f, $nv_raw, $mapStatus, $mapCity);
  if($ov !== $nv) $ast->execute([$id,current_user()['id'],$f,$ov,$nv]);
}

header('Location: ../records.php'); exit;

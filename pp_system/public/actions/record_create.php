<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php'; require_once __DIR__.'/../../src/helpers.php';

$eventus = substr(trim($_POST['eventus'] ?? ''),0,15);
$pp      = (int)($_POST['pp_status_id'] ?? 0);
$issued  = $_POST['issued_at'] ?? date('Y-m-d');
$city    = (int)($_POST['city_id'] ?? 0);
$addr    = substr(trim($_POST['address'] ?? ''),0,190);
$op      = substr(trim($_POST['operation'] ?? ''),0,120);
$long    = ($_POST['long_desc'] ?? null);
$arch    = isset($_POST['archived']) ? 1 : 0;

if(!$eventus || !$pp || !$city){
  header('Location: ../records_new.php?err=fk'); exit;
}

// Duplikáció-ellenőrzés (csak élő rekordok között)
$st = db()->prepare('SELECT 1 FROM records WHERE eventus = ? AND deleted_at IS NULL LIMIT 1');
$st->execute([$eventus]);
if ($st->fetch()) {
  header('Location: ../records_new.php?err=dup'); exit;
}

$due = calc_due($issued);
$cityName = db()->prepare('SELECT name FROM cities WHERE id=?');
$cityName->execute([$city]);
$cityName = (string)($cityName->fetchColumn() ?? '');
$geo = geocode_address(trim($cityName . ' ' . $addr)) ?? geocode_address($cityName);
$st = db()->prepare('INSERT INTO records (eventus,pp_status_id,issued_at,due_at,city_id,address,operation,long_desc,archived,created_by,gps_lat,gps_lng) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$st->execute([$eventus,$pp,$issued,$due,$city,$addr,$op,$long,$arch,current_user()['id'],$geo['lat']??null,$geo['lng']??null]);

header('Location: ../records.php'); exit;
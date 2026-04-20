<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: ../admin_dicts.php?city_err=bad'); exit; }

// használat ellenőrzése
$st = db()->prepare('SELECT COUNT(*) FROM records WHERE city_id = ?');
$st->execute([$id]);
$cnt = (int)$st->fetchColumn();

if ($cnt > 0) {
  header('Location: ../admin_dicts.php?city_err=inuse'); exit;
}

// törlés
db()->prepare('DELETE FROM cities WHERE id = ?')->execute([$id]);
header('Location: ../admin_dicts.php?city_msg=deleted'); exit;
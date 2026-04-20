<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }

$name = trim($_POST['name'] ?? '');
if ($name === '') { header('Location: ../admin_dicts.php?city_err=empty'); exit; }

try {
  $st = db()->prepare('INSERT INTO cities (name) VALUES (?)');
  $st->execute([$name]);
  header('Location: ../admin_dicts.php?city_msg=created'); exit;
} catch (PDOException $e) {
  // 1062 = duplicate entry (UNIQUE constraint)
  if ((int)$e->errorInfo[1] === 1062) {
    header('Location: ../admin_dicts.php?city_err=dup&name='.urlencode($name)); exit;
  }
  // ismeretlen hiba
  header('Location: ../admin_dicts.php?city_err=unknown'); exit;
}
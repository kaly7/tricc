<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
$id=(int)($_POST['id'] ?? 0);
$name=trim($_POST['name'] ?? '');
if($id>0){ db()->prepare('UPDATE cities SET name=? WHERE id=?')->execute([$name,$id]); }
header('Location: ../admin_dicts.php'); exit;

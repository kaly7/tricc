<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
$id=(int)($_POST['id'] ?? 0);
$name=trim($_POST['name'] ?? '');
$hex =trim($_POST['color_hex'] ?? '#E3F2FD');
if($id>0){ db()->prepare('UPDATE pp_status SET name=?, color_hex=? WHERE id=?')->execute([$name,$hex,$id]); }
header('Location: ../admin_dicts.php'); exit;

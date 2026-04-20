<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
$name = trim($_POST['name'] ?? '');
$hex  = trim($_POST['color_hex'] ?? '#E3F2FD');
if($name!==''){ db()->prepare('INSERT INTO pp_status (name,color_hex) VALUES (?,?)')->execute([$name,$hex]); }
header('Location: ../admin_dicts.php'); exit;

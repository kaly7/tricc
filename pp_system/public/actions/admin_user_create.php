<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
$name=trim($_POST['name']??''); $email=trim($_POST['email']??''); $pass=(string)($_POST['password']??''); $role=(int)($_POST['role_id']??2);
if($name && $email && $pass){
  $hash=password_hash($pass, PASSWORD_DEFAULT);
  db()->prepare('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?)')->execute([$email,$name,$hash,$role]);
}
header('Location: ../admin_users.php'); exit;

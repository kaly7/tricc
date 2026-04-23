<?php
require_once __DIR__.'/db.php';

function start_session(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
function csrf_token(): string { start_session(); return $_SESSION['csrf']; }
function check_csrf(): void {
  start_session();
  $t = $_POST['_csrf'] ?? '';
  if (!hash_equals($_SESSION['csrf'], $t)) {
    http_response_code(419); echo 'CSRF'; exit;
  }
}

function login_user(string $email, string $password): bool {
  $st = db()->prepare('SELECT u.*, r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.is_active=1');
  $st->execute([$email]);
  $u = $st->fetch();
  if (!$u || !password_verify($password, $u['password_hash'])) return false;
  start_session();
  $_SESSION['uid']  = (int)$u['id'];
  $_SESSION['name'] = $u['name'];
  $_SESSION['role'] = $u['role_name'];
  return true;
}

function current_user(): ?array {
  start_session();
  if (empty($_SESSION['uid'])) return null;
  return ['id'=>$_SESSION['uid'],'name'=>$_SESSION['name'],'role'=>$_SESSION['role']];
}
function require_login_or_redirect(): void { if(!current_user()){ header('Location: login.php'); exit; } }
function is_admin(): bool { $u=current_user(); return $u && $u['role']==='admin'; }
function is_worker(): bool { $u=current_user(); return $u && $u['role']==='worker'; }
function logout_user(): void { start_session(); $_SESSION=[]; session_destroy(); }

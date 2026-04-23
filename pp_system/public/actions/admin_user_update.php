<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
if (!is_admin()) { http_response_code(403); echo 'Forbidden'; exit; }

$id    = (int)($_POST['id'] ?? 0);
$name  = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$role  = (int)($_POST['role_id'] ?? 2);
$pass  = (string)($_POST['password'] ?? '');

if (!$id || !$name || !$email) { header('Location: ../admin_users.php?err=bad'); exit; }

if ($pass !== '') {
    if (strlen($pass) < 8) { header('Location: ../admin_users.php?err=short'); exit; }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET name=?, email=?, role_id=?, password_hash=? WHERE id=?')
       ->execute([$name, $email, $role, $hash, $id]);
} else {
    db()->prepare('UPDATE users SET name=?, email=?, role_id=? WHERE id=?')
       ->execute([$name, $email, $role, $id]);
}

header('Location: ../admin_users.php?msg=updated'); exit;

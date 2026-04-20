<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../../src/db.php'; check_csrf();

if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }

$uid = (int)($_POST['id'] ?? 0);
if($uid<=0){ header('Location: ../admin_users.php'); exit; }

$me = current_user()['id'];
if ($uid === $me) { // önmagát ne tiltsa le
  header('Location: ../admin_users.php?err=self'); exit;
}

$st = db()->prepare('SELECT is_active FROM users WHERE id=?'); $st->execute([$uid]);
$u = $st->fetch(); if(!$u){ header('Location: ../admin_users.php'); exit; }

$new = $u['is_active'] ? 0 : 1;
db()->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$new, $uid]);

header('Location: ../admin_users.php'); exit;

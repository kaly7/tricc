<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';

$uid = current_user()['id'] ?? 0;
$cur = (string)($_POST['current_password'] ?? '');
$new = (string)($_POST['new_password'] ?? '');
$rep = (string)($_POST['new_password_confirm'] ?? '');

if ($uid<=0 || $cur==='' || $new==='' || $rep==='') {
  header('Location: ../change_password.php?err=bad'); exit;
}
if ($new !== $rep) {
  header('Location: ../change_password.php?err=mismatch'); exit;
}
if (strlen($new) < 8) {
  header('Location: ../change_password.php?err=short'); exit;
}

// felhasználó és jelenlegi jelszó ellenőrzés
$st = db()->prepare('SELECT password_hash FROM users WHERE id=? AND is_active=1');
$st->execute([$uid]);
$u = $st->fetch();

if (!$u || !password_verify($cur, $u['password_hash'])) {
  header('Location: ../change_password.php?err=badcur'); exit;
}

// új hash mentése
$hash = password_hash($new, PASSWORD_DEFAULT);
db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $uid]);

header('Location: ../change_password.php?msg=ok'); exit;
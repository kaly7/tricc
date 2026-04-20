<?php
require_once __DIR__.'/../../src/auth.php';
require_login_or_redirect();
check_csrf();
require_once __DIR__.'/../../src/db.php';

if (!is_admin()) { http_response_code(403); echo 'Forbidden'; exit; }

$uid = (int)($_POST['id'] ?? 0);
$new = trim((string)($_POST['new_password'] ?? ''));

// Alapellenőrzések
if ($uid <= 0 || $new === '') {
  header('Location: ../admin_users.php?err=bad'); exit;
}

// Opció: ne engedjük, hogy az admin saját magát véletlenül kitúrja? (nem szükséges, de lehet)
$me = current_user()['id'];
// ha szeretnéd, tiltsd meg az admin saját jelszavának itt történő módosítását:
// if ($uid === $me) { header('Location: ../admin_users.php?err=self'); exit; }

// Jelszó minimum követelmény (ajánlott)
if (strlen($new) < 8) {
  header('Location: ../admin_users.php?err=short'); exit;
}

// Hash és frissítés
$hash = password_hash($new, PASSWORD_DEFAULT);
$db = db();
$st = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$st->execute([$hash, $uid]);

// (OPCIONÁLIS) Naplózás: csak azt rögzítsük, hogy történt változtatás, NE a jelszót.
// Példa: létrehozhatsz külön audit táblát, ha szükséges.
// $db->prepare('INSERT INTO admin_audit (admin_id,action,target_user,created_at) VALUES (?,?,?,NOW())')->execute([current_user()['id'],'password_change',$uid]);

header('Location: ../admin_users.php?msg=passwd_changed'); exit;
<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../../src/db.php'; check_csrf();

if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }

$uid = (int)($_POST['id'] ?? 0);
if($uid<=0){ header('Location: ../admin_users.php'); exit; }

$me = current_user()['id'];
if ($uid === $me) { // önmagát ne törölje
  header('Location: ../admin_users.php?err=self'); exit;
}

// van-e hivatkozás?
$cnt = 0;
$q = db()->prepare('SELECT
  (SELECT COUNT(*) FROM records WHERE created_by=? OR updated_by=? OR deleted_by=?) +
  (SELECT COUNT(*) FROM record_changes WHERE changed_by=?) AS c
');
$q->execute([$uid,$uid,$uid,$uid]);
$cnt = (int)$q->fetchColumn();

if ($cnt > 0) {
  // van hivatkozás -> nem töröljük, javaslat: inaktiválás
  header('Location: ../admin_users.php?err=ref'); exit;
}

// ha nincs hivatkozás, törölhető
db()->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);

header('Location: ../admin_users.php'); exit;
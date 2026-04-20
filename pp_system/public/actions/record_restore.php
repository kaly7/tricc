<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
$id=(int)($_POST['id'] ?? 0);
db()->prepare('UPDATE records SET deleted_at=NULL, deleted_by=NULL WHERE id=?')->execute([$id]);
header('Location: ../records.php'); exit;

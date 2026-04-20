<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
$id=(int)($_POST['id'] ?? 0);
db()->prepare('UPDATE records SET deleted_at=NOW(), deleted_by=? WHERE id=? AND deleted_at IS NULL')->execute([current_user()['id'],$id]);
header('Location: ../records.php'); exit;

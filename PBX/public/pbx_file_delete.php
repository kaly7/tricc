<?php
require __DIR__.'/../app/auth.php';
require_login();
require_role('editor');
$pdo = db();

$fileId = (int)($_GET['id'] ?? 0);
$pbxId  = (int)($_GET['pbx_id'] ?? 0);

$st = $pdo->prepare("UPDATE pbx_files SET is_deleted=1, deleted_at=NOW() WHERE id=?");
$st->execute([$fileId]);

header('Location: '.base_url('pbx_system_files.php?id='.$pbxId));
exit;

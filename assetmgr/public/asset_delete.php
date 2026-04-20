<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');
verify_csrf();

$id = (int)($_POST['id'] ?? 0);
$pdo = db();
$pdo->prepare("UPDATE assets SET is_deleted=1, deleted_at=NOW() WHERE id=?")->execute([$id]);
flash_set('ok','Törölve.');
header('Location: assets.php');
exit;

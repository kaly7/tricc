<?php
require __DIR__.'/../app/auth.php';
require_login();
require_role('editor');
$pdo = db();

$id  = (int)($_GET['id'] ?? 0);
$cid = (int)($_GET['cid'] ?? 0);

$st = $pdo->prepare("UPDATE catalog_files SET is_deleted=1, deleted_at=NOW() WHERE id=?");
$st->execute([$id]);

header('Location: '.base_url('catalog_item_files.php?id='.$cid));
exit;

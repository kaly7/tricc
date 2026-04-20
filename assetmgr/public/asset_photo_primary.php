<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');
verify_csrf();

$id=(int)($_POST['id'] ?? 0);
$asset_id=(int)($_POST['asset_id'] ?? 0);
$pdo=db();
$pdo->prepare("UPDATE asset_photos SET is_primary=0 WHERE asset_id=?")->execute([$asset_id]);
$pdo->prepare("UPDATE asset_photos SET is_primary=1 WHERE id=? AND asset_id=?")->execute([$id,$asset_id]);
flash_set('ok','Fő kép beállítva.');
header('Location: asset_edit.php?id='.$asset_id);
exit;

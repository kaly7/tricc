<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');
verify_csrf();

$id=(int)($_POST['id'] ?? 0);
$asset_id=(int)($_POST['asset_id'] ?? 0);
$pdo=db();

$st=$pdo->prepare("SELECT file_path, is_primary FROM asset_photos WHERE id=? AND asset_id=? LIMIT 1");
$st->execute([$id,$asset_id]);
$p=$st->fetch();
if($p){
  $pdo->prepare("DELETE FROM asset_photos WHERE id=? AND asset_id=?")->execute([$id,$asset_id]);
  // Try delete file (best-effort)
  $fp = __DIR__.'/..'.$p['file_path'];
  if (is_file($fp)) @unlink($fp);
  if ((int)$p['is_primary']===1) {
    // promote latest as primary
    $st2=$pdo->prepare("SELECT id FROM asset_photos WHERE asset_id=? ORDER BY id DESC LIMIT 1");
    $st2->execute([$asset_id]);
    $n=$st2->fetch();
    if($n) $pdo->prepare("UPDATE asset_photos SET is_primary=1 WHERE id=?")->execute([(int)$n['id']]);
  }
}
flash_set('ok','Kép törölve.');
header('Location: asset_edit.php?id='.$asset_id);
exit;

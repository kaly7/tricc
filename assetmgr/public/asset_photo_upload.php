<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');
verify_csrf();

$asset_id=(int)($_POST['asset_id'] ?? 0);
if ($asset_id<=0) { header('Location: assets.php'); exit; }

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
  flash_set('err','Feltöltési hiba.');
  header('Location: asset_edit.php?id='.$asset_id); exit;
}

$ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
  flash_set('err','Csak kép tölthető fel (jpg/png/webp/gif).');
  header('Location: asset_edit.php?id='.$asset_id); exit;
}

$dir = __DIR__.'/../storage/uploads/assets/'.$asset_id;
if (!is_dir($dir)) mkdir($dir, 0775, true);

$fn = 'p_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
$abs = $dir.'/'.$fn;
if (!move_uploaded_file($_FILES['photo']['tmp_name'], $abs)) {
  flash_set('err','Nem sikerült menteni.');
  header('Location: asset_edit.php?id='.$asset_id); exit;
}

$rel = '/storage/uploads/assets/'.$asset_id.'/'.$fn; // served by apache alias or direct if docroot is module root
$pdo=db();

// First photo becomes primary
$has = $pdo->prepare("SELECT COUNT(*) c FROM asset_photos WHERE asset_id=?");
$has->execute([$asset_id]);
$cnt = (int)($has->fetch()['c'] ?? 0);
$is_primary = ($cnt===0) ? 1 : 0;

$pdo->prepare("INSERT INTO asset_photos (asset_id,file_path,is_primary) VALUES (?,?,?)")->execute([$asset_id,$rel,$is_primary]);
flash_set('ok','Kép feltöltve.');
header('Location: asset_edit.php?id='.$asset_id);
exit;

<?php
declare(strict_types=1);
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');
verify_csrf();

$inspectionId = (int)($_POST['inspection_id'] ?? 0);
$assetId      = (int)($_POST['asset_id'] ?? 0);
if ($inspectionId <= 0 || $assetId <= 0) { redirect('assets.php'); }

$pdo = db();
$st = $pdo->prepare("SELECT id FROM asset_inspections WHERE id=? AND asset_id=? LIMIT 1");
$st->execute([$inspectionId, $assetId]);
if (!$st->fetchColumn()) {
    flash_set('err', 'Felülvizsgálati bejegyzés nem található.');
    redirect('asset_edit.php?id='.$assetId.'#inspection');
}

if (!isset($_FILES['doc']) || $_FILES['doc']['error'] !== UPLOAD_ERR_OK) {
    flash_set('err', 'Feltöltési hiba.');
    redirect('asset_edit.php?id='.$assetId.'#inspection');
}

$origName = (string)($_FILES['doc']['name'] ?? '');
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg','jpeg','png','webp','gif','pdf'], true)) {
    flash_set('err', 'Csak kép (jpg/png/webp/gif) vagy PDF tölthető fel.');
    redirect('asset_edit.php?id='.$assetId.'#inspection');
}

$dir = __DIR__.'/../storage/uploads/assets/'.$assetId.'/inspections';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$fn  = 'insp_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
$abs = $dir.'/'.$fn;
if (!move_uploaded_file($_FILES['doc']['tmp_name'], $abs)) {
    flash_set('err', 'Nem sikerült menteni a fájlt.');
    redirect('asset_edit.php?id='.$assetId.'#inspection');
}

$rel  = '/storage/uploads/assets/'.$assetId.'/inspections/'.$fn;
$mime = (string)($_FILES['doc']['type'] ?? '');
$size = (int)($_FILES['doc']['size'] ?? 0);

$pdo->prepare("INSERT INTO asset_inspection_docs (inspection_id, asset_id, file_path, original_name, mime_type, file_size)
               VALUES (?,?,?,?,?,?)")
    ->execute([$inspectionId, $assetId, $rel, $origName ?: $fn, $mime, $size]);

flash_set('ok', 'Dokumentum feltöltve.');
redirect('asset_edit.php?id='.$assetId.'#inspection');

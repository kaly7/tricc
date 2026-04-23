<?php
declare(strict_types=1);
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');
verify_csrf();

$docId   = (int)($_POST['doc_id'] ?? 0);
$assetId = (int)($_POST['asset_id'] ?? 0);
if ($docId <= 0 || $assetId <= 0) { redirect('assets.php'); }

$pdo = db();
$st = $pdo->prepare("SELECT * FROM asset_inspection_docs WHERE id=? AND asset_id=? LIMIT 1");
$st->execute([$docId, $assetId]);
$doc = $st->fetch();
if (!$doc) {
    flash_set('err', 'Dokumentum nem található.');
    redirect('asset_edit.php?id='.$assetId.'#inspection');
}

$pdo->prepare("DELETE FROM asset_inspection_docs WHERE id=?")->execute([$docId]);

$abs = __DIR__.'/..'.(string)$doc['file_path'];
if (is_file($abs)) @unlink($abs);

flash_set('ok', 'Dokumentum törölve.');
redirect('asset_edit.php?id='.$assetId.'#inspection');

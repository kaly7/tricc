<?php
declare(strict_types=1);
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');
verify_csrf();

$assetId = (int)($_POST['asset_id'] ?? 0);
if ($assetId <= 0) { redirect('assets.php'); }

$pdo = db();
$st = $pdo->prepare("SELECT id FROM assets WHERE id=? AND is_deleted=0 LIMIT 1");
$st->execute([$assetId]);
if (!$st->fetchColumn()) {
    flash_set('err', 'Eszköz nem található.');
    redirect('assets.php');
}

$inspectionDate = trim((string)($_POST['inspection_date'] ?? ''));
if ($inspectionDate === '') $inspectionDate = date('Y-m-d');

$intervalValue = (int)($_POST['interval_value'] ?? 0);
$intervalUnit  = (string)($_POST['interval_unit'] ?? 'month');
if (!in_array($intervalUnit, ['day','month','year'], true)) $intervalUnit = 'month';

$nextDateRaw = trim((string)($_POST['next_date'] ?? ''));
$nextDate = null;

if ($intervalValue > 0) {
    try {
        $d = new DateTime($inspectionDate);
        $d->modify("+{$intervalValue} {$intervalUnit}");
        $nextDate = $d->format('Y-m-d');
    } catch (Throwable $e) {
        $nextDate = null;
    }
} elseif ($nextDateRaw !== '') {
    $nextDate = $nextDateRaw;
}

$note = trim((string)($_POST['note'] ?? '')) ?: null;
$userId = (int)(current_user()['id'] ?? 0);

$pdo->prepare("INSERT INTO asset_inspections (asset_id, inspection_date, next_date, interval_value, interval_unit, note, created_by)
               VALUES (?,?,?,?,?,?,?)")
    ->execute([
        $assetId,
        $inspectionDate,
        $nextDate,
        $intervalValue > 0 ? $intervalValue : null,
        $intervalValue > 0 ? $intervalUnit : null,
        $note,
        $userId ?: null,
    ]);

$inspectionId = (int)$pdo->lastInsertId();

// Dokumentum feltöltés (opcionális)
if (isset($_FILES['doc']) && $_FILES['doc']['error'] === UPLOAD_ERR_OK) {
    $origName = (string)($_FILES['doc']['name'] ?? '');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif','pdf'];
    if (in_array($ext, $allowed, true)) {
        $dir = __DIR__.'/../storage/uploads/assets/'.$assetId.'/inspections';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $fn  = 'insp_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $abs = $dir.'/'.$fn;
        if (move_uploaded_file($_FILES['doc']['tmp_name'], $abs)) {
            $rel  = '/storage/uploads/assets/'.$assetId.'/inspections/'.$fn;
            $mime = (string)($_FILES['doc']['type'] ?? '');
            $size = (int)($_FILES['doc']['size'] ?? 0);
            $pdo->prepare("INSERT INTO asset_inspection_docs (inspection_id, asset_id, file_path, original_name, mime_type, file_size)
                           VALUES (?,?,?,?,?,?)")
                ->execute([$inspectionId, $assetId, $rel, $origName ?: $fn, $mime, $size]);
        }
    }
}

flash_set('ok', 'Felülvizsgálat rögzítve.');
redirect('asset_edit.php?id='.$assetId.'#inspection');

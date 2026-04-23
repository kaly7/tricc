<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';

$jobId      = (int)($_POST['job_id'] ?? 0);
$materialId = (int)($_POST['material_id'] ?? 0);
$qty        = (float)str_replace(',', '.', $_POST['qty'] ?? '1');
$note       = trim($_POST['note'] ?? '');

if (!$jobId || !$materialId || $qty <= 0) {
    header('Location: ../my_om_job.php?id='.$jobId.'&err=bad'); exit;
}

$wh = new PDO('mysql:host=127.0.0.1;dbname=warehousemgr;charset=utf8mb4', 'ppdb', 'abrakadabra', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$mat = $wh->prepare('SELECT id, name, sku, unit FROM material_items WHERE id=? AND is_active=1 LIMIT 1');
$mat->execute([$materialId]);
$mat = $mat->fetch();
if (!$mat) { header('Location: ../my_om_job.php?id='.$jobId.'&err=bad'); exit; }

db()->prepare('INSERT INTO om_job_materials (job_id, warehouse_item_id, material_name, material_sku, unit, quantity, note, user_id) VALUES (?,?,?,?,?,?,?,?)')
   ->execute([$jobId, $mat['id'], $mat['name'], $mat['sku'], $mat['unit'], $qty, $note ?: null, current_user()['id']]);

header('Location: ../my_om_job.php?id='.$jobId); exit;

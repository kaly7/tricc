<?php
declare(strict_types=1);
/**
 * warehousemgr
 * Belső raktárközi átadás PDF megjelenítő / letöltő végpont.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/pdf_external_handover.php'; // warehouse_external_pdf_items_table_html
require_once __DIR__ . '/../app/pdf_internal_transfer.php';

$transferId = (int)($_GET['id'] ?? 0);
$download   = isset($_GET['download']) && (string)($_GET['download'] ?? '') === '1';

if ($transferId < 1) {
    http_response_code(400);
    echo 'Hiányzó vagy érvénytelen átadás azonosító.';
    exit;
}

$transfer = warehouse_transfer_find($config, $transferId);
if (!$transfer) {
    http_response_code(404);
    echo 'Az átadás nem található.';
    exit;
}

if (warehouse_transfer_type_normalize((string)($transfer['transfer_type'] ?? 'internal')) !== 'internal') {
    http_response_code(400);
    echo 'Ez a végpont csak belső raktárközi átadáshoz használható.';
    exit;
}

$sourceId = (int)($transfer['source_warehouse_id'] ?? 0);
$targetId = (int)($transfer['target_warehouse_id'] ?? 0);
if (!warehouse_module_admin($config) && !warehouse_user_can_view_warehouse($config, $sourceId) && !warehouse_user_can_view_warehouse($config, $targetId)) {
    http_response_code(403);
    echo 'Nincs jogosultságod a dokumentum megnyitásához.';
    exit;
}

try {
    $pdf      = warehouse_generate_internal_transfer_pdf($config, $transfer);
    $abs      = (string)($pdf['abs'] ?? '');
    $filename = (string)($pdf['filename'] ?? ('transfer_' . $transferId . '.pdf'));

    if ($abs === '' || !is_file($abs)) {
        throw new RuntimeException('A PDF fájl nem jött létre.');
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($abs);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'PDF generálási hiba: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

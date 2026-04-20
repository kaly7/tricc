<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Külsős átadás PDF megjelenítő / letöltő végpont.
 * Csak elfogadott külsős átadáshoz enged dokumentumot generálni.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/pdf_external_handover.php';

$transferId = (int)($_GET['id'] ?? 0);
$download = isset($_GET['download']) && (string)($_GET['download'] ?? '') === '1';

if ($transferId < 1) {
    http_response_code(400);
    echo 'Hiányzó vagy érvénytelen átadás azonosító.';
    exit;
}

// A PDF csak létező, elfogadott és külső típusú átadásnál érhető el.
$transfer = warehouse_transfer_find($config, $transferId);
if (!$transfer) {
    http_response_code(404);
    echo 'Az átadás nem található.';
    exit;
}
if (warehouse_transfer_type_normalize((string)($transfer['transfer_type'] ?? 'internal')) !== 'external') {
    http_response_code(400);
    echo 'Ehhez az átadáshoz nem tartozik külsős szállítólevél PDF.';
    exit;
}
if ((string)($transfer['status'] ?? '') !== 'accepted') {
    http_response_code(400);
    echo 'PDF csak elfogadott külsős átadáshoz készíthető.';
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
    $pdf = warehouse_generate_external_transfer_pdf_mpdf($config, $transfer);
    $abs = (string)($pdf['abs'] ?? '');
    $filename = (string)($pdf['filename'] ?? ('external_transfer_' . $transferId . '.pdf'));
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
    echo 'PDF generálási hiba: ' . h($e->getMessage());
    exit;
}

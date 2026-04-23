<?php
declare(strict_types=1);
/**
 * warehousemgr
 * Raktárközi (belső) átadás PDF előállítás.
 */

function warehouse_internal_pdf_output_file(array $transfer): array {
    $storageDir = __DIR__ . '/../storage/documents/internal_transfer';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Nem sikerült létrehozni a PDF könyvtárat: ' . $storageDir);
    }

    $ref = trim((string)($transfer['reference_no'] ?? ''));
    if ($ref === '') {
        $ref = 'belso_atadas_' . (int)($transfer['id'] ?? 0);
    }
    $safeRef = preg_replace('/[^A-Za-z0-9_-]+/', '_', $ref) ?: ('belso_atadas_' . (int)($transfer['id'] ?? 0));
    $filename = $safeRef . '.pdf';
    $abs = $storageDir . '/' . $filename;
    $rel = '/storage/documents/internal_transfer/' . $filename;
    return [$abs, $rel, $filename];
}

function warehouse_generate_internal_transfer_pdf(array $config, array $transfer): array {
    $transferId = (int)($transfer['id'] ?? 0);
    if ($transferId < 1) {
        throw new RuntimeException('Érvénytelen átadás rekord.');
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Hiányzik a vendor/autoload.php.');
    }
    require_once $autoload;

    $tmp = __DIR__ . '/../storage/mpdf';
    if (!is_dir($tmp) && !@mkdir($tmp, 0775, true) && !is_dir($tmp)) {
        throw new RuntimeException('Nem sikerült létrehozni az mPDF temp könyvtárat: ' . $tmp);
    }

    [$pdfAbs, $pdfRel, $filename] = warehouse_internal_pdf_output_file($transfer);
    $tplAbs = __DIR__ . '/../templates/pdf/internal_transfer.html';

    if (!is_file($tplAbs)) {
        throw new RuntimeException('PDF sablon nem található: ' . $tplAbs);
    }
    $html = (string)file_get_contents($tplAbs);

    // Logo
    $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.png';
    if (!is_file($logoAbs)) {
        $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.jpg';
    }
    $logo = '';
    if (is_file($logoAbs)) {
        $ext  = strtolower(pathinfo($logoAbs, PATHINFO_EXTENSION));
        $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
        $b64  = base64_encode((string)file_get_contents($logoAbs));
        $logo = '<img class="logoimg" src="data:' . $mime . ';base64,' . $b64 . '">';
    }

    // Dátum
    $requestedAt = trim((string)($transfer['requested_at'] ?? ''));
    $acceptedAt  = trim((string)($transfer['accepted_at'] ?? ''));
    $handoverAt  = $acceptedAt !== '' ? $acceptedAt : $requestedAt;

    // Tételek táblázat (újrahasználjuk a meglévő függvényt)
    $itemsTable = warehouse_external_pdf_items_table_html((array)($transfer['items'] ?? []));

    // Bizonylat szám: ha nincs egyedi reference_no, a TR-XXXXXX formátumot használjuk
    $refNo = trim((string)($transfer['reference_no'] ?? ''));
    if ($refNo === '') {
        $refNo = warehouse_transfer_reference($transferId);
    }

    // Placeholder csere
    $vars = [
        'LOGO'             => $logo,
        'REFERENCE_NO'     => htmlspecialchars($refNo, ENT_QUOTES, 'UTF-8'),
        'HANDOVER_AT'      => htmlspecialchars($handoverAt, ENT_QUOTES, 'UTF-8'),
        'SOURCE_WAREHOUSE' => htmlspecialchars(
            trim((string)($transfer['source_warehouse_name'] ?? '') . ' (' . (string)($transfer['source_warehouse_code'] ?? '') . ')'),
            ENT_QUOTES, 'UTF-8'
        ),
        'TARGET_WAREHOUSE' => htmlspecialchars(
            trim((string)($transfer['target_warehouse_name'] ?? '') . ' (' . (string)($transfer['target_warehouse_code'] ?? '') . ')'),
            ENT_QUOTES, 'UTF-8'
        ),
        'REQUESTED_BY'     => htmlspecialchars((string)($transfer['requested_by_name'] ?? '—'), ENT_QUOTES, 'UTF-8'),
        'ACCEPTED_BY'      => htmlspecialchars((string)($transfer['accepted_by_name'] ?? '—'), ENT_QUOTES, 'UTF-8'),
        'NOTE'             => nl2br(htmlspecialchars((string)($transfer['note'] ?? ''), ENT_QUOTES, 'UTF-8')),
        'ITEMS_TABLE'      => $itemsTable,
        'FOOTER_NOTE'      => '&copy; Perfect-Phone - A dokumentum elektronikusan készült. - www.perfect-phone.hu',
    ];

    foreach ($vars as $k => $v) {
        $html = str_replace('{{' . $k . '}}', $v, $html);
    }
    $html = (string)preg_replace('/\{\{[A-Z0-9_]+\}\}/', '', $html);

    $mpdf = new \Mpdf\Mpdf([
        'mode'         => 'utf-8',
        'format'       => 'A4',
        'default_font' => 'dejavusans',
        'tempDir'      => $tmp,
    ]);
    $mpdf->WriteHTML($html);
    $mpdf->Output($pdfAbs, \Mpdf\Output\Destination::FILE);
    clearstatcache(true, $pdfAbs);

    if (!is_file($pdfAbs) || filesize($pdfAbs) < 800) {
        throw new RuntimeException('A generált PDF üres vagy hibás: ' . $pdfAbs);
    }

    return [
        'abs'      => $pdfAbs,
        'rel'      => $pdfRel,
        'filename' => $filename,
    ];
}

<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Külsős átadás-átvételi PDF előállítás.
 * A dokumentum HTML sablonból készül, majd SimplePDF-vel kerül renderelésre.
 */

function warehouse_external_pdf_vendor_autoload(): string {
    return __DIR__ . '/../vendor/autoload.php';
}

function warehouse_external_pdf_render_template(string $tplAbs, array $vars): string {
    if (!is_file($tplAbs)) {
        throw new RuntimeException('PDF sablon nem található: ' . $tplAbs);
    }
    $html = (string)file_get_contents($tplAbs);
    foreach ($vars as $k => $v) {
        $html = str_replace('{{' . $k . '}}', (string)$v, $html);
    }
    $html = (string)preg_replace('/\{\{[A-Z0-9_]+\}\}/', '', $html);
    return $html;
}

function warehouse_external_pdf_image_tag(string $src, int $maxWidthMm = 70, int $maxHeightMm = 28, string $class = 'sigimg'): string {
    $src = trim($src);
    if ($src === '') {
        return '';
    }

    if (preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $src) === 1) {
        return '<img class="' . $class . '" style="max-width: ' . $maxWidthMm . 'mm; max-height: ' . $maxHeightMm . 'mm;" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '">';
    }

    if (!is_file($src)) {
        return '';
    }

    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
    $b64 = base64_encode((string)file_get_contents($src));
    return '<img class="' . $class . '" style="max-width: ' . $maxWidthMm . 'mm; max-height: ' . $maxHeightMm . 'mm;" src="data:' . $mime . ';base64,' . $b64 . '">';
}

function warehouse_external_pdf_items_table_html(array $items): string {
    $rows = '';
    $i = 1;
    foreach ($items as $item) {
        $sku = htmlspecialchars((string)($item['sku'] ?? ''), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string)($item['material_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $qty = htmlspecialchars(warehouse_format_quantity($item['quantity'] ?? ''), ENT_QUOTES, 'UTF-8');
        $unit = htmlspecialchars((string)($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
        $category = trim((string)($item['category_name'] ?? ''));
        $category = $category !== '' ? htmlspecialchars($category, ENT_QUOTES, 'UTF-8') : '—';
        $identifierValues = array_values(array_map(static fn(array $identifier): string => warehouse_material_identifier_display_value($identifier), (array)($item['identifiers'] ?? [])));
        $identifierHtml = '';
        if ($identifierValues !== []) {
            $identifierLabel = htmlspecialchars(warehouse_material_identifier_value_label((array)$item), ENT_QUOTES, 'UTF-8');
            $identifierHtml = '<div class="muted" style="font-size:9pt; margin-top:1mm;">' . $identifierLabel . ': ' . htmlspecialchars(implode(', ', $identifierValues), ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $rows .= '<tr>'
            . '<td>' . $i . '</td>'
            . '<td><code>' . $sku . '</code></td>'
            . '<td>' . $name . $identifierHtml . '</td>'
            . '<td>' . $category . '</td>'
            . '<td style="text-align:right;">' . $qty . ' ' . $unit . '</td>'
            . '</tr>';
        $i++;
    }

    return '<table class="assets"><thead><tr><th>#</th><th>Cikkszám</th><th>Megnevezés</th><th>Kategória</th><th>Mennyiség</th></tr></thead><tbody>' . $rows . '</tbody></table>';
}

function warehouse_external_pdf_make_mpdf(): \Mpdf\Mpdf {
    $autoload = warehouse_external_pdf_vendor_autoload();
    if (!is_file($autoload)) {
        throw new RuntimeException('Hiányzik a vendor/autoload.php. Futtasd a warehousemgr könyvtárban: composer require mpdf/mpdf');
    }
    require_once $autoload;

    $tmp = __DIR__ . '/../storage/mpdf';
    if (!is_dir($tmp) && !@mkdir($tmp, 0775, true) && !is_dir($tmp)) {
        throw new RuntimeException('Nem sikerült létrehozni az mPDF temp könyvtárat: ' . $tmp);
    }

    return new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => 'dejavusans',
        'tempDir' => $tmp,
    ]);
}

function warehouse_external_pdf_output_file(array $transfer): array {
    $storageDir = __DIR__ . '/../storage/documents/external_transfer';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Nem sikerült létrehozni a PDF könyvtárat: ' . $storageDir);
    }

    $ref = trim((string)($transfer['reference_no'] ?? ''));
    if ($ref === '') {
        $ref = 'kulso_atadas_' . (int)($transfer['id'] ?? 0);
    }
    $safeRef = preg_replace('/[^A-Za-z0-9_-]+/', '_', $ref) ?: ('kulso_atadas_' . (int)($transfer['id'] ?? 0));
    $filename = $safeRef . '.pdf';
    $abs = $storageDir . '/' . $filename;
    $rel = '/storage/documents/external_transfer/' . $filename;
    return [$abs, $rel, $filename];
}

function warehouse_generate_external_transfer_pdf_mpdf(array $config, array $transfer): array {
    $transferId = (int)($transfer['id'] ?? 0);
    if ($transferId < 1) {
        throw new RuntimeException('Érvénytelen külsős átadás rekord.');
    }
    if (warehouse_transfer_type_normalize((string)($transfer['transfer_type'] ?? 'internal')) !== 'external') {
        throw new RuntimeException('Csak külsős átadáshoz készíthető szállítólevél PDF.');
    }

    [$pdfAbs, $pdfRel, $filename] = warehouse_external_pdf_output_file($transfer);
    $tplAbs = __DIR__ . '/../templates/pdf/external_handover.html';

    $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.png';
    if (!is_file($logoAbs)) {
        $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.jpg';
    }
    $logo = is_file($logoAbs) ? warehouse_external_pdf_image_tag($logoAbs, 42, 20, 'logoimg') : '';

    $signature = warehouse_external_pdf_image_tag((string)($transfer['receiver_signature_data'] ?? ''), 72, 28, 'sigimg');

    $requestedAt = trim((string)($transfer['requested_at'] ?? ''));
    $acceptedAt = trim((string)($transfer['accepted_at'] ?? ''));
    $handoverAt = $acceptedAt !== '' ? $acceptedAt : $requestedAt;
    $handedBy = trim((string)($transfer['accepted_by_name'] ?? ''));
    if ($handedBy === '') {
        $handedBy = trim((string)($transfer['requested_by_name'] ?? ''));
    }

    $html = warehouse_external_pdf_render_template($tplAbs, [
        'LOGO' => $logo,
        'TITLE' => 'Szállítólevél / Átadás-átvételi jegyzőkönyv',
        'REFERENCE_NO' => htmlspecialchars((string)($transfer['reference_no'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'HANDOVER_AT' => htmlspecialchars($handoverAt, ENT_QUOTES, 'UTF-8'),
        'HANDED_BY' => htmlspecialchars($handedBy, ENT_QUOTES, 'UTF-8'),
        'SOURCE_WAREHOUSE' => htmlspecialchars(trim((string)($transfer['source_warehouse_name'] ?? '') . ' (' . (string)($transfer['source_warehouse_code'] ?? '') . ')'), ENT_QUOTES, 'UTF-8'),
        'TARGET_WAREHOUSE' => htmlspecialchars(trim((string)($transfer['target_warehouse_name'] ?? '') . ' (' . (string)($transfer['target_warehouse_code'] ?? '') . ')'), ENT_QUOTES, 'UTF-8'),
        'PARTNER_NAME' => htmlspecialchars((string)($transfer['partner_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'RECEIVER_NAME' => htmlspecialchars((string)($transfer['receiver_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'RECEIVER_PHONE' => htmlspecialchars((string)($transfer['receiver_phone'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'RECEIVER_EMAIL' => htmlspecialchars((string)($transfer['receiver_email'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'PROJECT_NO' => htmlspecialchars((string)($transfer['project_no'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'NOTE' => nl2br(htmlspecialchars((string)($transfer['note'] ?? ''), ENT_QUOTES, 'UTF-8')),
        'ITEMS_TABLE' => warehouse_external_pdf_items_table_html((array)($transfer['items'] ?? [])),
        'SIGNATURE' => $signature,
        'FOOTER_NOTE' => '&copy; Perfect-Phone - A dokumentum elektronikusan készült. - www.perfect-phone.hu',
    ]);

    $mpdf = warehouse_external_pdf_make_mpdf();
    $mpdf->WriteHTML($html);
    $mpdf->Output($pdfAbs, \Mpdf\Output\Destination::FILE);
    clearstatcache(true, $pdfAbs);
    if (!is_file($pdfAbs) || filesize($pdfAbs) < 800) {
        throw new RuntimeException('A generált PDF üres vagy hibás: ' . $pdfAbs);
    }

    return [
        'abs' => $pdfAbs,
        'rel' => $pdfRel,
        'filename' => $filename,
    ];
}

<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Raktárkészlet lista PDF / nyomtatási nézet generálása a jelenlegi szűrők alapján.
 */

function warehouse_stock_pdf_vendor_autoload(): string {
    return __DIR__ . '/../vendor/autoload.php';
}

function warehouse_stock_pdf_render_template(string $tplAbs, array $vars): string {
    if (!is_file($tplAbs)) {
        throw new RuntimeException('PDF sablon nem található: ' . $tplAbs);
    }
    $html = (string)file_get_contents($tplAbs);
    foreach ($vars as $k => $v) {
        $html = str_replace('{{' . $k . '}}', (string)$v, $html);
    }
    return (string)preg_replace('/\{\{[A-Z0-9_]+\}\}/', '', $html);
}

function warehouse_stock_pdf_make_mpdf(): \Mpdf\Mpdf {
    $autoload = warehouse_stock_pdf_vendor_autoload();
    if (!is_file($autoload)) {
        throw new RuntimeException('Hiányzik a vendor/autoload.php. A PDF exporthoz ugyanaz az mPDF függőség kell, mint a külsős szállítólevélhez.');
    }
    require_once $autoload;

    $tmp = __DIR__ . '/../storage/mpdf';
    if (!is_dir($tmp) && !@mkdir($tmp, 0775, true) && !is_dir($tmp)) {
        throw new RuntimeException('Nem sikerült létrehozni az mPDF temp könyvtárat: ' . $tmp);
    }

    return new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'default_font' => 'dejavusans',
        'tempDir' => $tmp,
        'margin_top' => 9,
        'margin_bottom' => 12,
        'margin_left' => 8,
        'margin_right' => 8,
    ]);
}

function warehouse_stock_report_output_file(bool $detailed = false): array {
    $storageDir = __DIR__ . '/../storage/documents/stock_reports';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Nem sikerült létrehozni a készlet riport könyvtárat: ' . $storageDir);
    }

    $stamp = date('Ymd_His');
    $filename = $detailed
        ? 'raktarkeszlet_azonositokkal_' . $stamp . '.pdf'
        : 'raktarkeszlet_' . $stamp . '.pdf';
    $abs = $storageDir . '/' . $filename;
    $rel = '/storage/documents/stock_reports/' . $filename;
    return [$abs, $rel, $filename];
}

function warehouse_stock_report_filter_label(array $filters, array $allAccessibleWarehouses): array {
    $warehouseLabel = 'Mindegyik';
    $warehouseId = (int)($filters['warehouse_id'] ?? 0);
    if ($warehouseId > 0) {
        foreach ($allAccessibleWarehouses as $warehouse) {
            if ((int)($warehouse['id'] ?? 0) === $warehouseId) {
                $warehouseLabel = trim((string)($warehouse['name'] ?? '') . ' (' . (string)($warehouse['code'] ?? '') . ')');
                break;
            }
        }
    }

    return [
        'warehouse' => $warehouseLabel,
        'category_name' => trim((string)($filters['category_name'] ?? '')) !== '' ? trim((string)$filters['category_name']) : 'Mindegyik',
        'q' => trim((string)($filters['q'] ?? '')) !== '' ? trim((string)$filters['q']) : '—',
        'low_only' => (int)($filters['low_only'] ?? 0) === 1 ? 'Igen' : 'Nem',
        'include_archived' => (int)($filters['include_archived'] ?? 0) === 1 ? 'Igen' : 'Nem',
        'include_zero' => (int)($filters['include_zero'] ?? 0) === 1 ? 'Igen' : 'Nem',
    ];
}

function warehouse_stock_report_summary_html(array $filters, array $allAccessibleWarehouses, int $rowCount, string $generatedAt, string $generatedBy): string {
    $labels = warehouse_stock_report_filter_label($filters, $allAccessibleWarehouses);
    $cells = [
        ['Raktár', $labels['warehouse']],
        ['Kategória', $labels['category_name']],
        ['Keresés', $labels['q']],
        ['Csak minimum alatt', $labels['low_only']],
        ['0 készlet is', $labels['include_zero']],
        ['Archív is', $labels['include_archived']],
        ['Találatok száma', $rowCount . ' tétel'],
        ['Generálás ideje', $generatedAt],
        ['Készítette', $generatedBy !== '' ? $generatedBy : '—'],
    ];

    $html = '<table><tr>';
    foreach ($cells as $index => [$label, $value]) {
        $html .= '<td><div class="label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div><div class="value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</div></td>';
        if (($index + 1) % 4 === 0 && $index + 1 < count($cells)) {
            $html .= '</tr><tr>';
        }
    }
    $remainder = count($cells) % 4;
    if ($remainder !== 0) {
        for ($i = $remainder; $i < 4; $i++) {
            $html .= '<td></td>';
        }
    }
    $html .= '</tr></table>';
    return $html;
}

function warehouse_stock_report_table_html(array $rows, array $stockIdentifierMap, bool $archiveFeatureReady): string {
    $html = '';
    $i = 1;
    foreach ($rows as $row) {
        $identifierKey = ((int)($row['warehouse_id'] ?? 0)) . ':' . ((int)($row['material_id'] ?? 0));
        $identifierCount = count($stockIdentifierMap[$identifierKey] ?? []);
        $isArchived = $archiveFeatureReady && (int)($row['material_is_archived'] ?? 0) === 1;

        $html .= '<tr>'
            . '<td class="num">' . $i . '</td>'
            . '<td>' . htmlspecialchars((string)($row['warehouse_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '<div class="muted">' . htmlspecialchars((string)($row['warehouse_code'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></td>'
            . '<td><code>' . htmlspecialchars((string)($row['sku'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code></td>'
            . '<td>' . htmlspecialchars((string)($row['material_name'] ?? ''), ENT_QUOTES, 'UTF-8')
                . ($isArchived ? '<div class="muted"><strong>Archivált anyag</strong></div>' : '')
                . ((int)($row['material_is_active'] ?? 0) !== 1 ? '<div class="muted"><strong>Inaktív anyag</strong></div>' : '')
                . '</td>'
            . '<td>' . htmlspecialchars((string)($row['category_name'] ?? '—'), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars((string)($row['unit'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td class="num">' . htmlspecialchars(warehouse_format_quantity($row['quantity'] ?? 0), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . (((int)($row['is_identified'] ?? 0) === 1) ? 'Igen' : 'Nem') . '</td>'
            . '<td class="num">' . (((int)($row['is_identified'] ?? 0) === 1) ? $identifierCount : 0) . '</td>'
            . '<td>' . htmlspecialchars((string)($row['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
        $i++;
    }

    if ($html === '') {
        $html = '<tr><td colspan="10" class="empty">Nincs megjeleníthető készletadat.</td></tr>';
    }

    return '<table class="tbl compact"><thead><tr>'
        . '<th class="num">#</th><th>Raktár</th><th>Cikkszám</th><th>Megnevezés</th><th>Kategória</th><th>ME</th><th class="num">Készlet</th><th>Azonosítós</th><th class="num">Azonosító db</th><th>Frissítés</th>'
        . '</tr></thead><tbody>' . $html . '</tbody></table>';
}

function warehouse_stock_identifier_matches_search(string $displayValue, string $searchQuery): bool {
    $displayValue = trim($displayValue);
    $searchQuery = trim($searchQuery);
    if ($displayValue === '' || $searchQuery === '') {
        return false;
    }
    return stripos($displayValue, $searchQuery) !== false;
}

function warehouse_stock_report_identifier_details_html(array $rows, array $stockIdentifierMap, string $searchQuery): string {
    $sections = [];
    foreach ($rows as $row) {
        if ((int)($row['is_identified'] ?? 0) !== 1) {
            continue;
        }
        $identifierKey = ((int)($row['warehouse_id'] ?? 0)) . ':' . ((int)($row['material_id'] ?? 0));
        $identifierRows = $stockIdentifierMap[$identifierKey] ?? [];
        if (!$identifierRows) {
            continue;
        }

        $prepared = [];
        foreach ($identifierRows as $identifierRow) {
            $display = trim(warehouse_material_identifier_display_value((array)$identifierRow));
            if ($display === '') {
                continue;
            }
            $prepared[] = [
                'display' => $display,
                'note' => trim((string)($identifierRow['note'] ?? '')),
                'created_at' => trim((string)($identifierRow['created_at'] ?? '')),
                'is_match' => warehouse_stock_identifier_matches_search($display, $searchQuery),
            ];
        }
        if (!$prepared) {
            continue;
        }

        usort($prepared, static function (array $a, array $b): int {
            $matchCompare = (int)$b['is_match'] <=> (int)$a['is_match'];
            if ($matchCompare !== 0) {
                return $matchCompare;
            }
            return strcmp((string)$a['display'], (string)$b['display']);
        });

        $materialHeader = htmlspecialchars((string)($row['material_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $warehouseHeader = htmlspecialchars((string)($row['warehouse_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sku = htmlspecialchars((string)($row['sku'] ?? ''), ENT_QUOTES, 'UTF-8');
        $identifierLabel = htmlspecialchars(warehouse_material_identifier_value_label((array)$row), ENT_QUOTES, 'UTF-8');

        $tableRows = '';
        $index = 1;
        foreach ($prepared as $identifier) {
            $tableRows .= '<tr' . ($identifier['is_match'] ? ' class="match-row"' : '') . '>'
                . '<td class="num">' . $index . '</td>'
                . '<td><code>' . htmlspecialchars($identifier['display'], ENT_QUOTES, 'UTF-8') . '</code>'
                . ($identifier['is_match'] ? ' <span class="chip">Találat</span>' : '')
                . '</td>'
                . '<td>' . ($identifier['note'] !== '' ? htmlspecialchars($identifier['note'], ENT_QUOTES, 'UTF-8') : '—') . '</td>'
                . '<td>' . ($identifier['created_at'] !== '' ? htmlspecialchars($identifier['created_at'], ENT_QUOTES, 'UTF-8') : '—') . '</td>'
                . '</tr>';
            $index++;
        }

        $sections[] = '<div class="detail-block">'
            . '<div class="detail-head"><strong>' . $materialHeader . '</strong> <span class="muted">[' . $sku . ']</span> · ' . $warehouseHeader . '</div>'
            . '<table class="tbl detail"><thead><tr><th class="num">#</th><th>' . $identifierLabel . '</th><th>Megjegyzés</th><th>Rögzítve</th></tr></thead><tbody>'
            . $tableRows
            . '</tbody></table></div>';
    }

    if (!$sections) {
        return '<div class="empty-block">A jelenlegi szűréshez nem tartozik megjeleníthető azonosítólista.</div>';
    }

    return '<div class="detail-title">Azonosító részletek</div>' . implode('', $sections);
}

function warehouse_generate_stock_report_pdf(array $config, array $filters, array $rows, array $allAccessibleWarehouses, array $stockIdentifierMap, bool $detailed = false): array {
    [$pdfAbs, $pdfRel, $filename] = warehouse_stock_report_output_file($detailed);
    $tplAbs = __DIR__ . '/../templates/pdf/stock_report.html';
    $archiveFeatureReady = warehouse_material_archive_feature_ready($config);
    $generatedAt = date('Y-m-d H:i:s');
    $generatedBy = trim((string)(function_exists('current_auth_display_name') ? current_auth_display_name() : ''));
    $searchQuery = trim((string)($filters['q'] ?? ''));

    $html = warehouse_stock_pdf_render_template($tplAbs, [
        'TITLE' => $detailed ? 'Raktárkészlet lista azonosítókkal' : 'Raktárkészlet lista',
        'SUBTITLE' => $detailed ? 'Szűrt, nyomtatható raktárkészlet lista részletes azonosító blokkokkal' : 'Szűrt, nyomtatható raktárkészlet lista',
        'SUMMARY_BLOCK' => warehouse_stock_report_summary_html($filters, $allAccessibleWarehouses, count($rows), $generatedAt, $generatedBy),
        'TABLE_BLOCK' => warehouse_stock_report_table_html($rows, $stockIdentifierMap, $archiveFeatureReady),
        'DETAIL_BLOCK' => $detailed ? warehouse_stock_report_identifier_details_html($rows, $stockIdentifierMap, $searchQuery) : '',
    ]);

    $mpdf = warehouse_stock_pdf_make_mpdf();
    $mpdf->SetTitle($detailed ? 'Raktárkészlet lista azonosítókkal' : 'Raktárkészlet lista');
    $mpdf->SetAuthor($generatedBy !== '' ? $generatedBy : 'warehousemgr');
    $mpdf->SetHTMLFooter('<div style="font-size:7.5pt; color:#6b7280; border-top:1px solid #d1d5db; padding-top:2mm;">Raktárkészlet lista' . ($detailed ? ' azonosítókkal' : '') . ' · {DATE Y-m-d H:i} · {PAGENO}/{nbpg}</div>');
    $mpdf->WriteHTML($html);
    $mpdf->Output($pdfAbs, \Mpdf\Output\Destination::FILE);
    clearstatcache(true, $pdfAbs);
    if (!is_file($pdfAbs) || filesize($pdfAbs) < 800) {
        throw new RuntimeException('A generált készlet PDF üres vagy hibás: ' . $pdfAbs);
    }

    return [
        'abs' => $pdfAbs,
        'rel' => $pdfRel,
        'filename' => $filename,
    ];
}

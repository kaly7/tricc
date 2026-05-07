<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$today   = date('Y-m-d');
$defFrom = date('Y-m-d', strtotime('-30 days'));
$defTo   = $today;

$filterFrom   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']   ?? '') ? $_GET['from']   : $defFrom;
$filterTo     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']     ?? '') ? $_GET['to']     : $defTo;
$filterEmp    = filter_input(INPUT_GET, 'employee_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$filterStatus = in_array($_GET['status'] ?? '', ['aktív','passzív','vár','archív']) ? $_GET['status'] : '';
$filterTaskId = filter_input(INPUT_GET, 'task_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$format       = in_array($_GET['format'] ?? '', ['csv','xlsx','pdf']) ? $_GET['format'] : 'csv';

$params = [$filterFrom, $filterTo];
$where  = ['ta.task_date BETWEEN ? AND ?'];

if ($filterEmp) {
  $where[]  = 'ta.employee_id = ?';
  $params[] = $filterEmp;
}
if ($filterStatus) {
  $where[]  = 't.status = ?';
  $params[] = $filterStatus;
}
if ($filterTaskId) {
  $where[]  = 't.id = ?';
  $params[] = $filterTaskId;
}

$sql = "
  SELECT ta.task_date,
         t.id AS task_id, t.title, t.status, t.color,
         e.full_name
  FROM task_assignments ta
  JOIN tasks t ON t.id = ta.task_id
  JOIN hr.employees e ON e.id = ta.employee_id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY ta.task_date ASC, t.title, e.full_name
";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Csoportosítás
$grouped   = [];
$empHours  = [];
$taskHours = [];

foreach ($rows as $r) {
    $key = $r['task_date'] . '|' . $r['task_id'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'task_date' => $r['task_date'],
            'title'     => $r['title'],
            'status'    => $r['status'],
            'color'     => ltrim($r['color'], '#'),
            'employees' => [],
        ];
    }
    $grouped[$key]['employees'][]  = $r['full_name'];
    $empHours[$r['full_name']]     = ($empHours[$r['full_name']]  ?? 0) + 8;
    $taskHours[$r['title']]        = ($taskHours[$r['title']]     ?? 0) + 8;
}
arsort($empHours);
arsort($taskHours);

$totalEntries = count($rows);
$filename = 'napiterv_' . $filterFrom . '_' . $filterTo;

// ── CSV ──────────────────────────────────────────────────────────────────────
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['Dátum', 'Feladat', 'Státusz', 'Dolgozók', 'Munkaóra'], ';');
    foreach ($grouped as $g) {
        fputcsv($out, [
            $g['task_date'],
            $g['title'],
            $g['status'],
            implode(', ', $g['employees']),
            count($g['employees']) * 8,
        ], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['ÖSSZESÍTŐ – DOLGOZÓNKÉNT'], ';');
    fputcsv($out, ['Dolgozó', 'Munkaóra'], ';');
    foreach ($empHours as $name => $h) {
        fputcsv($out, [$name, $h], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['ÖSSZESÍTŐ – FELADATONKÉNT'], ';');
    fputcsv($out, ['Feladat', 'Munkaóra'], ';');
    foreach ($taskHours as $title => $h) {
        fputcsv($out, [$title, $h], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['Összesen', $totalEntries . ' hozzárendelés', $totalEntries * 8 . ' h'], ';');
    fclose($out);
    exit;
}

// ── XLSX ─────────────────────────────────────────────────────────────────────
if ($format === 'xlsx') {
    use_xlsx($grouped, $empHours, $taskHours, $totalEntries, $filterFrom, $filterTo, $filename);
    exit;
}

// ── PDF ──────────────────────────────────────────────────────────────────────
if ($format === 'pdf') {
    use_pdf($grouped, $empHours, $taskHours, $totalEntries, $filterFrom, $filterTo, $filename);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────

function use_xlsx(array $grouped, array $empHours, array $taskHours, int $total, string $from, string $to, string $filename): void
{
    $sp  = new Spreadsheet();
    $sp->getProperties()->setTitle('Napiterv előzmények');

    // ── 1. lap: Lista ──
    $ws = $sp->getActiveSheet()->setTitle('Lista');
    $ws->getColumnDimension('A')->setWidth(13);
    $ws->getColumnDimension('B')->setWidth(30);
    $ws->getColumnDimension('C')->setWidth(12);
    $ws->getColumnDimension('D')->setWidth(40);
    $ws->getColumnDimension('E')->setWidth(12);

    $hStyle = [
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '212529']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ];
    $ws->fromArray(['Dátum','Feladat','Státusz','Dolgozók','Munkaóra (h)'], null, 'A1');
    $ws->getStyle('A1:E1')->applyFromArray($hStyle);

    $row = 2;
    foreach ($grouped as $g) {
        $hrs = count($g['employees']) * 8;
        $ws->fromArray([
            $g['task_date'],
            $g['title'],
            $g['status'],
            implode(', ', $g['employees']),
            $hrs,
        ], null, 'A' . $row);
        // Feladatszín a B cellában
        $hex = preg_match('/^[0-9A-Fa-f]{6}$/', $g['color']) ? strtoupper($g['color']) : 'FFFFFF';
        $ws->getStyle('B' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($hex);
        $ws->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
    }

    // ── 2. lap: Összesítő ──
    $ws2 = $sp->createSheet()->setTitle('Összesítő');
    $sp->setActiveSheetIndex(1);

    $ws2->getColumnDimension('A')->setWidth(32);
    $ws2->getColumnDimension('B')->setWidth(14);
    $ws2->getColumnDimension('D')->setWidth(32);
    $ws2->getColumnDimension('E')->setWidth(14);

    $ws2->setCellValue('A1', 'Dolgozónként')->getStyle('A1');
    $ws2->getStyle('A1')->applyFromArray($hStyle);
    $ws2->setCellValue('B1', 'Munkaóra (h)');
    $ws2->getStyle('B1')->applyFromArray($hStyle);
    $r = 2;
    foreach ($empHours as $name => $h) {
        $ws2->setCellValue('A' . $r, $name);
        $ws2->setCellValue('B' . $r, $h);
        $r++;
    }
    $ws2->setCellValue('A' . $r, 'Összesen');
    $ws2->setCellValue('B' . $r, array_sum($empHours));
    $ws2->getStyle('A' . $r . ':B' . $r)->getFont()->setBold(true);

    $ws2->setCellValue('D1', 'Feladatonként')->getStyle('D1');
    $ws2->getStyle('D1')->applyFromArray($hStyle);
    $ws2->setCellValue('E1', 'Munkaóra (h)');
    $ws2->getStyle('E1')->applyFromArray($hStyle);
    $r = 2;
    foreach ($taskHours as $title => $h) {
        $ws2->setCellValue('D' . $r, $title);
        $ws2->setCellValue('E' . $r, $h);
        $r++;
    }
    $ws2->setCellValue('D' . $r, 'Összesen');
    $ws2->setCellValue('E' . $r, array_sum($taskHours));
    $ws2->getStyle('D' . $r . ':E' . $r)->getFont()->setBold(true);

    $sp->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($sp))->save('php://output');
}

function use_pdf(array $grouped, array $empHours, array $taskHours, int $total, string $from, string $to, string $filename): void
{
    $tmpDir = __DIR__ . '/../storage/tmp';
    $mpdf = new \Mpdf\Mpdf([
        'tempDir'       => $tmpDir,
        'margin_top'    => 14,
        'margin_bottom' => 14,
        'margin_left'   => 10,
        'margin_right'  => 10,
        'orientation'   => 'L',
        'default_font_size' => 9,
    ]);
    $mpdf->SetTitle('Napiterv előzmények');

    $css = '
        body  { font-family: dejavusans; font-size: 9pt; }
        h2    { font-size: 12pt; margin: 0 0 3px 0; }
        .sub  { color: #666; font-size: 8pt; margin-bottom: 10px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 14px; }
        th    { background: #212529; color: #ffffff; padding: 5px 6px; text-align: left; font-size: 8pt; }
        td    { padding: 3px 6px; font-size: 8.5pt; border-bottom: 1px solid #dee2e6; }
        .foot td { font-weight: bold; background: #e9ecef; }
        .dot  { display: inline-block; width: 9px; height: 9px; margin-right: 4px; }
        .badge { background: #555; color: #fff; padding: 1px 4px; font-size: 7.5pt; }
    ';

    $statusColors = ['aktív'=>'#198754','passzív'=>'#6c757d','vár'=>'#dc2626','archív'=>'#343a40'];

    // ── Fejléc ──
    $html  = "<style>$css</style>";
    $html .= '<h2>Napiterv – feladat-előzmények</h2>';
    $html .= '<div class="sub">' . htmlspecialchars($from) . ' – ' . htmlspecialchars($to)
           . ' &nbsp;|&nbsp; ' . count($grouped) . ' sor, ' . $total . ' hozzárendelés, ' . ($total * 8) . ' munkaóra</div>';

    // ── Lista tábla ──
    $html .= '<table>';
    $html .= '<thead><tr>'
           . '<th style="width:88px">Dátum</th>'
           . '<th>Feladat</th>'
           . '<th style="width:65px">Státusz</th>'
           . '<th>Dolgozók</th>'
           . '<th style="width:45px;text-align:right">Óra</th>'
           . '</tr></thead><tbody>';

    $odd = true;
    foreach ($grouped as $g) {
        $sc   = $statusColors[$g['status']] ?? '#6c757d';
        $hrs  = count($g['employees']) * 8;
        $hex  = preg_match('/^[0-9A-Fa-f]{6}$/', $g['color']) ? '#' . $g['color'] : '#cccccc';
        $bg   = $odd ? '' : ' style="background:#f8f9fa"';
        $odd  = !$odd;
        $html .= "<tr$bg>";
        $html .= '<td>' . htmlspecialchars($g['task_date']) . '</td>';
        $html .= '<td><span class="dot" style="background:' . $hex . '">&nbsp;</span>' . htmlspecialchars($g['title']) . '</td>';
        $html .= '<td><span class="badge" style="background:' . $sc . '">' . htmlspecialchars($g['status']) . '</span></td>';
        $html .= '<td>' . htmlspecialchars(implode(', ', $g['employees'])) . '</td>';
        $html .= '<td style="text-align:right">' . $hrs . '</td>';
        $html .= '</tr>';
    }
    $html .= '<tr class="foot"><td colspan="4">Összesen</td><td style="text-align:right">' . ($total * 8) . '</td></tr>';
    $html .= '</tbody></table>';

    // ── Összesítők – nested table trick mPDF-hez ──
    $empRows  = array_keys($empHours);
    $taskRows = array_keys($taskHours);

    $empTable  = '<table style="width:100%"><thead><tr><th>Dolgozó</th><th style="width:50px;text-align:right">Óra</th></tr></thead><tbody>';
    $odd = true;
    foreach ($empHours as $name => $h) {
        $bg = $odd ? '' : ' style="background:#f8f9fa"'; $odd = !$odd;
        $empTable .= "<tr$bg><td>" . htmlspecialchars($name) . '</td><td style="text-align:right">' . $h . '</td></tr>';
    }
    $empTable .= '<tr class="foot"><td>Összesen</td><td style="text-align:right">' . array_sum($empHours) . '</td></tr>';
    $empTable .= '</tbody></table>';

    $taskTable = '<table style="width:100%"><thead><tr><th>Feladat</th><th style="width:50px;text-align:right">Óra</th></tr></thead><tbody>';
    $odd = true;
    foreach ($taskHours as $title => $h) {
        $bg = $odd ? '' : ' style="background:#f8f9fa"'; $odd = !$odd;
        $taskTable .= "<tr$bg><td>" . htmlspecialchars($title) . '</td><td style="text-align:right">' . $h . '</td></tr>';
    }
    $taskTable .= '<tr class="foot"><td>Összesen</td><td style="text-align:right">' . array_sum($taskHours) . '</td></tr>';
    $taskTable .= '</tbody></table>';

    $html .= '<table style="width:100%;border:none"><tr>'
           . '<td style="width:49%;vertical-align:top;border:none;padding-right:8px">' . $empTable . '</td>'
           . '<td style="width:2%;border:none"></td>'
           . '<td style="width:49%;vertical-align:top;border:none;padding-left:8px">' . $taskTable . '</td>'
           . '</tr></table>';

    $mpdf->WriteHTML($html);
    $mpdf->Output($filename . '.pdf', 'D');
}

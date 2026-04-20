<?php
declare(strict_types=1);

function _assetmgr_vendor_autoload(): string {
  return __DIR__ . '/../vendor/autoload.php';
}

function _pdf_data_img_tag(string $absPath, int $maxWidthMm = 70, int $maxHeightMm = 28, string $class = 'sigimg'): string {
  if ($absPath === '' || !is_file($absPath)) return '';
  $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
  $mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'gif'         => 'image/gif',
    'webp'        => 'image/webp',
    default       => 'application/octet-stream',
  };
  $b64 = base64_encode((string)file_get_contents($absPath));
  return '<img class="'.$class.'" style="max-width: '.$maxWidthMm.'mm; max-height: '.$maxHeightMm.'mm;" src="data:'.$mime.';base64,'.$b64.'">';
}

function _render_template(string $tplAbs, array $vars): string {
  if (!is_file($tplAbs)) {
    throw new RuntimeException('PDF sablon nem található: ' . $tplAbs);
  }
  $html = (string)file_get_contents($tplAbs);
  foreach ($vars as $k => $v) {
    $html = str_replace('{{'.$k.'}}', (string)$v, $html);
  }
  $html = (string)preg_replace('/\{\{[A-Z0-9_]+\}\}/', '', $html);
  return $html;
}

function _assets_table_html(array $assets): string {
  $rows = '';
  $i = 1;
  foreach ($assets as $a) {
    $name = htmlspecialchars((string)($a['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $inv  = htmlspecialchars((string)($a['inventory'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ser  = htmlspecialchars((string)($a['serial'] ?? ''), ENT_QUOTES, 'UTF-8');
    $rows .= "<tr><td>{$i}</td><td>{$name}</td><td>{$inv}</td><td>{$ser}</td></tr>";
    $i++;
  }
  return '<table class="assets"><thead><tr><th>#</th><th>Név</th><th>Leltár/QR</th><th>Sorozat/SKU</th></tr></thead><tbody>'.$rows.'</tbody></table>';
}


function _toolbook_placeholder(): string {
  return '<span style="display:block; text-align:center; font-weight:700; font-size:10.5pt; letter-spacing:1.2mm; color:#111;">- - - -</span>';
}

function _toolbook_cell(mixed $value): string {
  $value = trim((string)$value);
  if ($value === '') return _toolbook_placeholder();
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function _assets_toolbook_table_html(array $assets): string {
  $rows = '';
  $i = 1;
  foreach ($assets as $a) {
    $name = _toolbook_cell($a['name'] ?? '');
    $inv  = _toolbook_cell($a['inventory'] ?? '');
    $ser  = _toolbook_cell($a['serial'] ?? '');
    $cat  = _toolbook_cell($a['categories'] ?? '');
    $note = _toolbook_cell($a['note'] ?? '');
    $rows .= "<tr><td>{$i}</td><td>{$name}</td><td>{$inv}</td><td>{$ser}</td><td>{$cat}</td><td>{$note}</td></tr>";
    $i++;
  }
  return '<table class="assets toolbook"><thead><tr><th>#</th><th>Megnevezés</th><th>Leltár/QR</th><th>Cikkszám</th><th>Kategória</th><th>Megjegyzés</th></tr></thead><tbody>'.$rows.'</tbody></table>';
}

function generate_employee_toolbook_pdf_html(array $data): string {
  $storageDir = __DIR__ . '/../storage/documents/toolbook';
  if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    throw new RuntimeException('Nem sikerült létrehozni a PDF könyvtárat: ' . $storageDir);
  }

  $pdfFile = 'szerszamkonyv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
  $pdfAbs  = $storageDir . '/' . $pdfFile;
  $tplAbs  = __DIR__ . '/../templates/pdf/toolbook.html';

  $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.png';
  $logo = is_file($logoAbs) ? _pdf_data_img_tag($logoAbs, 42, 20, 'logoimg') : '';

  $employeeName = htmlspecialchars((string)($data['employee'] ?? ''), ENT_QUOTES, 'UTF-8');

  $html = _render_template($tplAbs, [
    'LOGO'                    => $logo,
    'DOC_DATE'                => htmlspecialchars((string)($data['doc_date'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'PRINTED_BY'              => htmlspecialchars((string)($data['printed_by'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EMPLOYEE'                => $employeeName,
    'EMPLOYEE_STATUS'         => htmlspecialchars((string)($data['employee_status'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'FILTERS'                 => nl2br(htmlspecialchars((string)($data['filters'] ?? ''), ENT_QUOTES, 'UTF-8')),
    'ASSET_COUNT'             => (string)((int)($data['asset_count'] ?? 0)),
    'ASSETS_TABLE'            => _assets_toolbook_table_html((array)($data['assets'] ?? [])),
    'EMPLOYEE_SIGNATURE_NAME' => $employeeName,
  ]);

  $mpdf = _make_mpdf();
  $mpdf->mirrorMargins = false;
  $mpdf->WriteHTML($html);
  _finalize_pdf($mpdf, $pdfAbs);
  return '/storage/documents/toolbook/' . $pdfFile;
}


function _make_mpdf(): \Mpdf\Mpdf {
  if (!file_exists(_assetmgr_vendor_autoload())) {
    throw new RuntimeException('Hiányzik a vendor/autoload.php. Futtasd: composer install');
  }
  require_once _assetmgr_vendor_autoload();

  $tmp = __DIR__ . '/../storage/mpdf';
  if (!is_dir($tmp) && !@mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    throw new RuntimeException('Nem sikerült létrehozni az mPDF temp könyvtárat: ' . $tmp);
  }

  return new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'default_font' => 'dejavusans',
    'tempDir' => $tmp,
    'margin_top' => 12,
    'margin_right' => 12,
    'margin_bottom' => 18,
    'margin_left' => 12,
    'margin_footer' => 6,
  ]);
}

function _finalize_pdf(\Mpdf\Mpdf $mpdf, string $pdfAbs): void {
  $mpdf->Output($pdfAbs, \Mpdf\Output\Destination::FILE);
  clearstatcache(true, $pdfAbs);
  if (!is_file($pdfAbs) || filesize($pdfAbs) < 800) {
    throw new RuntimeException('A generált PDF üres vagy hibás: ' . $pdfAbs);
  }
}

function generate_external_handover_pdf_html(array $data): string {
  $storageDir = __DIR__ . '/../storage/documents/external_handover';
  if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    throw new RuntimeException('Nem sikerült létrehozni a PDF könyvtárat: ' . $storageDir);
  }

  $pdfFile = 'atadas_atvetel_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
  $pdfAbs  = $storageDir . '/' . $pdfFile;
  $tplAbs = __DIR__ . '/../templates/pdf/handover.html';

  $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.png';
  $logo = is_file($logoAbs) ? _pdf_data_img_tag($logoAbs, 42, 20, 'logoimg') : '';

  $sigTag = '';
  $sigAbs = (string)($data['signature_abs'] ?? '');
  if ($sigAbs !== '' && is_file($sigAbs)) {
    $sigTag = _pdf_data_img_tag($sigAbs, 72, 28, 'sigimg');
  }

  $photoTag = '';
  $photoAbs = (string)($data['asset_photo_abs'] ?? '');
  if ($photoAbs !== '' && is_file($photoAbs)) {
    $photoTag = _pdf_data_img_tag($photoAbs, 90, 60, 'photo');
  }

  $html = _render_template($tplAbs, [
    'LOGO'         => $logo,
    'ASSIGNED_AT'  => htmlspecialchars((string)($data['assigned_at'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'ASSIGNED_BY'  => htmlspecialchars((string)($data['assigned_by'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EXT_COMPANY'  => htmlspecialchars((string)($data['company'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EXT_CONTACT'  => htmlspecialchars((string)($data['contact'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EXT_PHONE'    => htmlspecialchars((string)($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EXT_EMAIL'    => htmlspecialchars((string)($data['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'COURIER_REF'  => htmlspecialchars((string)($data['courier_ref'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'NOTE'         => nl2br(htmlspecialchars((string)($data['note'] ?? ''), ENT_QUOTES, 'UTF-8')),
    'ASSETS_TABLE' => _assets_table_html((array)($data['assets'] ?? [])),
    'ASSET_PHOTO'  => $photoTag,
    'SIGNATURE'    => $sigTag,
  ]);

  $mpdf = _make_mpdf();
  $mpdf->WriteHTML($html);
  _finalize_pdf($mpdf, $pdfAbs);
  return '/storage/documents/external_handover/' . $pdfFile;
}

function generate_external_return_pdf_html(array $data): string {
  $storageDir = __DIR__ . '/../storage/documents/external_return';
  if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    throw new RuntimeException('Nem sikerült létrehozni a PDF könyvtárat: ' . $storageDir);
  }

  $pdfFile = 'visszavetel_atvetel_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
  $pdfAbs  = $storageDir . '/' . $pdfFile;
  $tplAbs = __DIR__ . '/../templates/pdf/return.html';

  $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.png';
  $logo = is_file($logoAbs) ? _pdf_data_img_tag($logoAbs, 42, 20, 'logoimg') : '';

  $sigTag = '';
  $sigAbs = (string)($data['signature_abs'] ?? '');
  if ($sigAbs !== '' && is_file($sigAbs)) {
    $sigTag = _pdf_data_img_tag($sigAbs, 72, 28, 'sigimg');
  }

  $html = _render_template($tplAbs, [
    'LOGO'         => $logo,
    'ASSIGNED_AT'  => htmlspecialchars((string)($data['assigned_at'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'ASSIGNED_BY'  => htmlspecialchars((string)($data['assigned_by'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'RETURNED_AT'  => htmlspecialchars((string)($data['returned_at'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'RETURNED_BY'  => htmlspecialchars((string)($data['returned_by'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'RETURNED_TO'  => htmlspecialchars((string)($data['returned_to'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EXT_COMPANY'  => htmlspecialchars((string)($data['company'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EXT_CONTACT'  => htmlspecialchars((string)($data['contact'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EXT_PHONE'    => htmlspecialchars((string)($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EXT_EMAIL'    => htmlspecialchars((string)($data['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'COURIER_REF'  => htmlspecialchars((string)($data['courier_ref'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'NOTE'         => nl2br(htmlspecialchars((string)($data['note'] ?? ''), ENT_QUOTES, 'UTF-8')),
    'RETURN_NOTE'  => nl2br(htmlspecialchars((string)($data['return_note'] ?? ''), ENT_QUOTES, 'UTF-8')),
    'ASSETS_TABLE' => _assets_table_html((array)($data['assets'] ?? [])),
    'SIGNATURE'    => $sigTag,
  ]);

  $mpdf = _make_mpdf();
  $mpdf->WriteHTML($html);
  _finalize_pdf($mpdf, $pdfAbs);
  return '/storage/documents/external_return/' . $pdfFile;
}


function generate_external_return_pdf(array $data): string {
  if (!isset($data['signature_abs']) && isset($data['signature_path_abs'])) {
    $data['signature_abs'] = $data['signature_path_abs'];
  }
  return generate_external_return_pdf_html($data);
}


function generate_warehouse_intake_pdf_html(array $data): string {
  $storageDir = __DIR__ . '/../storage/documents/warehouse_intake';
  if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    throw new RuntimeException('Nem sikerült létrehozni a PDF könyvtárat: ' . $storageDir);
  }

  $pdfFile = 'raktarba_vetel_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
  $pdfAbs  = $storageDir . '/' . $pdfFile;
  $tplAbs  = __DIR__ . '/../templates/pdf/warehouse_intake.html';

  $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.png';
  $logo = is_file($logoAbs) ? _pdf_data_img_tag($logoAbs, 42, 20, 'logoimg') : '';

  $html = _render_template($tplAbs, [
    'LOGO'         => $logo,
    'DOC_DATE'     => htmlspecialchars((string)($data['doc_date'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'PERFORMED_BY' => htmlspecialchars((string)($data['performed_by'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'WAREHOUSE'    => htmlspecialchars((string)($data['warehouse'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'NOTE'         => nl2br(htmlspecialchars((string)($data['note'] ?? ''), ENT_QUOTES, 'UTF-8')),
    'ASSETS_TABLE' => _assets_table_html((array)($data['assets'] ?? [])),
  ]);

  $mpdf = _make_mpdf();
  $mpdf->WriteHTML($html);
  _finalize_pdf($mpdf, $pdfAbs);
  return '/storage/documents/warehouse_intake/' . $pdfFile;
}


function generate_warehouse_issue_pdf_html(array $data): string {
  $storageDir = __DIR__ . '/../storage/documents/warehouse_issue';
  if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    throw new RuntimeException('Nem sikerült létrehozni a PDF könyvtárat: ' . $storageDir);
  }

  $pdfFile = 'raktari_kiadas_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
  $pdfAbs  = $storageDir . '/' . $pdfFile;
  $tplAbs  = __DIR__ . '/../templates/pdf/warehouse_issue.html';

  $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.png';
  $logo = is_file($logoAbs) ? _pdf_data_img_tag($logoAbs, 42, 20, 'logoimg') : '';

  $html = _render_template($tplAbs, [
    'LOGO'         => $logo,
    'DOC_DATE'     => htmlspecialchars((string)($data['doc_date'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'PERFORMED_BY' => htmlspecialchars((string)($data['performed_by'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'WAREHOUSE'    => htmlspecialchars((string)($data['warehouse'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'EMPLOYEE'     => htmlspecialchars((string)($data['employee'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'NOTE'         => nl2br(htmlspecialchars((string)($data['note'] ?? ''), ENT_QUOTES, 'UTF-8')),
    'ASSETS_TABLE' => _assets_table_html((array)($data['assets'] ?? [])),
  ]);

  $mpdf = _make_mpdf();
  $mpdf->WriteHTML($html);
  _finalize_pdf($mpdf, $pdfAbs);
  return '/storage/documents/warehouse_issue/' . $pdfFile;
}

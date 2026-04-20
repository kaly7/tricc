<?php
require_once __DIR__ . '/lib/simplepdf.php';

/**
 * Generate a return (external) protocol PDF.
 * Returns relative web path (starting with /storage/...) for the saved PDF.
 *
 * Expected keys in $ret:
 *  - 'assigned_at', 'returned_at'
 *  - 'assigned_by', 'returned_by', 'returned_to'
 *  - 'company','contact','phone','email'
 *  - 'courier_ref','note','return_note'
 *  - 'assets' => [ ['name'=>..., 'inventory'=>..., 'serial'=>..., 'photo_abs'=>...?], ...]
 *  - 'signature_path_abs' => absolute path to signature png (from handover)
 */
function generate_external_return_pdf(array $ret): string {
  $storageDir = __DIR__ . '/../storage/documents/external_return';
  if (!is_dir($storageDir)) mkdir($storageDir, 0775, true);

  // SimplePDF cannot embed PNG with alpha (RGBA). Convert such images to JPG on white background.
  $ensurePdfSafeImage = function(string $absPath) use ($storageDir): string {
    if (!is_file($absPath)) return $absPath;
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    if ($ext !== 'png') return $absPath;

    $fh = @fopen($absPath, 'rb');
    if (!$fh) return $absPath;
    $sig = fread($fh, 8);
    if ($sig !== "\x89PNG\r\n\x1a\n") { fclose($fh); return $absPath; }
    fread($fh, 4); // len
    $type = fread($fh, 4);
    if ($type !== 'IHDR') { fclose($fh); return $absPath; }
    $ihdr = fread($fh, 13);
    fclose($fh);
    if (strlen($ihdr) !== 13) return $absPath;
    $colorType = ord($ihdr[9]);
    $hasAlpha = ($colorType === 4 || $colorType === 6);
    if (!$hasAlpha) return $absPath;

    if (!function_exists('imagecreatefrompng')) return $absPath;
    $im = @imagecreatefrompng($absPath);
    if (!$im) return $absPath;
    $w = imagesx($im); $h = imagesy($im);
    $dst = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $w, $h, $white);
    imagealphablending($dst, true);
    imagecopy($dst, $im, 0, 0, 0, 0, $w, $h);

    $tmpDir = $storageDir . '/tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
    $out = $tmpDir . '/img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    imagejpeg($dst, $out, 92);
    imagedestroy($dst); imagedestroy($im);
    return $out;
  };

  $ts = date('Ymd_His');
  $rand = bin2hex(random_bytes(4));
  $pdfFile = "visszavetel_atvetel_{$ts}_{$rand}.pdf";
  $pdfAbs = $storageDir . '/' . $pdfFile;

  $pdf = new SimplePDF();
  $pdf->addPage();

  // Header
  $pdf->setFont('Helvetica', 16);
  $pdf->writeLine("Átadás-átvételi jegyzőkönyv – Visszavétel");
  $pdf->setFont('Helvetica', 11);
  $pdf->writeLine("Perfect-Phone Kft.");

  $logo = __DIR__ . '/../public/assets/perfect-phone-logo.png';
  if (is_file($logo)) {
    $pdf->image($ensurePdfSafeImage($logo), 430, 780, 120, 0);
  }

  $pdf->ln(8);
  $pdf->setFont('Helvetica', 12);
  $pdf->writeLine("Esemény adatai");
  $pdf->setFont('Helvetica', 11);

  $pdf->writeLine("Átadás ideje: " . ($ret['assigned_at'] ?? ''));
  $pdf->writeLine("Átadta (külsősnek): " . ($ret['assigned_by'] ?? ''));

  $pdf->writeLine("Visszavétel ideje: " . ($ret['returned_at'] ?? ''));
  $pdf->writeLine("Visszavette: " . ($ret['returned_by'] ?? ''));
  if (!empty($ret['returned_to'])) $pdf->writeLine("Visszakerült: " . ($ret['returned_to'] ?? ''));

  $pdf->ln(4);
  $pdf->writeLine("Külsős partner:");
  $pdf->writeLine("  Cég: " . ($ret['company'] ?? ''));
  if (!empty($ret['contact'])) $pdf->writeLine("  Kapcsolattartó: " . $ret['contact']);
  if (!empty($ret['phone']))   $pdf->writeLine("  Telefon: " . $ret['phone']);
  if (!empty($ret['email']))   $pdf->writeLine("  Email: " . $ret['email']);

  if (!empty($ret['courier_ref'])) $pdf->writeLine("Szállítólevél szám: " . $ret['courier_ref']);
  if (!empty($ret['note'])) $pdf->writeLine("Átadás megjegyzés: " . $ret['note']);
  if (!empty($ret['return_note'])) $pdf->writeLine("Visszavétel megjegyzés: " . $ret['return_note']);

  $pdf->ln(10);
  $pdf->setFont('Helvetica', 12);
  $pdf->writeLine("Eszközök");
  $pdf->setFont('Helvetica', 10);

  $assets = $ret['assets'] ?? [];
  $i = 1;
  foreach ($assets as $a) {
    $line = "{$i}. " . ($a['name'] ?? '');
    if (!empty($a['inventory'])) $line .= " | QR/Leltár: ".$a['inventory'];
    if (!empty($a['serial'])) $line .= " | SKU: ".$a['serial'];
    $pdf->writeLine($line);
    $i++;
  }

  foreach ($assets as $a) {
    if (!empty($a['photo_abs']) && is_file($a['photo_abs'])) {
      $pdf->ln(6);
      $pdf->setFont('Helvetica', 11);
      $pdf->writeLine("Eszköz fotó (első elérhető):");
      $pdf->image($ensurePdfSafeImage($a['photo_abs']), 60, 360, 220, 0);
      break;
    }
  }

  // Signature from original handover
  $pdf->ln(14);
  $pdf->setFont('Helvetica', 12);
  $pdf->writeLine("Átvevő aláírása (átadáskor rögzítve)");
  $sigAbs = $ret['signature_path_abs'] ?? '';
  if ($sigAbs && is_file($sigAbs)) {
    $pdf->image($ensurePdfSafeImage($sigAbs), 60, 160, 220, 0);
    $pdf->rect(55, 155, 230, 90);
  } else {
    $pdf->writeLine("(aláírás fájl nem található)");
  }

  $pdf->output($pdfAbs);
  return '/storage/documents/external_return/'.$pdfFile;
}

<?php
require_once __DIR__ . '/lib/simplepdf.php';

/**
 * Generate a handover PDF for external transfer.
 * Returns relative web path (starting with /storage/...) for the saved PDF.
 *
 * @param array $handover [
 *   'company' => string, 'contact'=>string|null, 'phone'=>string|null, 'email'=>string|null,
 *   'courier_ref'=>string|null, 'note'=>string|null,
 *   'assigned_at'=>string (Y-m-d H:i:s),
 *   'assigned_by'=>string (user full name/email),
 *   'assets' => [ ['name'=>..., 'serial'=>..., 'inventory'=>..., 'category'=>..., 'photo_path'=>...?], ... ],
 *   'signature_path_abs' => string (absolute), // png
 * ]
 */
function generate_external_handover_pdf(array $handover): string {
  $storageDir = __DIR__ . '/../storage/documents/external_handover';
  if (!is_dir($storageDir)) mkdir($storageDir, 0775, true);

  // SimplePDF cannot embed PNG with alpha (RGBA). Convert such images to JPG on white background.
  $ensurePdfSafeImage = function(string $absPath) use ($storageDir): string {
    if (!is_file($absPath)) return $absPath;
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    if ($ext !== 'png') return $absPath;

    // detect PNG color type from IHDR (byte 9 of IHDR data)
    $fh = @fopen($absPath, 'rb');
    if (!$fh) return $absPath;
    $sig = fread($fh, 8);
    if ($sig !== "\x89PNG\r\n\x1a\n") { fclose($fh); return $absPath; }
    // length(4) + type(4)
    $len = fread($fh, 4);
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
    $w = imagesx($im);
    $h = imagesy($im);
    $dst = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $w, $h, $white);
    imagealphablending($dst, true);
    imagecopy($dst, $im, 0, 0, 0, 0, $w, $h);

    $tmpDir = $storageDir . '/tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
    $out = $tmpDir . '/img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    imagejpeg($dst, $out, 92);

    imagedestroy($dst);
    imagedestroy($im);
    return $out;
  };

  $ts = date('Ymd_His');
  $rand = bin2hex(random_bytes(4));
  $pdfFile = "atadas_atvetel_{$ts}_{$rand}.pdf";
  $pdfAbs = $storageDir . '/' . $pdfFile;

  $pdf = new SimplePDF();
  $pdf->addPage();

  // Header
  $pdf->setFont('Helvetica', 16);
  $pdf->writeLine("Átadás-átvételi jegyzőkönyv");
  $pdf->setFont('Helvetica', 11);
  $pdf->writeLine("Perfect-Phone Kft.");

  // Optional logo (put your logo here)
  $logo = __DIR__ . '/../public/assets/perfect-phone-logo.png';
  if (is_file($logo)) {
    // top-right corner
    $pdf->image($ensurePdfSafeImage($logo), 430, 780, 120, 0);
  } else {
    $pdf->writeLine("LOGO: /public/assets/perfect-phone-logo.png (helyezd ide a logót)");
  }

  $pdf->ln(8);
  $pdf->setFont('Helvetica', 12);
  $pdf->writeLine("Átadás adatai", 0);
  $pdf->setFont('Helvetica', 11);

  $pdf->writeLine("Dátum: " . ($handover['assigned_at'] ?? ''));
  $pdf->writeLine("Átadó: " . ($handover['assigned_by'] ?? ''));

  $pdf->ln(4);
  $pdf->writeLine("Átvevő (külsős):");
  $pdf->writeLine("  Cég: " . ($handover['company'] ?? ''));
  if (!empty($handover['contact'])) $pdf->writeLine("  Kapcsolattartó: " . $handover['contact']);
  if (!empty($handover['phone']))   $pdf->writeLine("  Telefon: " . $handover['phone']);
  if (!empty($handover['email']))   $pdf->writeLine("  Email: " . $handover['email']);

  if (!empty($handover['courier_ref'])) $pdf->writeLine("Szállítólevél szám: " . $handover['courier_ref']);
  if (!empty($handover['note'])) $pdf->writeLine("Megjegyzés: " . $handover['note']);

  $pdf->ln(10);
  $pdf->setFont('Helvetica', 12);
  $pdf->writeLine("Eszközök");
  $pdf->setFont('Helvetica', 10);

  $assets = $handover['assets'] ?? [];
  $i = 1;
  foreach ($assets as $a) {
    $line = "{$i}. " . ($a['name'] ?? '');
    if (!empty($a['inventory'])) $line .= " | QR/Leltár: ".$a['inventory'];
    if (!empty($a['serial'])) $line .= " | SKU: ".$a['serial'];
    $pdf->writeLine($line);
    $i++;
  }

  // First asset photo (if exists)
  foreach ($assets as $a) {
    if (!empty($a['photo_abs']) && is_file($a['photo_abs'])) {
      $pdf->ln(6);
      $pdf->setFont('Helvetica', 11);
      $pdf->writeLine("Eszköz fotó (első elérhető):");
      // Place image lower part
      $pdf->image($ensurePdfSafeImage($a['photo_abs']), 60, 360, 220, 0);
      break;
    }
  }

  // Signature
  $pdf->ln(14);
  $pdf->setFont('Helvetica', 12);
  $pdf->writeLine("Átvevő aláírása");
  $sigAbs = $handover['signature_path_abs'] ?? '';
  if ($sigAbs && is_file($sigAbs)) {
    $pdf->image($ensurePdfSafeImage($sigAbs), 60, 160, 220, 0);
    $pdf->rect(55, 155, 230, 90);
  } else {
    $pdf->writeLine("(aláírás fájl nem található)");
  }

  $pdf->output($pdfAbs);

  return '/storage/documents/external_handover/'.$pdfFile;
}

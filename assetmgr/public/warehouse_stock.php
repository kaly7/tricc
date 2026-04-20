<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require __DIR__.'/../app/warehouses.php';
require __DIR__.'/../app/pdf_mpdf.php';
require __DIR__.'/../app/mailer.php';
require_login();

$u = current_user();
$pdo = db();
$title='Raktárkészlet';
$page='Raktárkészlet';

if (!warehouses_schema_ready($pdo)) {
  require __DIR__.'/_header.php';
  ?>
  <div class="container" style="max-width:960px">
    <div class="alert alert-warning">A raktár modul még nincs migrálva. Futtasd: <code>migrations/warehouses_phase1.sql</code></div>
  </div>
  <?php
  require __DIR__.'/_footer.php';
  exit;
}
if (!warehouse_is_admin($u)) {
  http_response_code(403);
  require __DIR__.'/_header.php';
  ?>
  <div class="container" style="max-width:960px"><div class="alert alert-danger">Ehhez az oldalhoz nincs jogosultságod.</div></div>
  <?php
  require __DIR__.'/_footer.php';
  exit;
}

function stripPngAlphaInplace_warehouse(string $pngAbs): void {
  if (!is_file($pngAbs)) return;
  if (!function_exists('imagecreatefrompng')) return;
  $im = @imagecreatefrompng($pngAbs);
  if (!$im) return;
  $w = imagesx($im); $h = imagesy($im);
  $dst = imagecreatetruecolor($w, $h);
  $white = imagecolorallocate($dst, 255, 255, 255);
  imagefilledrectangle($dst, 0, 0, $w, $h, $white);
  imagealphablending($dst, true);
  imagecopy($dst, $im, 0, 0, 0, 0, $w, $h);
  imagesavealpha($dst, false);
  @imagepng($dst, $pngAbs);
  imagedestroy($dst); imagedestroy($im);
}

function photo_public_url_warehouse(string $path): string {
  $p = trim($path);
  if ($p === '') return '';
  if (preg_match('~^https?://~i', $p)) return $p;
  $p = ltrim($p, '/');
  if (substr($p,0,8)==='storage/') return '/'.$p;
  return '/storage/'.$p;
}

function to_abs_storage_warehouse(string $webOrAbs): string {
  $p = trim($webOrAbs);
  if ($p === '') return '';
  if ($p[0] === '/' && str_starts_with($p, '/storage/')) return __DIR__ . '/..' . $p;
  if ($p[0] === '/' && is_file($p)) return $p;
  $p = ltrim($p, '/');
  if (str_starts_with($p, 'storage/')) return __DIR__ . '/../' . $p;
  return $p;
}

$accessible = warehouses_for_user($u);
$selectedWarehouseId = (int)($_GET['warehouse_id'] ?? 0);
if ($selectedWarehouseId <= 0 && $accessible) $selectedWarehouseId = (int)$accessible[0]['id'];
$selectedWarehouse = null;
foreach ($accessible as $w) if ((int)$w['id'] === $selectedWarehouseId) $selectedWarehouse = $w;
if (!$selectedWarehouse && $accessible) { $selectedWarehouse = $accessible[0]; $selectedWarehouseId = (int)$selectedWarehouse['id']; }

$hrEmployees = [];
try {
  $hr = db_hr();
  $hrEmployees = $hr->query("SELECT id, full_name, is_active FROM employees WHERE is_active=1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $hrEmployees = []; }

$authUserMap = [];
try {
  $auth = auth_pdo();
  foreach ($auth->query("SELECT id, COALESCE(NULLIF(full_name,''), NULLIF(username,''), email) AS nm FROM users")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $authUserMap[(int)$row['id']] = (string)$row['nm'];
  }
} catch (Throwable $e) {}

$hasExternalFlow = false;
$aeaCols = [];
try {
  $pdo->query("SELECT 1 FROM external_holders LIMIT 1");
  $pdo->query("SELECT 1 FROM asset_external_assignments LIMIT 1");
  foreach ($pdo->query("SHOW COLUMNS FROM asset_external_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $aeaCols[(string)$c['Field']] = true;
  }
  $hasExternalFlow = true;
} catch (Throwable $e) {
  $hasExternalFlow = false;
}

$externalHolders = [];
if ($hasExternalFlow) {
  try {
    $externalHolders = $pdo->query("SELECT id, company_name, contact_name, phone FROM external_holders WHERE is_active=1 ORDER BY company_name, contact_name, id DESC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $externalHolders = []; }
}
$hasWhExtCols = $hasExternalFlow && isset($aeaCols['source_warehouse_id']) && isset($aeaCols['returned_to_warehouse_id']);
$hasReturnPdf = $hasExternalFlow && isset($aeaCols['return_pdf_path']);
$hasExtEmail = $hasExternalFlow && isset($aeaCols['ext_email']);

// issue to employee
if ($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action'] ?? '')==='issue_to_employee') {
  verify_csrf();
  $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
  $toEmpId = (int)($_POST['to_employee_id'] ?? 0);
  $note = trim((string)($_POST['note'] ?? ''));
  $csv = trim((string)($_POST['asset_ids_csv'] ?? ''));
  $assetIds = $csv !== '' ? explode(',', $csv) : [];
  $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn($v)=>$v>0)));

  if (!warehouse_is_admin($u, $warehouseId)) { flash_set('err','Ehhez a raktárhoz nincs jogosultságod.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }
  if ($toEmpId <= 0) { flash_set('err','Válassz dolgozót.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }
  if (!$assetIds) { flash_set('err','Nem jelöltél ki eszközt.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }

  $stWh = $pdo->prepare("SELECT id, name FROM warehouses WHERE id=? AND is_active=1 LIMIT 1");
  $stWh->execute([$warehouseId]);
  $warehouse = $stWh->fetch(PDO::FETCH_ASSOC);
  if (!$warehouse) { flash_set('err','A raktár nem található vagy inaktív.'); header('Location: warehouse_stock.php'); exit; }

  $in = implode(',', array_fill(0, count($assetIds), '?'));
  $stChk = $pdo->prepare("SELECT id FROM assets WHERE is_deleted=0 AND current_warehouse_id=? AND id IN ($in)");
  $stChk->execute(array_merge([$warehouseId], $assetIds));
  $okIds = array_map('intval', array_column($stChk->fetchAll(PDO::FETCH_ASSOC), 'id'));
  if (count($okIds) !== count($assetIds)) { flash_set('err','A kijelölt eszközök közül nem mind ebben a raktárban található.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }

  $cols = [];
  foreach ($pdo->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[(string)$c['Field']] = true;
  if (isset($cols['status'])) {
    $stPend = $pdo->prepare("SELECT asset_id FROM asset_assignments WHERE status='pending' AND asset_id IN ($in)");
    $stPend->execute($okIds);
    if ($stPend->fetchColumn()) { flash_set('err','Van olyan kiválasztott eszköz, amelyre már van függő belső átadás.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }
  }

  $recipientEmail = trim((string)($_POST['recipient_email'] ?? ''));
  if ($recipientEmail !== '' && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    flash_set('err','Az email cím formátuma hibás.');
    header('Location: warehouse_stock.php?warehouse_id='.$warehouseId);
    exit;
  }

  $pdfWeb = null;
  $emailOk = null;
  $assetData = [];
  $targetName = '';
  foreach ($hrEmployees as $e) {
    if ((int)$e['id'] === $toEmpId) { $targetName = (string)$e['full_name']; break; }
  }

  $pdo->beginTransaction();
  try {
    $upd = $pdo->prepare("UPDATE assets SET current_employee_id=?, current_warehouse_id=NULL WHERE id=?");
    $stA = $pdo->prepare("SELECT id, name, sku, qr_value FROM assets WHERE id=? LIMIT 1");
    foreach ($okIds as $aid) {
      $upd->execute([$toEmpId, $aid]);
      $stA->execute([$aid]);
      $a = $stA->fetch(PDO::FETCH_ASSOC) ?: [];
      $assetData[] = [
        'id' => (int)$aid,
        'name' => (string)($a['name'] ?? ''),
        'inventory' => (string)($a['qr_value'] ?? ''),
        'serial' => (string)($a['sku'] ?? ''),
      ];
    }

    $performedBy = (string)(($u['name'] ?? '') ?: ($u['full_name'] ?? '') ?: ($u['email'] ?? '') ?: ('#'.(string)($u['id'] ?? '')));
    if (function_exists('generate_warehouse_issue_pdf_html')) {
      $pdfWeb = generate_warehouse_issue_pdf_html([
        'doc_date' => date('Y-m-d H:i:s'),
        'performed_by' => $performedBy,
        'warehouse' => (string)$warehouse['name'],
        'employee' => $targetName,
        'note' => $note,
        'assets' => $assetData,
      ]);
    }

    try {
      $insDoc = $pdo->prepare("INSERT INTO warehouse_issue_documents (asset_id, warehouse_id, to_employee_id, created_by_user_id, doc_date, note, recipient_email, pdf_path) VALUES (?,?,?,?,NOW(),?,?,?)");
      foreach ($assetData as $a) {
        $insDoc->execute([(int)$a['id'], $warehouseId, $toEmpId, (int)($u['id'] ?? 0), $note !== '' ? $note : null, $recipientEmail !== '' ? $recipientEmail : null, $pdfWeb]);
      }
    } catch (Throwable $e) {}

    $pdo->commit();

    if ($recipientEmail !== '' && $pdfWeb !== null) {
      $cfg = require __DIR__ . '/../app/config.php';
      $from = (string)($cfg['mail_from'] ?? 'no-reply@localhost');
      $bcc = (string)($cfg['mail_bcc'] ?? '');
      $pdfAbs = __DIR__ . '/..' . $pdfWeb;
      $assetItemsHtml = '';
      foreach ($assetData as $a) {
        $line = htmlspecialchars((string)$a['name'], ENT_QUOTES, 'UTF-8');
        if (!empty($a['inventory'])) $line .= ' | Leltár/QR: ' . htmlspecialchars((string)$a['inventory'], ENT_QUOTES, 'UTF-8');
        if (!empty($a['serial'])) $line .= ' | SKU/SN: ' . htmlspecialchars((string)$a['serial'], ENT_QUOTES, 'UTF-8');
        $assetItemsHtml .= '<li style="margin:0 0 6px 0;">' . $line . '</li>';
      }
      $subject = 'Perfect-Phone – Raktári kiadási bizonylat';
      $body = '<!doctype html><html lang="hu"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">'
            . '<div style="max-width:700px;margin:0 auto;padding:24px;">'
            . '<div style="background:#ffffff;border:1px solid #ddd;border-radius:10px;overflow:hidden;">'
            . '<div style="padding:24px 24px 12px 24px;">'
            . '<h2 style="margin:0 0 16px 0;font-size:22px;">Raktári kiadási értesítő</h2>'
            . '<p style="margin:0 0 16px 0;">Csatoltan küldjük a raktári kiadási bizonylatot PDF formátumban.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px 0;">'
            . '<tr><td style="padding:4px 0;width:180px;"><strong>Időpont:</strong></td><td style="padding:4px 0;">' . htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:4px 0;"><strong>Rögzítette:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($performedBy, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:4px 0;"><strong>Raktár:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)$warehouse['name'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:4px 0;"><strong>Dolgozó:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($targetName, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '</table>'
            . '<div style="margin:0 0 18px 0;"><strong>Eszközök:</strong><ul style="margin:8px 0 0 18px;padding:0;">' . $assetItemsHtml . '</ul></div>'
            . ($note !== '' ? '<p style="margin:14px 0 0 0;"><strong>Megjegyzés:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' : '')
            . '<p style="margin:18px 0 16px 0;">Üdvözlettel,<br><strong>Perfect-Phone</strong></p>'
            . '<div style="padding:16px 24px;border-top:1px solid #eee;background:#fcfcfc;text-align:left;">'
            . '<img src="cid:companylogo" alt="Perfect-Phone" style="max-height:48px;">'
            . '</div></div></div></body></html>';
      $emailOk = send_mail_with_attachment($recipientEmail, $subject, $body, $from, ($bcc !== '' ? $bcc : null), $pdfAbs, basename($pdfAbs));
    }

    if ($recipientEmail !== '' && $pdfWeb !== null) {
      if ($emailOk) flash_set('ok','Eszköz(ök) kiadva a dolgozónak. PDF elkészült és email elküldve.');
      else flash_set('warn','Eszköz(ök) kiadva a dolgozónak. PDF elkészült, de az email küldés nem sikerült.');
    } else {
      flash_set('ok','Eszköz(ök) kiadva a dolgozónak.');
    }
  } catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('err','Hiba raktárból kiadáskor: '.$e->getMessage());
  }
  header('Location: warehouse_stock.php?warehouse_id='.$warehouseId);
  exit;
}

// issue to external from warehouse
if ($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action'] ?? '')==='transfer_external') {
  verify_csrf();
  if (!$hasExternalFlow) {
    flash_set('err', 'Hiányzik a külsős átadás séma.');
    header('Location: warehouse_stock.php?warehouse_id='.$selectedWarehouseId);
    exit;
  }
  if (!$hasWhExtCols) {
    flash_set('err', 'Hiányzik a raktári külsős átadás séma. Futtasd: migrations/external_handover_warehouse.sql');
    header('Location: warehouse_stock.php?warehouse_id='.$selectedWarehouseId);
    exit;
  }

  $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
  if (!warehouse_is_admin($u, $warehouseId)) { flash_set('err','Ehhez a raktárhoz nincs jogosultságod.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }

  $holderId = (int)($_POST['external_holder_id'] ?? 0);
  $company = trim((string)($_POST['ext_company'] ?? ''));
  $contact = trim((string)($_POST['ext_contact'] ?? ''));
  $phone   = trim((string)($_POST['ext_phone'] ?? ''));
  $email   = trim((string)($_POST['ext_email'] ?? ''));
  $courier = trim((string)($_POST['courier_ref'] ?? ''));
  $note    = trim((string)($_POST['ext_note'] ?? ''));
  $note    = ($note === '') ? null : $note;
  $sigDataUrl = trim((string)($_POST['signature_png'] ?? ''));
  $csv = trim((string)($_POST['asset_ids_csv'] ?? ''));
  $assetIds = $csv !== '' ? explode(',', $csv) : [];
  $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn($v)=>$v>0)));

  if ($company === '') { flash_set('err', 'Külsős átadáshoz a cég neve kötelező.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash_set('err', 'Az email cím formátuma hibás.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }
  if ($sigDataUrl === '' || stripos($sigDataUrl, 'data:image/png;base64,') !== 0) { flash_set('err', 'Külsős átadáshoz kötelező az aláírás.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }
  if (!$assetIds) { flash_set('err', 'Nem jelöltél ki eszközt.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }

  $stWh = $pdo->prepare("SELECT id, name FROM warehouses WHERE id=? AND is_active=1 LIMIT 1");
  $stWh->execute([$warehouseId]);
  $warehouse = $stWh->fetch(PDO::FETCH_ASSOC);
  if (!$warehouse) { flash_set('err', 'A kiválasztott raktár nem található vagy inaktív.'); header('Location: warehouse_stock.php'); exit; }

  $in = implode(',', array_fill(0, count($assetIds), '?'));
  $chk = $pdo->prepare("SELECT id FROM assets WHERE is_deleted=0 AND current_warehouse_id=? AND id IN ($in)");
  $chk->execute(array_merge([$warehouseId], $assetIds));
  $okIds = array_map('intval', array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'id'));
  if (count($okIds) !== count($assetIds)) { flash_set('err', 'A kijelölt eszközök közül nem mind ebben a raktárban van.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }

  $cols = [];
  foreach ($pdo->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[(string)$c['Field']] = true;
  if (isset($cols['status'])) {
    $stPend = $pdo->prepare("SELECT asset_id FROM asset_assignments WHERE status='pending' AND asset_id IN ($in)");
    $stPend->execute($okIds);
    if ($stPend->fetchColumn()) { flash_set('err','Van olyan kijelölt eszköz, amelyre már van függő belső átadás.'); header('Location: warehouse_stock.php?warehouse_id='.$warehouseId); exit; }
  }

  $pdo->beginTransaction();
  try {
    if ($holderId > 0) {
      $stH = $pdo->prepare("SELECT id FROM external_holders WHERE id=? AND is_active=1 LIMIT 1");
      $stH->execute([$holderId]);
      if (!$stH->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('A kiválasztott külsős nem található vagy inaktív.');
      $pdo->prepare("UPDATE external_holders SET company_name=?, contact_name=?, phone=? WHERE id=?")
          ->execute([$company, $contact !== '' ? $contact : '', $phone !== '' ? $phone : null, $holderId]);
    } else {
      $pdo->prepare("INSERT INTO external_holders (company_name, contact_name, phone, is_active) VALUES (?,?,?,1)")
          ->execute([$company, $contact !== '' ? $contact : '', $phone !== '' ? $phone : null]);
      $holderId = (int)$pdo->lastInsertId();
    }

    $raw = base64_decode(substr($sigDataUrl, strlen('data:image/png;base64,')), true);
    if ($raw === false || strlen($raw) < 200) throw new RuntimeException('Hibás aláírás adat.');

    $sigDir = __DIR__.'/../storage/uploads/external_signatures/'.$holderId;
    if (!is_dir($sigDir)) mkdir($sigDir, 0775, true);
    $sigFn = 'sig_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.png';
    $sigAbs = $sigDir.'/'.$sigFn;
    if (file_put_contents($sigAbs, $raw) === false) throw new RuntimeException('Nem sikerült elmenteni az aláírást.');
    stripPngAlphaInplace_warehouse($sigAbs);
    $sigRel = '/storage/uploads/external_signatures/'.$holderId.'/'.$sigFn;

    $newIds = [];
    $insExt = $pdo->prepare("INSERT INTO asset_external_assignments (asset_id, external_holder_id, courier_ref, note, signature_path, ext_company, ext_contact, ext_phone, assigned_by_user_id, status, source_warehouse_id) VALUES (?,?,?,?,?,?,?,?,?,'active',?)");
    $updAsset = $pdo->prepare("UPDATE assets SET current_employee_id=NULL, current_warehouse_id=NULL WHERE id=?");

    foreach ($okIds as $aid) {
      $insExt->execute([$aid, $holderId, $courier, $note, $sigRel, $company, $contact !== '' ? $contact : null, $phone !== '' ? $phone : null, (int)($u['id'] ?? 0), $warehouseId]);
      $newIds[] = (int)$pdo->lastInsertId();
      $updAsset->execute([$aid]);
    }

    $pdfRel = null;
    $assetsData = [];
    $stA = $pdo->prepare("SELECT a.id, a.name, a.sku, a.qr_value, c.name AS category_name FROM assets a LEFT JOIN asset_category ac ON ac.asset_id=a.id LEFT JOIN categories c ON c.id=ac.category_id WHERE a.id=? LIMIT 1");
    $stP = $pdo->prepare("SELECT file_path FROM asset_photos WHERE asset_id=? ORDER BY is_primary DESC, id ASC LIMIT 1");
    foreach ($okIds as $aid) {
      $stA->execute([$aid]);
      $a = $stA->fetch(PDO::FETCH_ASSOC) ?: [];
      $stP->execute([$aid]);
      $pp = $stP->fetch(PDO::FETCH_ASSOC);
      $photoRel = $pp ? (string)$pp['file_path'] : '';
      $photoAbs = ($photoRel !== '') ? (__DIR__ . '/..' . $photoRel) : '';
      $assetsData[] = [
        'name' => (string)($a['name'] ?? ''),
        'serial' => (string)($a['sku'] ?? ''),
        'inventory' => (string)($a['qr_value'] ?? ''),
        'category' => (string)($a['category_name'] ?? ''),
        'photo_abs' => ($photoAbs && is_file($photoAbs)) ? $photoAbs : null,
      ];
    }

    $assignedAt = date('Y-m-d H:i:s');
    $assignedBy = (string)(($u['name'] ?? '') ?: ($u['full_name'] ?? '') ?: ($u['email'] ?? '') ?: ('#'.(string)($u['id'] ?? '')));
    $pdfRel = generate_external_handover_pdf_html([
      'company' => $company,
      'contact' => $contact !== '' ? $contact : null,
      'phone'   => $phone !== '' ? $phone : null,
      'email'   => $email !== '' ? $email : null,
      'courier_ref' => $courier !== '' ? $courier : null,
      'note'    => $note,
      'assigned_at' => $assignedAt,
      'assigned_by' => $assignedBy . ' (raktár: ' . (string)$warehouse['name'] . ')',
      'assets' => $assetsData,
      'signature_abs' => $sigAbs,
      'asset_photo_abs' => (string)($assetsData[0]['photo_abs'] ?? ''),
    ]);

    $upd = $pdo->prepare("UPDATE asset_external_assignments SET pdf_path=?, ext_email=? WHERE id=?");
    foreach ($newIds as $rid) $upd->execute([$pdfRel, $email !== '' ? $email : null, $rid]);

    $pdo->commit();

    if ($email !== '' && $pdfRel) {
      $cfg = require __DIR__ . '/../app/config.php';
      $from = (string)($cfg['mail_from'] ?? 'no-reply@localhost');
      $bcc  = (string)($cfg['mail_bcc'] ?? '');
      $pdfAbsMail = __DIR__ . '/..' . $pdfRel;
      $assetItemsHtml = '';
      foreach ($assetsData as $a) {
        $line = htmlspecialchars((string)$a['name'], ENT_QUOTES, 'UTF-8');
        if (!empty($a['inventory'])) $line .= ' | Leltár/QR: '.htmlspecialchars((string)$a['inventory'], ENT_QUOTES, 'UTF-8');
        if (!empty($a['serial'])) $line .= ' | SKU/SN: '.htmlspecialchars((string)$a['serial'], ENT_QUOTES, 'UTF-8');
        $assetItemsHtml .= '<li style="margin:0 0 6px 0;">'.$line.'</li>';
      }
      $subject = 'Perfect-Phone – Eszköz átadás-átvétel';
      $body = '<!doctype html><html lang="hu"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">'
            . '<div style="max-width:700px;margin:0 auto;padding:24px;">'
            . '<div style="background:#ffffff;border:1px solid #ddd;border-radius:10px;overflow:hidden;">'
            . '<div style="padding:24px 24px 12px 24px;">'
            . '<h2 style="margin:0 0 16px 0;font-size:22px;">Eszköz átadás-átvételi értesítő</h2>'
            . '<p style="margin:0 0 16px 0;">Csatoltan küldjük az eszköz átadás-átvételi jegyzőkönyvet.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px 0;">'
            . '<tr><td style="padding:4px 0;width:180px;"><strong>Átadás ideje:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($assignedAt, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:4px 0;"><strong>Átadta:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($assignedBy, ENT_QUOTES, 'UTF-8') . ' (raktár: ' . htmlspecialchars((string)$warehouse['name'], ENT_QUOTES, 'UTF-8') . ')</td></tr>'
            . '<tr><td style="padding:4px 0;"><strong>Partner:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($company, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . (!empty($contact) ? '<tr><td style="padding:4px 0;"><strong>Kapcsolattartó:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($contact, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
            . (!empty($phone) ? '<tr><td style="padding:4px 0;"><strong>Telefon:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
            . (!empty($email) ? '<tr><td style="padding:4px 0;"><strong>Email:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
            . (!empty($courier) ? '<tr><td style="padding:4px 0;"><strong>Szállítólevél:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($courier, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
            . '</table>'
            . '<div style="margin:0 0 18px 0;"><strong>Eszközök:</strong><ul style="margin:8px 0 0 18px;padding:0;">'.$assetItemsHtml.'</ul></div>'
            . ($note !== '' ? '<p style="margin:14px 0 0 0;"><strong>Megjegyzés:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>' : '')
            . '<p style="margin:18px 0 16px 0;">Üdvözlettel,<br><strong>Perfect-Phone</strong></p>'
            . '<div style="padding:16px 24px;border-top:1px solid #eee;background:#fcfcfc;text-align:left;">'
            . '<img src="cid:companylogo" alt="Perfect-Phone" style="max-height:48px;">'
            . '</div></div></div></body></html>';
      $okMail = send_mail_with_attachment($email, $subject, $body, $from, ($bcc !== '' ? $bcc : null), $pdfAbsMail, basename($pdfAbsMail));
      if ($okMail) flash_set('ok', 'Külsős átadás rögzítve. PDF elkészült és email elküldve.');
      else flash_set('warn', 'Külsős átadás rögzítve. PDF elkészült, de az email küldés nem sikerült.');
    } else {
      flash_set('ok', 'Külsős átadás rögzítve.');
    }
  } catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('err', 'Hiba külsős átadáskor: ' . $e->getMessage());
  }

  header('Location: warehouse_stock.php?warehouse_id='.$warehouseId);
  exit;
}

// return external to warehouse
if ($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action'] ?? '')==='return_external_to_warehouse') {
  verify_csrf();
  if (!$hasExternalFlow || !$hasWhExtCols) {
    flash_set('err', 'Hiányzik a raktári külsős visszavétel séma. Futtasd: migrations/external_handover_warehouse.sql');
    header('Location: warehouse_stock.php?warehouse_id='.$selectedWarehouseId);
    exit;
  }

  $extAssignId = (int)($_POST['ext_assignment_id'] ?? 0);
  $sourceWarehouseId = (int)($_POST['source_warehouse_id'] ?? 0);
  $targetWarehouseId = (int)($_POST['target_warehouse_id'] ?? 0);
  $returnNote = trim((string)($_POST['return_note'] ?? ''));

  if ($extAssignId <= 0 || $sourceWarehouseId <= 0 || $targetWarehouseId <= 0) {
    flash_set('err', 'Hiányzó külsős visszavételi adatok.');
    header('Location: warehouse_stock.php?warehouse_id='.$selectedWarehouseId);
    exit;
  }
  if (!warehouse_is_admin($u, $sourceWarehouseId)) {
    flash_set('err','A forrás raktárhoz nincs jogosultságod.');
    header('Location: warehouse_stock.php?warehouse_id='.$selectedWarehouseId);
    exit;
  }
  if (!warehouse_is_admin($u, $targetWarehouseId)) {
    flash_set('err','A cél raktárhoz nincs jogosultságod.');
    header('Location: warehouse_stock.php?warehouse_id='.$selectedWarehouseId);
    exit;
  }

  $sourceWarehouseName = '';
  foreach ($accessible as $w) { if ((int)$w['id'] === $sourceWarehouseId) { $sourceWarehouseName = (string)$w['name']; break; } }
  if ($sourceWarehouseName === '') {
    $stWh = $pdo->prepare("SELECT name FROM warehouses WHERE id=? LIMIT 1");
    $stWh->execute([$sourceWarehouseId]);
    $sourceWarehouseName = (string)($stWh->fetchColumn() ?: ('#'.$sourceWarehouseId));
  }

  $warehouseName = '';
  foreach ($accessible as $w) { if ((int)$w['id'] === $targetWarehouseId) { $warehouseName = (string)$w['name']; break; } }
  if ($warehouseName === '') {
    $stWh = $pdo->prepare("SELECT name FROM warehouses WHERE id=? LIMIT 1");
    $stWh->execute([$targetWarehouseId]);
    $warehouseName = (string)($stWh->fetchColumn() ?: ('#'.$targetWarehouseId));
  }

  $xea = null;
  try {
    $pdo->beginTransaction();

    $stx = $pdo->prepare("
      SELECT aea.*, eh.company_name, eh.contact_name, eh.phone
      FROM asset_external_assignments aea
      JOIN external_holders eh ON eh.id=aea.external_holder_id
      WHERE aea.id=? AND aea.status='active' AND aea.source_warehouse_id=?
      LIMIT 1
    ");
    $stx->execute([$extAssignId, $sourceWarehouseId]);
    $xea = $stx->fetch(PDO::FETCH_ASSOC);
    if (!$xea) throw new RuntimeException('Nincs ehhez a raktárhoz tartozó aktív külsős átadás ezzel az azonosítóval.');

    $assetId = (int)$xea['asset_id'];

    $pdo->prepare("UPDATE asset_external_assignments
          SET status='returned',
              returned_at=NOW(),
              returned_by_user_id=?,
              returned_to_warehouse_id=?,
              return_note=?
          WHERE id=?")
        ->execute([(int)($u['id'] ?? 0), $targetWarehouseId, $returnNote !== '' ? $returnNote : null, $extAssignId]);

    $pdo->prepare("UPDATE assets SET current_employee_id=NULL, current_warehouse_id=? WHERE id=?")
        ->execute([$targetWarehouseId, $assetId]);

    $pdo->commit();

    if (!empty($xea['pdf_path'])) {
      try {
        $stA = $pdo->prepare("SELECT id, name, sku, qr_value FROM assets WHERE id=? LIMIT 1");
        $stA->execute([$assetId]);
        $asset = $stA->fetch(PDO::FETCH_ASSOC) ?: [];

        $photoAbs = '';
        try {
          $stP = $pdo->prepare("SELECT file_path FROM asset_photos WHERE asset_id=? ORDER BY is_primary DESC, id DESC LIMIT 1");
          $stP->execute([$assetId]);
          $photo = (string)($stP->fetchColumn() ?: '');
          if ($photo !== '') $photoAbs = to_abs_storage_warehouse(photo_public_url_warehouse($photo));
        } catch (Throwable $e) {}

        $sigAbs = '';
        $sigWeb = (string)($xea['signature_path'] ?? '');
        if ($sigWeb !== '') $sigAbs = to_abs_storage_warehouse($sigWeb);

        $assignedBy = $authUserMap[(int)($xea['assigned_by_user_id'] ?? 0)] ?? ('#'.(int)($xea['assigned_by_user_id'] ?? 0));
        $returnedBy = $authUserMap[(int)($u['id'] ?? 0)] ?? ('#'.(int)($u['id'] ?? 0));

        $stR = $pdo->prepare("SELECT assigned_at, returned_at FROM asset_external_assignments WHERE id=? LIMIT 1");
        $stR->execute([$extAssignId]);
        $times = $stR->fetch(PDO::FETCH_ASSOC) ?: [];

        $pdfWeb = generate_external_return_pdf_html([
          'assigned_at'  => (string)($times['assigned_at'] ?? ($xea['assigned_at'] ?? '')),
          'returned_at'  => (string)($times['returned_at'] ?? ''),
          'assigned_by'  => (string)$assignedBy,
          'returned_by'  => (string)$returnedBy,
          'returned_to'  => (string)$warehouseName,
          'company'      => (string)($xea['company_name'] ?? ''),
          'contact'      => (string)($xea['contact_name'] ?? ''),
          'phone'        => (string)($xea['phone'] ?? ''),
          'email'        => (string)($xea['ext_email'] ?? ''),
          'courier_ref'  => (string)($xea['courier_ref'] ?? ''),
          'note'         => (string)($xea['note'] ?? ''),
          'return_note'  => (string)($returnNote ?? ''),
          'assets'       => [[
            'name'      => (string)($asset['name'] ?? ''),
            'inventory' => (string)($asset['qr_value'] ?? ''),
            'serial'    => (string)($asset['sku'] ?? ''),
            'photo_abs' => $photoAbs,
          ]],
          'signature_abs' => $sigAbs,
        ]);

        if ($hasReturnPdf) {
          $pdo->prepare("UPDATE asset_external_assignments SET return_pdf_path=? WHERE id=?")->execute([$pdfWeb, $extAssignId]);
        }

        $to = trim((string)($xea['ext_email'] ?? ''));
        if ($to !== '') {
          $cfg = require __DIR__ . '/../app/config.php';
          $from = (string)($cfg['mail_from'] ?? 'no-reply@localhost');
          $bcc  = (string)($cfg['mail_bcc'] ?? '');
          $subj = "Perfect-Phone – Eszköz visszavéve";

          $assetLine = htmlspecialchars((string)($asset['name'] ?? ''), ENT_QUOTES, 'UTF-8');
          $inv = (string)($asset['qr_value'] ?? '');
          $ser = (string)($asset['sku'] ?? '');
          if ($inv !== '') $assetLine .= ' | Leltár/QR: ' . htmlspecialchars($inv, ENT_QUOTES, 'UTF-8');
          if ($ser !== '') $assetLine .= ' | SKU/SN: ' . htmlspecialchars($ser, ENT_QUOTES, 'UTF-8');

          $contactHtml = !empty($xea['contact_name']) ? '<tr><td style="padding:4px 0;width:180px;"><strong>Kapcsolattartó:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)$xea['contact_name'], ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
          $phoneHtml   = !empty($xea['phone']) ? '<tr><td style="padding:4px 0;"><strong>Telefon:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)$xea['phone'], ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
          $emailHtml   = !empty($xea['ext_email']) ? '<tr><td style="padding:4px 0;"><strong>Email:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)$xea['ext_email'], ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
          $returnNoteHtml = ($returnNote ?? '') !== '' ? '<p style="margin:14px 0 0 0;"><strong>Visszavételi megjegyzés:</strong><br>' . nl2br(htmlspecialchars((string)$returnNote, ENT_QUOTES, 'UTF-8')) . '</p>' : '';

          $body = '<!doctype html><html lang="hu"><head><meta charset="utf-8"></head>'
                . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">'
                . '<div style="max-width:700px;margin:0 auto;padding:24px;">'
                . '<div style="background:#ffffff;border:1px solid #ddd;border-radius:10px;overflow:hidden;">'
                . '<div style="padding:24px 24px 12px 24px;">'
                . '<h2 style="margin:0 0 16px 0;font-size:22px;">Eszköz visszavételi értesítő</h2>'
                . '<p style="margin:0 0 16px 0;">Tisztelt Partner!</p>'
                . '<p style="margin:0 0 16px 0;">Az alábbi eszköz visszavételre került. A részletek a csatolt PDF-ben találhatók.</p>'
                . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px 0;">'
                . '<tr><td style="padding:4px 0;width:180px;"><strong>Átadás ideje:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)($times['assigned_at'] ?? ($xea['assigned_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Átadta:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($assignedBy, ENT_QUOTES, 'UTF-8') . ' (raktár: ' . htmlspecialchars($warehouseName, ENT_QUOTES, 'UTF-8') . ')</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Visszavétel ideje:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)($times['returned_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Visszavette:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($returnedBy, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Visszakerült:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($warehouseName, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Partner:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)($xea['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . $contactHtml . $phoneHtml . $emailHtml
                . '</table>'
                . '<div style="margin:0 0 18px 0;"><strong>Eszköz:</strong><ul style="margin:8px 0 0 18px;padding:0;"><li>'.$assetLine.'</li></ul></div>'
                . $returnNoteHtml
                . '<p style="margin:18px 0 16px 0;">Üdvözlettel,<br><strong>Perfect-Phone</strong></p>'
                . '<div style="padding:16px 24px;border-top:1px solid #eee;background:#fcfcfc;text-align:left;">'
                . '<img src="cid:companylogo" alt="Perfect-Phone" style="max-height:48px;">'
                . '</div></div></div></body></html>';

          send_mail_with_attachment($to, $subj, $body, $from, ($bcc !== '' ? $bcc : null), __DIR__ . '/..' . $pdfWeb, basename(__DIR__ . '/..' . $pdfWeb));
        }
      } catch (Throwable $e2) {
        flash_set('warn', 'Külsőstől visszavéve a raktárba, de a PDF/email generálás hibázott: '.$e2->getMessage());
        header('Location: warehouse_stock.php?warehouse_id='.$sourceWarehouseId);
        exit;
      }
    }

    flash_set('ok','Külsőstől visszavéve a raktárba.');
  } catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('err','Külsőstől raktárba visszavétel hibája: '.$e->getMessage());
  }
  header('Location: warehouse_stock.php?warehouse_id='.$sourceWarehouseId);
  exit;
}

$rows = [];
if ($selectedWarehouseId > 0) {
  $st = $pdo->prepare("
    SELECT a.*,
      (SELECT p.file_path FROM asset_photos p WHERE p.asset_id=a.id ORDER BY p.is_primary DESC, p.id DESC LIMIT 1) AS photo_path,
      (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
         FROM asset_category ac
         JOIN categories c ON c.id=ac.category_id AND c.is_deleted=0
        WHERE ac.asset_id=a.id) AS cat_names
    FROM assets a
    WHERE a.is_deleted=0 AND a.current_warehouse_id=?
    ORDER BY a.name ASC, a.id DESC");
  $st->execute([$selectedWarehouseId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$activeExternalRows = [];
if ($hasWhExtCols && $selectedWarehouseId > 0) {
  try {
    $stActive = $pdo->prepare("
      SELECT aea.*, eh.company_name, eh.contact_name, eh.phone, a.name AS asset_name, a.sku, a.qr_value
      FROM asset_external_assignments aea
      JOIN external_holders eh ON eh.id=aea.external_holder_id
      LEFT JOIN assets a ON a.id=aea.asset_id
      WHERE aea.source_warehouse_id=? AND aea.status='active'
      ORDER BY aea.id DESC
    ");
    $stActive->execute([$selectedWarehouseId]);
    $activeExternalRows = $stActive->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $activeExternalRows = [];
  }
}

require __DIR__.'/_header.php';
?>
<div class="container" style="max-width:980px">
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
          <label class="form-label">Raktár</label>
          <select class="form-select" name="warehouse_id" onchange="this.form.submit()">
            <?php foreach ($accessible as $w): ?>
              <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id']===$selectedWarehouseId ? 'selected' : '' ?>>
                <?= e((string)$w['name']) ?><?= !empty($w['location']) ? ' — '.e((string)$w['location']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>
  </div>

  <?php if ($selectedWarehouse): ?>
    <form method="post" id="issueForm" class="card shadow-sm mb-3">
      <div class="card-body">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="issue_to_employee">
        <input type="hidden" name="warehouse_id" value="<?= (int)$selectedWarehouseId ?>">
        <input type="hidden" name="asset_ids_csv" id="issue_asset_ids_csv" value="">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-md-4">
            <label class="form-label">Kiadás dolgozónak</label>
            <select class="form-select" name="to_employee_id" required>
              <option value="">— válassz dolgozót —</option>
              <?php foreach ($hrEmployees as $e): ?>
                <option value="<?= (int)$e['id'] ?>"><?= e((string)$e['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Email cím (opcionális)</label>
            <input type="email" class="form-control" name="recipient_email" placeholder="pl. dolgozo@ceg.hu">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Megjegyzés (opcionális)</label>
            <input class="form-control" name="note" placeholder="pl. induló készlet / raktári kiadás">
          </div>
          <div class="col-12 d-flex justify-content-between align-items-center mt-2">
            <div class="form-text">Jelöld ki a raktárban lévő eszközöket, majd add ki a dolgozónak.</div>
            <button class="btn btn-primary" type="submit">Kiadás</button>
          </div>
        </div>
      </div>
    </form>

    <?php if ($hasExternalFlow): ?>
      <form method="post" id="externalTransferForm" class="card shadow-sm mb-3">
        <div class="card-body">
          <button class="btn btn-warning" type="button" data-bs-toggle="collapse" data-bs-target="#extCollapseWh" aria-expanded="false" aria-controls="extCollapseWh">
            Külsős átadás raktárból
          </button>
          <div id="extCollapseWh" class="collapse mt-3">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="transfer_external">
            <input type="hidden" name="warehouse_id" value="<?= (int)$selectedWarehouseId ?>">
            <input type="hidden" name="asset_ids_csv" id="ext_asset_ids_csv" value="">
            <input type="hidden" name="signature_png" id="ext_signature_png" value="">
            <div class="row g-2">
              <?php if (!$hasWhExtCols): ?>
                <div class="col-12">
                  <div class="alert alert-warning mb-0">A raktári külsős átadáshoz futtasd: <code>migrations/external_handover_warehouse.sql</code></div>
                </div>
              <?php endif; ?>
              <div class="col-12">
                <label class="form-label">Külsős partner</label>
                <select class="form-select" name="external_holder_id" id="external_holder_id">
                  <option value="0">— Új külsős felvitele —</option>
                  <?php foreach ($externalHolders as $eh): ?>
                    <option value="<?= (int)$eh['id'] ?>"
                            data-company="<?= e((string)$eh['company_name']) ?>"
                            data-contact="<?= e((string)$eh['contact_name']) ?>"
                            data-phone="<?= e((string)($eh['phone'] ?? '')) ?>">
                      <?= e((string)$eh['company_name']) ?><?= ((string)$eh['contact_name']!=='') ? ' — '.e((string)$eh['contact_name']) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Cég neve</label>
                <input class="form-control" name="ext_company" id="ext_company" required>
              </div>
              <div class="col-12">
                <label class="form-label">Kapcsolattartó neve</label>
                <input class="form-control" name="ext_contact" id="ext_contact">
              </div>
              <div class="col-12">
                <label class="form-label">Telefonszám</label>
                <input class="form-control" name="ext_phone" id="ext_phone">
              </div>
              <div class="col-12">
                <label class="form-label">Email (opcionális)</label>
                <input type="email" class="form-control" name="ext_email" id="ext_email" placeholder="pl. partner@ceg.hu">
              </div>
              <div class="col-12">
                <label class="form-label">Szállítólevél szám (opcionális)</label>
                <input class="form-control" name="courier_ref" placeholder="pl. SL-2026-001">
              </div>
              <div class="col-12">
                <label class="form-label">Aláírás (kötelező)</label>
                <div class="border rounded p-2" style="background:#fff">
                  <canvas id="extSigCanvasWh" style="width:100%;height:220px;border:2px dashed #bbb;border-radius:10px;touch-action:none"></canvas>
                  <div class="d-flex gap-2 mt-2">
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="extSigClearWh">Törlés</button>
                    <div class="text-secondary small align-self-center">Egérrel vagy ujjal/stylusszal rajzolható.</div>
                  </div>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label">Megjegyzés</label>
                <input class="form-control" name="ext_note" placeholder="pl. külsős javítás / partner kiadás">
              </div>
              <div class="col-12 d-grid">
                <button class="btn btn-warning" type="submit" <?= $hasWhExtCols ? '' : 'disabled' ?>>Rögzítés</button>
              </div>
            </div>
            <div class="form-text mt-2">A kijelölt eszközök külsős partnerhez kerülnek.</div>
          </div>
        </div>
      </form>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="alert alert-info">Ebben a raktárban jelenleg nincs eszköz.</div>
    <?php else: ?>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="selectAllWh">
          <label class="form-check-label" for="selectAllWh">Összes kijelölése</label>
        </div>
        <div class="text-secondary small"><?= count($rows) ?> db</div>
      </div>
      <div class="d-grid gap-2">
        <?php foreach ($rows as $r): ?>
          <?php
            $photo = (string)($r['photo_path'] ?? '');
            $photoUrl = $photo !== '' ? photo_public_url_warehouse($photo) : '';
            $cats=(string)($r['cat_names'] ?? '');
            $sku=(string)($r['sku'] ?? '');
            $qr=(string)($r['qr_value'] ?? '');
            $aid=(int)$r['id'];
          ?>
          <div class="card shadow-sm">
            <div class="card-body d-flex gap-3">
              <div style="width:28px;flex:0 0 28px">
                <div class="form-check mt-1">
                  <input class="form-check-input whAssetCb" type="checkbox" value="<?= $aid ?>" id="w<?= $aid ?>">
                </div>
              </div>
              <div style="width:96px;flex:0 0 96px">
                <?php if ($photoUrl !== ''): ?>
                  <img src="<?= e($photoUrl) ?>" alt="" class="img-fluid rounded" style="max-height:96px;object-fit:cover;width:96px">
                <?php else: ?>
                  <div class="bg-light border rounded d-flex align-items-center justify-content-center" style="height:96px;width:96px"><span class="text-secondary small">nincs kép</span></div>
                <?php endif; ?>
              </div>
              <div class="flex-grow-1">
                <div class="fw-semibold"><?= e((string)$r['name']) ?></div>
                <div class="text-secondary small">#<?= $aid ?></div>
                <?php if ($sku !== ''): ?><div class="small mt-2"><strong>Cikkszám:</strong> <?= e($sku) ?></div><?php endif; ?>
                <?php if ($qr !== ''): ?><div class="small"><strong>QR:</strong> <?= e($qr) ?></div><?php endif; ?>
                <?php if ($cats !== ''): ?><div class="small"><strong>Kategória:</strong> <?= e($cats) ?></div><?php endif; ?>
              </div>
              <?php if (($u['role'] ?? '') === 'admin'): ?>
                <div><a class="btn btn-sm btn-outline-primary" href="asset_edit.php?id=<?= $aid ?>">Megnyit</a></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="card shadow-sm mt-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Külsősnél lévő tételek ebből a raktárból</strong>
        <span class="badge bg-secondary"><?= count($activeExternalRows) ?> db</span>
      </div>
      <div class="card-body">
        <div class="text-secondary small mb-3">Itt látszanak azok az eszközök, amelyeket ebből a raktárból adtak ki külsős partnernek. A visszavétel ugyanabba vagy másik raktárba is történhet.</div>
        <?php if (!$hasWhExtCols): ?>
          <div class="alert alert-warning mb-0">Ehhez a listához és visszavételhez futtasd: <code>migrations/external_handover_warehouse.sql</code></div>
        <?php elseif (!$activeExternalRows): ?>
          <div class="alert alert-light border mb-0">Nincs aktív külsős kiadás ebből a raktárból.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th style="width:80px">ID</th>
                  <th>Eszköz</th>
                  <th>Partner</th>
                  <th style="width:180px">Kiadva</th>
                  <th style="width:320px">Visszavétel raktárba</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($activeExternalRows as $x): ?>
                  <tr>
                    <td><?= (int)$x['id'] ?></td>
                    <td>
                      <div class="fw-semibold"><?= e((string)($x['asset_name'] ?? '')) ?></div>
                      <div class="small text-secondary">
                        <?php if (!empty($x['qr_value'])): ?>QR: <?= e((string)$x['qr_value']) ?><?php endif; ?>
                        <?php if (!empty($x['sku'])): ?><?= !empty($x['qr_value']) ? ' • ' : '' ?>SKU/SN: <?= e((string)$x['sku']) ?><?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <?= e((string)($x['company_name'] ?? '')) ?>
                      <?php if (!empty($x['contact_name'])): ?><div class="small text-secondary"><?= e((string)$x['contact_name']) ?></div><?php endif; ?>
                    </td>
                    <td><?= e((string)($x['assigned_at'] ?? '')) ?></td>
                    <td>
                      <form method="post" class="row g-2 align-items-start">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="return_external_to_warehouse">
                        <input type="hidden" name="source_warehouse_id" value="<?= (int)$selectedWarehouseId ?>">
                        <input type="hidden" name="ext_assignment_id" value="<?= (int)$x['id'] ?>">
                        <div class="col-12">
                          <select class="form-select form-select-sm" name="target_warehouse_id" required>
                            <?php foreach ($accessible as $w): ?>
                              <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === (int)$selectedWarehouseId ? 'selected' : '' ?>>
                                <?= e((string)$w['name']) ?><?= !empty($w['location']) ? ' — '.e((string)$w['location']) : '' ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-12">
                          <input class="form-control form-control-sm" name="return_note" placeholder="Megjegyzés (opcionális)">
                        </div>
                        <div class="col-12 d-grid">
                          <button class="btn btn-sm btn-outline-success" type="submit">Külsőstől visszavétel</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>
</div>

<script>
(function(){
  const selAll = document.getElementById('selectAllWh');
  const cbs = () => Array.from(document.querySelectorAll('.whAssetCb'));
  const selectedIds = () => cbs().filter(cb => cb.checked).map(cb => cb.value);
  const issueForm = document.getElementById('issueForm');
  const issueCsv = document.getElementById('issue_asset_ids_csv');
  if (selAll) {
    selAll.addEventListener('change', function(){ cbs().forEach(cb => cb.checked = selAll.checked); });
  }
  if (issueForm && issueCsv) {
    issueForm.addEventListener('submit', function(e){
      const ids = selectedIds();
      if (!ids.length) { e.preventDefault(); alert('Jelölj ki legalább 1 eszközt.'); return; }
      issueCsv.value = ids.join(',');
    });
  }

  const extForm = document.getElementById('externalTransferForm');
  const extCsv = document.getElementById('ext_asset_ids_csv');
  const sigInp = document.getElementById('ext_signature_png');
  const sigCanvas = document.getElementById('extSigCanvasWh');
  const sigClear = document.getElementById('extSigClearWh');
  const extCollapse = document.getElementById('extCollapseWh');
  let sigHasDrawn = false;
  let sigCtx = null;

  function sigResize() {
    if (!sigCanvas) return;
    const dpr = Math.max(1, window.devicePixelRatio || 1);
    const rect = sigCanvas.getBoundingClientRect();
    sigCanvas.width = Math.round(rect.width * dpr);
    sigCanvas.height = Math.round(rect.height * dpr);
    sigCtx = sigCanvas.getContext('2d', { willReadFrequently: true });
    sigCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
    sigCtx.lineWidth = 2.2;
    sigCtx.lineCap = 'round';
    sigCtx.lineJoin = 'round';
    sigCtx.strokeStyle = '#111';
  }
  function sigClearAll() {
    if (!sigCanvas || !sigCtx) return;
    const rect = sigCanvas.getBoundingClientRect();
    sigCtx.clearRect(0, 0, rect.width, rect.height);
    sigHasDrawn = false;
    if (sigInp) sigInp.value = '';
  }
  let drawing = false;
  function point(e){ const r = sigCanvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
  function start(e){ drawing = true; const p = point(e); sigCtx.beginPath(); sigCtx.moveTo(p.x, p.y); }
  function move(e){ if (!drawing) return; const p = point(e); sigCtx.lineTo(p.x, p.y); sigCtx.stroke(); sigHasDrawn = true; }
  function end(){ drawing = false; if (sigCtx) sigCtx.closePath(); }

  if (sigCanvas) {
    sigResize();
    window.addEventListener('resize', sigResize);
    sigCanvas.addEventListener('pointerdown', (e)=>{ e.preventDefault(); sigCanvas.setPointerCapture(e.pointerId); start(e); });
    sigCanvas.addEventListener('pointermove', (e)=>{ e.preventDefault(); move(e); });
    sigCanvas.addEventListener('pointerup', (e)=>{ e.preventDefault(); end(); });
    sigCanvas.addEventListener('pointercancel', (e)=>{ e.preventDefault(); end(); });
  }
  if (extCollapse) extCollapse.addEventListener('shown.bs.collapse', function(){ sigResize(); });
  if (sigClear) sigClear.addEventListener('click', sigClearAll);

  if (extForm && extCsv) {
    extForm.addEventListener('submit', function(e){
      const ids = selectedIds();
      if (!ids.length) { e.preventDefault(); alert('Jelölj ki legalább 1 eszközt a külsős átadáshoz.'); return; }
      if (!sigCanvas || !sigInp) { e.preventDefault(); alert('Aláírás mező nem elérhető.'); return; }
      if (!sigHasDrawn) { e.preventDefault(); alert('Az aláírás kötelező (nem lehet üres).'); return; }
      extCsv.value = ids.join(',');
      sigInp.value = sigCanvas.toDataURL('image/png');
    });
  }

  const holderSel = document.getElementById('external_holder_id');
  const extCompany = document.getElementById('ext_company');
  const extContact = document.getElementById('ext_contact');
  const extPhone = document.getElementById('ext_phone');
  function fillFromSelected() {
    if (!holderSel) return;
    const id = parseInt(holderSel.value || '0', 10);
    if (id <= 0) {
      if (extCompany) extCompany.value = '';
      if (extContact) extContact.value = '';
      if (extPhone) extPhone.value = '';
      return;
    }
    const opt = holderSel.options[holderSel.selectedIndex];
    if (!opt) return;
    if (extCompany) extCompany.value = opt.getAttribute('data-company') || '';
    if (extContact) extContact.value = opt.getAttribute('data-contact') || '';
    if (extPhone) extPhone.value = opt.getAttribute('data-phone') || '';
  }
  if (holderSel) holderSel.addEventListener('change', fillFromSelected);
  fillFromSelected();
})();
</script>
<?php require __DIR__.'/_footer.php'; ?>

<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require __DIR__.'/../app/warehouses.php';
require_login();
$u = current_user();
// PNG alpha eltávolítása (PDF kompatibilitás miatt).
// A SimplePDF nem tud RGBA (alpha csatornás) PNG-t beágyazni (colorType=6).
// Fehér háttérre rendereljük és visszamentjük alpha nélkül.
function stripPngAlphaInplace(string $pngAbs): void {
  if (!is_file($pngAbs)) return;
  if (!function_exists('imagecreatefrompng')) return; // php-gd hiányzik
  $im = @imagecreatefrompng($pngAbs);
  if (!$im) return;
  $w = imagesx($im);
  $h = imagesy($im);
  $dst = imagecreatetruecolor($w, $h);
  $white = imagecolorallocate($dst, 255, 255, 255);
  imagefilledrectangle($dst, 0, 0, $w, $h, $white);
  imagealphablending($dst, true);
  imagecopy($dst, $im, 0, 0, 0, 0, $w, $h);
  imagesavealpha($dst, false);
  @imagepng($dst, $pngAbs);
  imagedestroy($dst);
  imagedestroy($im);
}


// --- Automatikus redirect, ha van elfogadásra váró eszköz ---
try {
  $empId = (int)($u['hr_employee_id'] ?? 0);
  if ($empId <= 0) $empId = (int)($_SESSION['user']['hr_employee_id'] ?? 0);
  if ($empId <= 0) $empId = (int)($_SESSION['hr_employee_id'] ?? 0);
  if ($empId <= 0) $empId = (int)($_SESSION['auth_user']['hr_employee_id'] ?? 0);

  if ($empId > 0) {
    $pdoCheck = db();

    $hasStatus = false;
    foreach ($pdoCheck->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
      if ((string)$c['Field'] === 'status') {
        $hasStatus = true;
        break;
      }
    }

    if ($hasStatus) {
      // Lejárt pending kérések automatikus lezárása (ne maradjanak örökké függőben)
      $hasExpires = false;
      foreach ($pdoCheck->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c2) {
        if ((string)$c2['Field'] === 'expires_at') { $hasExpires = true; break; }
      }
      if ($hasExpires) {
        $pdoCheck->exec("UPDATE asset_assignments
                        SET status='expired', responded_at=NOW(),
                            response_note=COALESCE(response_note,'Lejárt automatikusan')
                        WHERE status='pending' AND expires_at IS NOT NULL AND expires_at < NOW()");
      }

      $st = $pdoCheck->prepare("
        SELECT COUNT(*) 
        FROM asset_assignments 
        WHERE to_employee_id=? AND status='pending'
      ");
      $st->execute([$empId]);
      $pending = (int)($st->fetchColumn() ?: 0);

      if ($pending > 0) {
        header('Location: '.base_url('inbox.php'));
        exit;
      }
    }
  }
} catch (Throwable $e) {
  // ha bármi hiba van, ne álljon meg az oldal
}


$title = 'Nálam lévő eszközök';
$page  = 'Nálam lévő eszközök';
$pdo = db();

// HR employee azonosító több helyről (különböző modulok/session struktúrák miatt)
$myEmpId = (int)($u['hr_employee_id'] ?? 0);
if ($myEmpId <= 0) $myEmpId = (int)($_SESSION['user']['hr_employee_id'] ?? 0);
if ($myEmpId <= 0) $myEmpId = (int)($_SESSION['hr_employee_id'] ?? 0);
if ($myEmpId <= 0) $myEmpId = (int)($_SESSION['auth_user']['hr_employee_id'] ?? 0);

$q = trim((string)($_GET['q'] ?? ''));

if ($myEmpId <= 0) {
  require __DIR__.'/_header.php';
  ?>
  <div class="container" style="max-width:720px">
    <div class="alert alert-warning">
      Ehhez a felhasználóhoz nincs HR munkatárs rendelve (vagy nem került be a sessionbe).
      Állítsd be az Auth Centerben (Admin → Users → HR munkatárs), majd jelentkezz ki/be.
    </div>
    <a class="btn btn-outline-secondary" href="logout.php">Kijelentkezés</a>
  </div>
  <?php
  require __DIR__.'/_footer.php';
  exit;
}

$hr = db_hr();

// Saját név
$stMe = $hr->prepare("SELECT full_name FROM employees WHERE id=? LIMIT 1");
$stMe->execute([$myEmpId]);
$myName = (string)($stMe->fetchColumn() ?: '');

// HR dolgozók listája átadáshoz
$hrEmployees = [];
try {
  $stE = $hr->query("SELECT id, full_name, is_active FROM employees WHERE is_active=1 ORDER BY full_name");
  $hrEmployees = $stE->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $hrEmployees = [];
}

// Külsős tábla ellenőrzés
$hasExternalFlow = false;
try {
  $pdo->query("SELECT 1 FROM external_holders LIMIT 1");
  $pdo->query("SELECT 1 FROM asset_external_assignments LIMIT 1");
  $hasExternalFlow = true;
} catch (Throwable $e) {
  $hasExternalFlow = false;
}

$externalHolders = [];
if ($hasExternalFlow) {
  try {
    $externalHolders = $pdo->query("SELECT id, company_name, contact_name, phone FROM external_holders WHERE is_active=1 ORDER BY company_name, contact_name, id DESC")
      ->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $externalHolders = [];
  }
}


/**
 * Returns asset IDs that already have a pending internal transfer.
 */
function pending_transfer_asset_ids(PDO $pdo, array $assetIds): array {
  $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn($v) => $v > 0)));
  if (!$assetIds) return [];

  $hasStatus = false;
  try {
    foreach ($pdo->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
      if ((string)$c['Field'] === 'status') { $hasStatus = true; break; }
    }
  } catch (Throwable $e) {
    return [];
  }
  if (!$hasStatus) return [];

  $in = implode(',', array_fill(0, count($assetIds), '?'));
  $st = $pdo->prepare("SELECT DISTINCT asset_id FROM asset_assignments WHERE status='pending' AND asset_id IN ($in)");
  $st->execute($assetIds);
  return array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'asset_id'));
}


$warehouses = [];
try {
  if (warehouses_schema_ready($pdo)) {
    $warehouses = $pdo->query("SELECT id, name, location FROM warehouses WHERE is_active=1 ORDER BY name")
      ->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  $warehouses = [];
}

// Átadás kérés (PENDING) - több eszköz egyszerre
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'transfer_request') {
    $toEmpId = (int)($_POST['to_employee_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    $note = ($note === '') ? null : $note;

    $assetIds = $_POST['asset_ids'] ?? [];
    if (!is_array($assetIds)) $assetIds = [];
    $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn($v)=>$v>0)));

    if ($toEmpId <= 0 || $toEmpId === $myEmpId) {
      flash_set('err', 'Válassz érvényes munkatársat (nem lehet saját magad).');
      header('Location: my_assets.php');
      exit;
    }
    if (!$assetIds) {
      flash_set('err', 'Nem jelöltél ki eszközt.');
      header('Location: my_assets.php');
      exit;
    }

    // Biztonság: csak a nálam lévő eszközöket lehessen átadni
    $in = implode(',', array_fill(0, count($assetIds), '?'));
    $chk = $pdo->prepare("SELECT id FROM assets WHERE is_deleted=0 AND current_employee_id=? AND id IN ($in)");
    $chk->execute(array_merge([$myEmpId], $assetIds));
    $okIds = array_map('intval', array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'id'));

    if (count($okIds) !== count($assetIds)) {
      flash_set('err', 'A kijelölt eszközök közül nem mind a te birtokodban van (vagy már törölt).');
      header('Location: my_assets.php');
      exit;
    }

    $pendingAssetIds = pending_transfer_asset_ids($pdo, $okIds);
    if ($pendingAssetIds) {
      flash_set('err', 'A kijelölt eszközök között van olyan, amelyre már függőben lévő belső átadás van. Előbb rendezd a függő átadást.');
      header('Location: my_assets.php');
      exit;
    }

    // Lejárat: 48 óra (később paraméterezhető)
    $expiresAt = (new DateTimeImmutable('now'))->modify('+48 hours')->format('Y-m-d H:i:s');

    // DB tranzakció
    $pdo->beginTransaction();
    try {
      // --- Helper: convert PNG with alpha (RGBA) to PNG without alpha (RGB) ---
      // SimplePDF (PDF generator) cannot embed PNG images with alpha channel.
      // We strip alpha by rendering onto a white background.
      // Ellenőrizzük, hogy vannak-e új oszlopok (status/expires_at). Ha nincsenek, akkor csak sima naplózás történik.
      $cols = [];
      $rs = $pdo->query("SHOW COLUMNS FROM asset_assignments");
      foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[(string)$c['Field']] = true;

      $hasStatus = isset($cols['status']);
      $hasExpires = isset($cols['expires_at']);

      foreach ($okIds as $aid) {
        if ($hasStatus && $hasExpires) {
          $pdo->prepare("
            INSERT INTO asset_assignments
              (asset_id, from_employee_id, to_employee_id, assigned_by_user_id, note, status, expires_at)
            VALUES (?,?,?,?,?,?,?)
          ")->execute([
            $aid, $myEmpId, $toEmpId, (int)($u['id'] ?? 0), $note, 'pending', $expiresAt
          ]);
        } elseif ($hasStatus) {
          $pdo->prepare("
            INSERT INTO asset_assignments
              (asset_id, from_employee_id, to_employee_id, assigned_by_user_id, note, status)
            VALUES (?,?,?,?,?,?)
          ")->execute([
            $aid, $myEmpId, $toEmpId, (int)($u['id'] ?? 0), $note, 'pending'
          ]);
        } else {
          // régi séma: csak naplózzuk, később bővítjük
          $pdo->prepare("
            INSERT INTO asset_assignments
              (asset_id, from_employee_id, to_employee_id, assigned_by_user_id, note)
            VALUES (?,?,?,?,?)
          ")->execute([
            $aid, $myEmpId, $toEmpId, (int)($u['id'] ?? 0), $note
          ]);
        }
      }

      $pdo->commit();
      flash_set('ok', 'Átadás kezdeményezve. A címzettnek meg fog jelenni elfogadásra.');
    } catch (Throwable $e) {
      $pdo->rollBack();
      flash_set('err', 'Hiba átadás indításakor: '.$e->getMessage());
    }

    header('Location: my_assets.php');
    exit;
  }


  if ($action === 'transfer_to_warehouse') {
    if (!warehouses_schema_ready($pdo)) {
      flash_set('err', 'Hiányzik a raktár modul séma. Futtasd: migrations/warehouses_phase1.sql');
      header('Location: my_assets.php');
      exit;
    }

    $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $note = trim((string)($_POST['warehouse_note'] ?? ''));
    $note = ($note === '') ? null : $note;

    $csv = trim((string)($_POST['warehouse_asset_ids_csv'] ?? ''));
    $assetIds = $csv !== '' ? explode(',', $csv) : [];
    $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn($v)=>$v>0)));

    if ($warehouseId <= 0) {
      flash_set('err', 'Válassz raktárat.');
      header('Location: my_assets.php');
      exit;
    }
    if (!$assetIds) {
      flash_set('err', 'Nem jelöltél ki eszközt.');
      header('Location: my_assets.php');
      exit;
    }

    $stWh = $pdo->prepare("SELECT id, name FROM warehouses WHERE id=? AND is_active=1 LIMIT 1");
    $stWh->execute([$warehouseId]);
    $warehouse = $stWh->fetch(PDO::FETCH_ASSOC);
    if (!$warehouse) {
      flash_set('err', 'A kiválasztott raktár nem található vagy inaktív.');
      header('Location: my_assets.php');
      exit;
    }

    $in = implode(',', array_fill(0, count($assetIds), '?'));
    $chk = $pdo->prepare("SELECT id FROM assets WHERE is_deleted=0 AND current_employee_id=? AND id IN ($in)");
    $chk->execute(array_merge([$myEmpId], $assetIds));
    $okIds = array_map('intval', array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'id'));

    if (count($okIds) !== count($assetIds)) {
      flash_set('err', 'A kijelölt eszközök közül nem mind a te birtokodban van.');
      header('Location: my_assets.php');
      exit;
    }

    $colsCheck = [];
    foreach ($pdo->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) $colsCheck[(string)$c['Field']] = true;
    if (isset($colsCheck['status'])) {
      $stPend = $pdo->prepare("SELECT asset_id FROM asset_assignments WHERE status='pending' AND asset_id IN ($in)");
      $stPend->execute($okIds);
      if ($stPend->fetchColumn()) {
        flash_set('err', 'Van olyan kiválasztott eszköz, amelyre már van függő belső átadás. Ezeket előbb rendezni kell.');
        header('Location: my_assets.php');
        exit;
      }
    }

    $pdo->beginTransaction();
    try {
      $upd = $pdo->prepare("UPDATE assets SET current_employee_id=NULL, current_warehouse_id=? WHERE id=?");
      foreach ($okIds as $aid) {
        $upd->execute([$warehouseId, $aid]);
      }
      $pdo->commit();
      flash_set('ok', 'Eszköz(ök) átadva a raktárnak: '.(string)$warehouse['name']);
    } catch (Throwable $e) {
      $pdo->rollBack();
      flash_set('err', 'Hiba raktárnak átadáskor: '.$e->getMessage());
    }

    header('Location: my_assets.php');
    exit;
  }

  if ($action === 'transfer_external') {
    if (!$hasExternalFlow) {
      flash_set('err', 'Hiányzik a külsős átadás séma. Futtasd: migrations/external_handover.sql');
      header('Location: my_assets.php');
      exit;
    }

    $holderId = (int)($_POST['external_holder_id'] ?? 0);
    $company = trim((string)($_POST['ext_company'] ?? ''));
    $contact = trim((string)($_POST['ext_contact'] ?? ''));
    $phone   = trim((string)($_POST['ext_phone'] ?? ''));
    $email   = trim((string)($_POST['ext_email'] ?? ''));
    $courier = trim((string)($_POST['courier_ref'] ?? '')); // szállítólevél szám (lehet üres)
    $note    = trim((string)($_POST['ext_note'] ?? ''));
    $note    = ($note === '') ? null : $note;

    // Aláírás (kötelező) - dataURL formában várjuk (PNG)
    $sigDataUrl = trim((string)($_POST['signature_png'] ?? ''));

    $csv = trim((string)($_POST['asset_ids_csv'] ?? ''));
    $assetIds = $csv !== '' ? explode(',', $csv) : [];
    $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn($v)=>$v>0)));

    if ($company === '') {
      flash_set('err', 'Külsős átadáshoz a cég neve kötelező.');
      header('Location: my_assets.php');
      exit;
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      flash_set('err', 'Az email cím formátuma hibás.');
      header('Location: my_assets.php');
      exit;
    }

    if ($sigDataUrl === '' || stripos($sigDataUrl, 'data:image/png;base64,') !== 0) {
      flash_set('err', 'Külsős átadáshoz kötelező az aláírás (nem lehet üres).');
      header('Location: my_assets.php');
      exit;
    }
    if (!$assetIds) {
      flash_set('err', 'Nem jelöltél ki eszközt.');
      header('Location: my_assets.php');
      exit;
    }

    $in = implode(',', array_fill(0, count($assetIds), '?'));
    $chk = $pdo->prepare("SELECT id FROM assets WHERE is_deleted=0 AND current_employee_id=? AND id IN ($in)");
    $chk->execute(array_merge([$myEmpId], $assetIds));
    $okIds = array_map('intval', array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'id'));
    if (count($okIds) !== count($assetIds)) {
      flash_set('err', 'A kijelölt eszközök közül nem mind a te birtokodban van.');
      header('Location: my_assets.php');
      exit;
    }

    $pendingAssetIds = pending_transfer_asset_ids($pdo, $okIds);
    if ($pendingAssetIds) {
      flash_set('err', 'A kijelölt eszközök között van olyan, amelyre már függőben lévő belső átadás van. Ilyen eszköz nem adható ki külsősnek.');
      header('Location: my_assets.php');
      exit;
    }

    $pdo->beginTransaction();
    try {
      // Ellenőrizzük, hogy létezik-e az aláírás mező (migráció után)
      $aeaCols = [];
      foreach ($pdo->query("SHOW COLUMNS FROM asset_external_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $aeaCols[(string)$c['Field']] = true;
      }
      $hasSigCol = isset($aeaCols['signature_path']);
      if (!$hasSigCol) {
        throw new RuntimeException("Hiányzik az aláírás mező a külsős átadáshoz. Futtasd: migrations/external_handover_signature.sql");
      }

      $hasSnapCols = isset($aeaCols['ext_company']) && isset($aeaCols['ext_contact']) && isset($aeaCols['ext_phone']);
      if (!$hasSnapCols) {
        throw new RuntimeException("Hiányzik a külsős partner-snapshot mező (ext_company/ext_contact/ext_phone). Futtasd: migrations/external_handover_snapshot.sql");
      }

      // Külsős kiválasztás / új felvitel
      if ($holderId > 0) {
        $stH = $pdo->prepare("SELECT id FROM external_holders WHERE id=? AND is_active=1 LIMIT 1");
        $stH->execute([$holderId]);
        if (!$stH->fetch(PDO::FETCH_ASSOC)) {
          throw new RuntimeException('A kiválasztott külsős nem található vagy inaktív.');
        }

        // Megengedjük, hogy kiválasztás esetén is módosítsd a partner adatait
        $pdo->prepare("UPDATE external_holders SET company_name=?, contact_name=?, phone=? WHERE id=?")
            ->execute([$company, $contact !== '' ? $contact : '', $phone !== '' ? $phone : null, $holderId]);

      } else {
        $insHolder = $pdo->prepare("INSERT INTO external_holders (company_name, contact_name, phone, is_active) VALUES (?,?,?,1)");
        $insHolder->execute([$company, $contact !== '' ? $contact : '', $phone !== '' ? $phone : null]);
        $holderId = (int)$pdo->lastInsertId();
      }

      // Aláírás mentése fájlba
      $raw = base64_decode(substr($sigDataUrl, strlen('data:image/png;base64,')), true);
      if ($raw === false || strlen($raw) < 200) {
        throw new RuntimeException('Hibás aláírás adat (nem sikerült dekódolni).');
      }

      $sigDir = __DIR__.'/../storage/uploads/external_signatures/'.$holderId;
      if (!is_dir($sigDir)) mkdir($sigDir, 0775, true);
      $sigFn = 'sig_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.png';
      $sigAbs = $sigDir.'/'.$sigFn;
      if (file_put_contents($sigAbs, $raw) === false) {
        throw new RuntimeException('Nem sikerült elmenteni az aláírást.');
      }

      // Make signature PNG PDF-safe (no alpha)
      stripPngAlphaInplace($sigAbs);
      $sigRel = '/storage/uploads/external_signatures/'.$holderId.'/'.$sigFn;

      $newIds = [];

      $insExt = $pdo->prepare("INSERT INTO asset_external_assignments (asset_id, external_holder_id, courier_ref, note, signature_path, ext_company, ext_contact, ext_phone, assigned_by_user_id, status) VALUES (?,?,?,?,?,?,?,?,?,'active')");
      $updAsset = $pdo->prepare("UPDATE assets SET current_employee_id=NULL WHERE id=?");

      foreach ($okIds as $aid) {
        $insExt->execute([$aid, $holderId, $courier, $note, $sigRel, $company, $contact !== '' ? $contact : null, $phone !== '' ? $phone : null, (int)($u['id'] ?? 0)]);
        $newIds[] = (int)$pdo->lastInsertId();
        $updAsset->execute([$aid]);
      }

      // PDF generálás + mentés (ha van megfelelő DB mező)
      $aeaCols2 = [];
      foreach ($pdo->query("SHOW COLUMNS FROM asset_external_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) $aeaCols2[(string)$c['Field']] = true;
      $hasPdfCol = isset($aeaCols2['pdf_path']);
      $hasEmailCol = isset($aeaCols2['ext_email']);

      $pdfRel = null;
      if ($hasPdfCol) {
        require_once __DIR__ . '/../app/pdf_mpdf.php';

        // Asset adatok + első fotó
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
            'serial' => (string)($a['sku'] ?? ''), // SKU-t használjuk azonosítónak
            'inventory' => (string)($a['qr_value'] ?? ''), // QR / leltár jellegű mező
            'category' => (string)($a['category_name'] ?? ''),
            'photo_abs' => ($photoAbs && is_file($photoAbs)) ? $photoAbs : null,
          ];
        }

        $assignedAt = date('Y-m-d H:i:s');
        $assignedBy = (string)($u['full_name'] ?? $u['email'] ?? ('#'.(string)($u['id'] ?? '')));

        $pdfRel = generate_external_handover_pdf_html([
          'company' => $company,
          'contact' => $contact !== '' ? $contact : null,
          'phone'   => $phone !== '' ? $phone : null,
          'email'   => $email !== '' ? $email : null,
          'courier_ref' => $courier !== '' ? $courier : null,
          'note'    => $note,
          'assigned_at' => $assignedAt,
          'assigned_by' => $assignedBy,
          'assets' => $assetsData,
          'signature_abs' => __DIR__ . '/..' . $sigRel,
          'asset_photo_abs' => (string)($assetsData[0]['photo_abs'] ?? ''),
        ]);

        // Update rows with pdf path (+ email snapshot if exists)
        $upd = $pdo->prepare("UPDATE asset_external_assignments SET pdf_path=?".($hasEmailCol?", ext_email=?":"")." WHERE id=?");
        foreach ($newIds as $rid) {
          $params = [$pdfRel];
          if ($hasEmailCol) $params[] = ($email !== '' ? $email : null);
          $params[] = $rid;
          $upd->execute($params);
        }
      }

      $pdo->commit();

      // Email küldés (nem része a tranzakciónak)
      if ($email !== '' && $pdfRel) {
        $cfg = require __DIR__ . '/../app/config.php';
        $from = (string)($cfg['mail_from'] ?? 'no-reply@localhost');
        $bcc  = (string)($cfg['mail_bcc'] ?? '');
        require_once __DIR__ . '/../app/mailer.php';

        $pdfAbs = __DIR__ . '/..' . $pdfRel;
        $subject = "Perfect-Phone – Eszköz átadás-átvétel";

        $assetItemsHtml = '';
        foreach ($assetsData as $a) {
          $line = htmlspecialchars((string)($a['name'] ?? ''), ENT_QUOTES, 'UTF-8');
          $cat = (string)($a['category'] ?? '');
          $inv = (string)($a['inventory'] ?? '');
          $ser = (string)($a['serial'] ?? '');
          if ($cat !== '') $line .= ' (' . htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') . ')';
          if ($inv !== '') $line .= ' | Leltár/QR: ' . htmlspecialchars($inv, ENT_QUOTES, 'UTF-8');
          if ($ser !== '') $line .= ' | SKU/SN: ' . htmlspecialchars($ser, ENT_QUOTES, 'UTF-8');
          $assetItemsHtml .= '<li style="margin:0 0 6px 0;">' . $line . '</li>';
        }

        $contactHtml = $contact !== '' ? '<tr><td style="padding:4px 0;width:180px;"><strong>Kapcsolattartó:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($contact, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
        $phoneHtml   = $phone   !== '' ? '<tr><td style="padding:4px 0;"><strong>Telefon:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
        $emailHtml   = $email   !== '' ? '<tr><td style="padding:4px 0;"><strong>Email:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
        $courierHtml = $courier !== '' ? '<tr><td style="padding:4px 0;"><strong>Szállítólevél:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($courier, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
        $noteHtml    = $note    !== null && $note !== '' ? '<p style="margin:14px 0 0 0;"><strong>Megjegyzés:</strong><br>' . nl2br(htmlspecialchars((string)$note, ENT_QUOTES, 'UTF-8')) . '</p>' : '';

        $body = '<!doctype html><html lang="hu"><head><meta charset="utf-8"></head>'
              . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">'
              . '<div style="max-width:700px;margin:0 auto;padding:24px;">'
              . '<div style="background:#ffffff;border:1px solid #ddd;border-radius:10px;overflow:hidden;">'
              . '<div style="padding:24px 24px 12px 24px;">'
              . '<h2 style="margin:0 0 16px 0;font-size:22px;">Eszköz átadás-átvételi értesítő</h2>'
              . '<p style="margin:0 0 16px 0;">Tisztelt Partner!</p>'
              . '<p style="margin:0 0 16px 0;">Csatoltan küldjük az eszköz átadás-átvételi jegyzőkönyvet PDF formátumban.</p>'
              . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px 0;">'
              . '<tr><td style="padding:4px 0;width:180px;"><strong>Átadás ideje:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($assignedAt, ENT_QUOTES, 'UTF-8') . '</td></tr>'
              . '<tr><td style="padding:4px 0;"><strong>Átadta:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($assignedBy, ENT_QUOTES, 'UTF-8') . '</td></tr>'
              . '<tr><td style="padding:4px 0;"><strong>Partner:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($company, ENT_QUOTES, 'UTF-8') . '</td></tr>'
              . $contactHtml . $phoneHtml . $emailHtml . $courierHtml
              . '</table>'
              . '<div style="margin:0 0 18px 0;"><strong>Eszközök:</strong>'
              . '<ul style="margin:8px 0 0 18px;padding:0;">' . $assetItemsHtml . '</ul></div>'
              . $noteHtml
              . '<p style="margin:18px 0 16px 0;">Üdvözlettel,<br><strong>Perfect-Phone</strong></p>'
              . '</div>'
              . '<div style="padding:16px 24px;border-top:1px solid #eee;background:#fcfcfc;text-align:left;">'
              . '<img src="cid:companylogo" alt="Perfect-Phone" style="max-height:48px;">'
              . '</div></div></div></body></html>';

        $okMail = send_mail_with_attachment($email, $subject, $body, $from, ($bcc !== '' ? $bcc : null), $pdfAbs, basename($pdfAbs));
        if ($okMail) {
          flash_set('ok', 'Külsős átadás rögzítve, PDF elkészült és email elküldve.');
        } else {
          flash_set('warn', 'Külsős átadás rögzítve, PDF elkészült, de az email küldés nem sikerült (PHPMailer hibát adott).');
        }
      } else {
        flash_set('ok', 'Külsős átadás rögzítve.');
      }
    } catch (Throwable $e) {
      $pdo->rollBack();
      flash_set('err', 'Hiba külsős átadáskor: '.$e->getMessage());
    }

    header('Location: my_assets.php');
    exit;
  }
}

$sql = "
  SELECT
    a.*,
    (SELECT file_path FROM asset_photos p
      WHERE p.asset_id=a.id
      ORDER BY p.is_primary DESC, p.id DESC
      LIMIT 1
    ) AS photo_path,
    (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
      FROM asset_category ac
      JOIN categories c ON c.id=ac.category_id AND c.is_deleted=0
      WHERE ac.asset_id=a.id
    ) AS cat_names
  FROM assets a
  WHERE a.is_deleted=0 AND a.current_employee_id=?
";
$params = [$myEmpId];

if ($q !== '') {
  $sql .= " AND (a.name LIKE ? OR a.sku LIKE ? OR a.qr_value LIKE ?)";
  $like = '%'.$q.'%';
  $params = array_merge($params, [$like,$like,$like]);
}

$sql .= " ORDER BY a.name ASC, a.id DESC LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

function photo_public_url(string $path): string {
  $p = trim($path);
  if ($p === '') return '';
  if (preg_match('~^https?://~i', $p)) return $p;
  $p = ltrim($p, '/');
  if (substr($p, 0, 8) === 'storage/') return '/'.$p;
  return '/storage/'.$p;
}

require __DIR__.'/_header.php';
?>

<div class="container" style="max-width:720px">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">Nálam lévő eszközök</h4>
      <?php if ($myName): ?>
        <div class="text-secondary small"><?= e($myName) ?></div>
      <?php else: ?>
        <div class="text-secondary small">HR ID: <?= (int)$myEmpId ?></div>
      <?php endif; ?>
    </div>
  </div>

  <form class="mb-3" method="get">
    <div class="input-group">
      <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Keresés: név, cikkszám, QR">
      <button class="btn btn-primary" type="submit">Keres</button>
      <?php if ($q !== ''): ?>
        <a class="btn btn-outline-secondary" href="my_assets.php">Törlés</a>
      <?php endif; ?>
    </div>
  </form>

  <form method="post" id="transferForm" class="card shadow-sm mb-3">
    <div class="card-body">
      <input type="hidden" name="action" value="transfer_request">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
          <label class="form-label">Átadás neki</label>
          <select class="form-select" name="to_employee_id" required>
            <option value="">— válassz —</option>
            <?php foreach ($hrEmployees as $eRow):
              $eid = (int)$eRow['id'];
              if ($eid === $myEmpId) continue;
            ?>
              <option value="<?= $eid ?>"><?= e($eRow['full_name'] ?? '') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Megjegyzés (opcionális)</label>
          <input class="form-control" name="note" placeholder="pl. töltő is jár hozzá">
        </div>
        <div class="col-12 d-flex justify-content-between align-items-center mt-2">
          <div class="form-text">
            Jelöld ki a kártyákon az eszközöket, majd indítsd az átadást. 
          </div>
          <button class="btn btn-success" type="submit">Átadás</button>
        </div>
      </div>
    </div>
  </form>


  <?php if (!empty($warehouses)): ?>
  <form method="post" id="warehouseTransferForm" class="card shadow-sm mb-3">
    <div class="card-body">
      <input type="hidden" name="action" value="transfer_to_warehouse">
      <input type="hidden" name="warehouse_asset_ids_csv" id="warehouse_asset_ids_csv" value="">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
          <label class="form-label">Átadás raktárnak</label>
          <select class="form-select" name="warehouse_id" required>
            <option value="">— válassz raktárat —</option>
            <?php foreach ($warehouses as $w): ?>
              <option value="<?= (int)$w['id'] ?>"><?= e((string)$w['name']) ?><?= !empty($w['location']) ? ' — '.e((string)$w['location']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Megjegyzés (opcionális)</label>
          <input class="form-control" name="warehouse_note" placeholder="pl. leadva a központi raktárba">
        </div>
        <div class="col-12 d-flex justify-content-between align-items-center mt-2">
          <div class="form-text">Jelöld ki a kártyákon az eszközöket, majd add át a kiválasztott raktárnak.</div>
          <button class="btn btn-secondary" type="submit">Raktárnak átadás</button>
        </div>
      </div>
    </div>
  </form>

  <?php endif; ?>

  <?php if ($hasExternalFlow): ?>
  <form method="post" id="externalTransferForm" class="card shadow-sm mb-3">
    <div class="card-body">
      <button class="btn btn-warning" type="button"
              data-bs-toggle="collapse" data-bs-target="#extCollapse"
              aria-expanded="false" aria-controls="extCollapse">
        Külsős átadás
      </button>

      <div id="extCollapse" class="collapse mt-3">
        <input type="hidden" name="action" value="transfer_external">
        <input type="hidden" name="asset_ids_csv" id="ext_asset_ids_csv" value="">
        <input type="hidden" name="signature_png" id="ext_signature_png" value="">

        <div class="row g-2">
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
            <div class="form-text">Válassz a listából, vagy hagyd “Új külsős” álláson és töltsd ki az adatokat.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Cég neve</label>
            <input class="form-control" name="ext_company" id="ext_company" required>
            <div class="form-text">Választás esetén automatikusan kitöltjük, de módosítható.</div>
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
            <input type="email" class="form-control" name="ext_email" id="ext_email" value="" placeholder="pl. partner@ceg.hu">
          </div>

          <div class="col-12">
            <label class="form-label">Szállítólevél szám (opcionális)</label>
            <input class="form-control" name="courier_ref" placeholder="pl. SL-2026-001">
          </div>

          <div class="col-12">
            <label class="form-label">Aláírás (kötelező)</label>
            <div class="border rounded p-2" style="background:#fff">
              <canvas id="extSigCanvas" style="width:100%;height:220px;border:2px dashed #bbb;border-radius:10px;touch-action:none"></canvas>
              <div class="d-flex gap-2 mt-2">
                <button class="btn btn-sm btn-outline-secondary" type="button" id="extSigClear">Törlés</button>
                <div class="text-secondary small align-self-center">Egérrel vagy ujjal/stylusszal rajzolható.</div>
              </div>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Megjegyzés</label>
            <input class="form-control" name="ext_note" placeholder="pl. külsős javítás / partner kiadás">
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-warning" type="submit">Rögzítés</button>
          </div>
        </div>
        <div class="form-text mt-2">A kijelölt eszközök külsős partnerhez kerülnek. Visszavétel az “Átadásra vár” menüből.</div>
      </div>
    </div>
  </form>
  <?php else: ?>
    <div class="alert alert-warning">Külsős átadás még nincs migrálva. Futtasd: <code>migrations/external_handover.sql</code></div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="alert alert-info">Nincs nálad eszköz (vagy a keresés nem adott találatot).</div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="selectAll">
      <label class="form-check-label" for="selectAll">Összes kijelölése</label>
    </div>
    <div class="text-secondary small"><?= count($rows) ?> db</div>
  </div>

  <div class="d-grid gap-2">
    <?php foreach ($rows as $r): ?>
      <?php
        $photo = (string)($r['photo_path'] ?? '');
        $photoUrl = $photo !== '' ? photo_public_url($photo) : '';
        $cats  = (string)($r['cat_names'] ?? '');
        $sku   = (string)($r['sku'] ?? '');
        $qr    = (string)($r['qr_value'] ?? '');
        $valA  = $r['value_amount'] ?? null;
        $valC  = (string)($r['value_currency'] ?? '');
        $aid   = (int)$r['id'];
      ?>
      <div class="card shadow-sm">
        <div class="card-body d-flex gap-3">
          <div style="width:28px;flex:0 0 28px">
            <div class="form-check mt-1">
              <input class="form-check-input assetCb" type="checkbox" name="asset_ids[]" value="<?= $aid ?>" form="transferForm" id="a<?= $aid ?>">
            </div>
          </div>

          <div style="width:96px;flex:0 0 96px">
            <?php if ($photoUrl !== ''): ?>
              <img src="<?= e($photoUrl) ?>" alt="" class="img-fluid rounded" style="max-height:96px;object-fit:cover;width:96px">
            <?php else: ?>
              <div class="bg-light border rounded d-flex align-items-center justify-content-center" style="height:96px;width:96px">
                <span class="text-secondary small">nincs kép</span>
              </div>
            <?php endif; ?>
          </div>

          <div class="flex-grow-1">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <label class="fw-semibold d-block" for="a<?= $aid ?>" style="cursor:pointer"><?= e($r['name'] ?? '') ?></label>
                <div class="text-secondary small">#<?= $aid ?></div>
              </div>
              <a class="btn btn-sm btn-outline-primary" href="asset_edit.php?id=<?= $aid ?>">Megnyit</a>
            </div>

            <?php if ($sku !== ''): ?>
              <div class="small mt-2"><strong>Cikkszám:</strong> <?= e($sku) ?></div>
            <?php endif; ?>
            <?php if ($qr !== ''): ?>
              <div class="small"><strong>QR:</strong> <?= e($qr) ?></div>
            <?php endif; ?>
            <?php if ($cats !== ''): ?>
              <div class="small"><strong>Kategória:</strong> <?= e($cats) ?></div>
            <?php endif; ?>
            <?php if ($valA !== null && $valA !== ''): ?>
              <div class="small"><strong>Érték:</strong> <?= e((string)$valA) ?> <?= e($valC) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function(){
  const selAll = document.getElementById('selectAll');
  const cbs = () => Array.from(document.querySelectorAll('.assetCb'));
  const selectedIds = () => cbs().filter(cb => cb.checked).map(cb => cb.value);

  if (selAll) {
    selAll.addEventListener('change', function(){
      cbs().forEach(cb => cb.checked = selAll.checked);
    });
  }

  const extForm = document.getElementById('externalTransferForm');
  const extCsv = document.getElementById('ext_asset_ids_csv');
  const sigInp = document.getElementById('ext_signature_png');
  const sigCanvas = document.getElementById('extSigCanvas');
  const sigClear = document.getElementById('extSigClear');
  const extCollapse = document.getElementById('extCollapse');

  // --- Aláírás pad (egér + touch + stylus) ---
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
  function point(e){
    const r = sigCanvas.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  }
  function start(e){
    drawing = true;
    const p = point(e);
    sigCtx.beginPath();
    sigCtx.moveTo(p.x, p.y);
  }
  function move(e){
    if (!drawing) return;
    const p = point(e);
    sigCtx.lineTo(p.x, p.y);
    sigCtx.stroke();
    sigHasDrawn = true;
  }
  function end(){
    drawing = false;
    if (sigCtx) sigCtx.closePath();
  }

  if (sigCanvas) {
    // Ha a collapse zárt, a canvas mérete 0 lehet. Ezért méretezünk nyitáskor is.
    sigResize();
    window.addEventListener('resize', sigResize);
    sigCanvas.addEventListener('pointerdown', (e)=>{ e.preventDefault(); sigCanvas.setPointerCapture(e.pointerId); start(e); });
    sigCanvas.addEventListener('pointermove', (e)=>{ e.preventDefault(); move(e); });
    sigCanvas.addEventListener('pointerup',   (e)=>{ e.preventDefault(); end(); });
    sigCanvas.addEventListener('pointercancel', (e)=>{ e.preventDefault(); end(); });
  }

  // Bootstrap collapse esemény: nyitás után újraméretezés (különben üres/0px lehet)
  if (extCollapse) {
    extCollapse.addEventListener('shown.bs.collapse', function(){
      sigResize();
    });
  }
  if (sigClear) sigClear.addEventListener('click', sigClearAll);
  if (extForm && extCsv) {
    extForm.addEventListener('submit', function(e){
      const ids = selectedIds();
      if (!ids.length) {
        e.preventDefault();
        alert('Jelölj ki legalább 1 eszközt a külsős átadáshoz.');
        return;
      }

      // Aláírás kötelező
      if (!sigCanvas || !sigInp) {
        e.preventDefault();
        alert('Aláírás mező nem elérhető.');
        return;
      }
      if (!sigHasDrawn) {
        e.preventDefault();
        alert('Az aláírás kötelező (nem lehet üres).');
        return;
      }

      extCsv.value = ids.join(',');
      sigInp.value = sigCanvas.toDataURL('image/png');
    });
  }

  // Külsős partner kiválasztás: lista -> mezők előtöltése, de bármikor módosítható
  const holderSel = document.getElementById('external_holder_id');
  const extCompany = document.getElementById('ext_company');
  const extContact = document.getElementById('ext_contact');
  const extPhone   = document.getElementById('ext_phone');

  function fillFromSelected() {
    if (!holderSel) return;
    const id = parseInt(holderSel.value || '0', 10);

    if (id <= 0) {
      // Új külsős: ürítsük
      if (extCompany) extCompany.value = '';
      if (extContact) extContact.value = '';
      if (extPhone) extPhone.value = '';
      return;
    }

    const opt = holderSel.options[holderSel.selectedIndex];
    if (!opt) return;

    if (extCompany) extCompany.value = opt.getAttribute('data-company') || '';
    if (extContact) extContact.value = opt.getAttribute('data-contact') || '';
    if (extPhone)   extPhone.value   = opt.getAttribute('data-phone') || '';
  }

  if (holderSel) holderSel.addEventListener('change', fillFromSelected);
  fillFromSelected();

  const whForm = document.getElementById('warehouseTransferForm');
  const whCsv = document.getElementById('warehouse_asset_ids_csv');
  if (whForm && whCsv) {
    whForm.addEventListener('submit', function(e){
      const ids = selectedIds();
      if (!ids.length) {
        e.preventDefault();
        alert('Jelölj ki legalább 1 eszközt a raktárnak átadáshoz.');
        return;
      }
      whCsv.value = ids.join(',');
    });
  }

})();
</script>

<?php require __DIR__.'/_footer.php'; ?>
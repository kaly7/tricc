<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require __DIR__.'/../app/mailer.php';
require __DIR__.'/../app/pdf_mpdf.php';
require_login();
$u = current_user();

$title = 'Átadásra vár';
$page  = 'Átadásra vár';
$pdo = db();

// HR employee azonosító több helyről
$myEmpId = (int)($u['hr_employee_id'] ?? 0);
if ($myEmpId <= 0) $myEmpId = (int)($_SESSION['user']['hr_employee_id'] ?? 0);
if ($myEmpId <= 0) $myEmpId = (int)($_SESSION['hr_employee_id'] ?? 0);
if ($myEmpId <= 0) $myEmpId = (int)($_SESSION['auth_user']['hr_employee_id'] ?? 0);

if ($myEmpId <= 0) {
  require __DIR__.'/_header.php';
  ?>
  <div class="container" style="max-width:720px">
    <div class="alert alert-warning">
      Ehhez a felhasználóhoz nincs HR munkatárs rendelve (vagy nem került be a sessionbe).
      Állítsd be az Auth Centerben, majd jelentkezz ki/be.
    </div>
    <a class="btn btn-outline-secondary" href="logout.php">Kijelentkezés</a>
  </div>
  <?php require __DIR__.'/_footer.php'; exit;
}

function photo_public_url(string $path): string {
  $p = trim($path);
  if ($p === '') return '';
  if (preg_match('~^https?://~i', $p)) return $p;
  $p = ltrim($p, '/');
  if (substr($p, 0, 8) === 'storage/') return '/'.$p;
  return '/storage/'.$p;
}

// Ellenőrizzük, hogy van-e status oszlop
$cols = [];
foreach ($pdo->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
  $cols[(string)$c['Field']] = true;
}
$hasStatus = isset($cols['status']);
$hasExpires = isset($cols['expires_at']);

if (!$hasStatus) {
  require __DIR__.'/_header.php';
  ?>
  <div class="container" style="max-width:720px">
    <div class="alert alert-warning">
      Az átadás/átvétel inbox funkcióhoz hiányzik az <code>asset_assignments.status</code> mező.
      Futtasd a migrációt: <code>migrations/asset_assignments_pending.sql</code>
    </div>
    <a class="btn btn-primary" href="my_assets.php">Nálam lévő eszközök</a>
  </div>
  <?php require __DIR__.'/_footer.php'; exit;
}

// Lejárt függő átadások automatikus lezárása
if ($hasExpires) {
  $pdo->exec("UPDATE asset_assignments
              SET status='expired', responded_at=NOW(),
                  response_note=COALESCE(response_note,'Lejárt automatikusan')
              WHERE status='pending' AND expires_at IS NOT NULL AND expires_at < NOW()");
}

// HR névtérkép (from/to)
$hr = db_hr();
$empMap = [];
try {
  foreach ($hr->query("SELECT id, full_name FROM employees")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $empMap[(int)$e['id']] = (string)$e['full_name'];
  }
} catch (Throwable $e) {
  $empMap = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $assignId = (int)($_POST['assignment_id'] ?? 0);
  $note = trim((string)($_POST['response_note'] ?? ''));
  $note = ($note === '') ? null : $note;
  $returnNote = trim((string)($_POST['return_note'] ?? ''));
  $returnNote = ($returnNote === '') ? null : $returnNote;

	// Külsős visszavétel (bármely bejelentkezett felhasználó visszaveheti a kint lévő eszközt)
  if ($action === 'return_external') {
    $extAssignId = (int)($_POST['ext_assignment_id'] ?? 0);
    if ($extAssignId <= 0) {
      flash_set('err', 'Hiányzó külsős visszavételi azonosító.');
      header('Location: inbox.php');
      exit;
    }

    // helper: /storage/... -> abs path
    $toAbs = function(string $webOrAbs): string {
      $p = trim($webOrAbs);
      if ($p === '') return '';
      if ($p[0] === '/' && str_starts_with($p, '/storage/')) {
        return __DIR__ . '/..' . $p;
      }
      // already absolute?
      if ($p[0] === '/' && is_file($p)) return $p;
      // relative storage path like storage/...
      $p = ltrim($p, '/');
      if (str_starts_with($p, 'storage/')) return __DIR__ . '/../' . $p;
      return $p;
    };

    $xea = null;
    try {
      $pdo->beginTransaction();

      $stx = $pdo->prepare("
        SELECT aea.*, eh.company_name, eh.contact_name, eh.phone
        FROM asset_external_assignments aea
        JOIN external_holders eh ON eh.id=aea.external_holder_id
        WHERE aea.id=? AND aea.status='active'
        LIMIT 1
      ");
      $stx->execute([$extAssignId]);
      $xea = $stx->fetch(PDO::FETCH_ASSOC);
      if (!$xea) throw new RuntimeException('Nincs aktív külsős átadás ezzel az azonosítóval.');

      $assetId = (int)$xea['asset_id'];

      // státusz: returned + meta
      $pdo->prepare("UPDATE asset_external_assignments
            SET status='returned',
                returned_at=NOW(),
                returned_by_user_id=?,
                returned_to_employee_id=?,
                return_note=?
            WHERE id=?")
          ->execute([(int)($u['id'] ?? 0), $myEmpId, $returnNote, $extAssignId]);

      // eszköz visszakerül a munkatárshoz
      $pdo->prepare("UPDATE assets SET current_employee_id=? WHERE id=?")
          ->execute([$myEmpId, $assetId]);

      $pdo->commit();

      // --- PDF + email (külön try, hogy a visszavétel akkor is sikeres legyen, ha PDF/email hibázik) ---
      try {
        // Név térképek (Auth Center users)
        $auth = auth_pdo();
        $authUserMap = [];
        foreach ($auth->query("SELECT id, COALESCE(NULLIF(full_name,''), NULLIF(username,''), email) AS nm FROM users")->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $authUserMap[(int)$row['id']] = (string)$row['nm'];
        }

        // asset + photo
        $stA = $pdo->prepare("SELECT id, name, sku, qr_value FROM assets WHERE id=? LIMIT 1");
        $stA->execute([(int)$xea['asset_id']]);
        $asset = $stA->fetch(PDO::FETCH_ASSOC) ?: [];

        $photoAbs = '';
        try {
          $stP = $pdo->prepare("SELECT file_path FROM asset_photos WHERE asset_id=? ORDER BY is_primary DESC, id DESC LIMIT 1");
          $stP->execute([(int)$xea['asset_id']]);
          $photo = (string)($stP->fetchColumn() ?: '');
          if ($photo !== '') $photoAbs = $toAbs(photo_public_url($photo));
        } catch (Throwable $e) { /* ignore */ }

        // signature abs (handover signature)
        $sigAbs = '';
        $sigWeb = (string)($xea['signature_path'] ?? '');
        if ($sigWeb !== '') $sigAbs = $toAbs($sigWeb);

        $assignedBy = $authUserMap[(int)($xea['assigned_by_user_id'] ?? 0)] ?? ('#'.(int)($xea['assigned_by_user_id'] ?? 0));
        $returnedBy = $authUserMap[(int)($u['id'] ?? 0)] ?? ('#'.(int)($u['id'] ?? 0));
        $returnedTo = $empMap[$myEmpId] ?? ('#'.$myEmpId);

        // Reload timestamps (to get returned_at)
        $stR = $pdo->prepare("SELECT assigned_at, returned_at FROM asset_external_assignments WHERE id=? LIMIT 1");
        $stR->execute([$extAssignId]);
        $times = $stR->fetch(PDO::FETCH_ASSOC) ?: [];

        $pdfWeb = generate_external_return_pdf_html([
          'assigned_at' => (string)($times['assigned_at'] ?? ($xea['assigned_at'] ?? '')),
          'returned_at' => (string)($times['returned_at'] ?? ''),
          'assigned_by' => (string)$assignedBy,
          'returned_by' => (string)$returnedBy,
          'returned_to' => (string)$returnedTo,
          'company'     => (string)($xea['company_name'] ?? ''),
          'contact'     => (string)($xea['contact_name'] ?? ''),
          'phone'       => (string)($xea['phone'] ?? ''),
          'email'       => (string)($xea['ext_email'] ?? ($xea['email'] ?? '')),
          'courier_ref' => (string)($xea['courier_ref'] ?? ''),
          'note'        => (string)($xea['note'] ?? ''),
          'return_note' => (string)($returnNote ?? ''),
          'assets'      => [[
            'name'      => (string)($asset['name'] ?? ''),
            'inventory' => (string)($asset['qr_value'] ?? ''),
            'serial'    => (string)($asset['sku'] ?? ''),
            'photo_abs' => $photoAbs,
          ]],
          'signature_abs' => $sigAbs,
        ]);

        // save return pdf path
        $pdo->prepare("UPDATE asset_external_assignments SET return_pdf_path=? WHERE id=?")
            ->execute([$pdfWeb, $extAssignId]);

        // email (if ext_email exists)
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
                . '<tr><td style="padding:4px 0;"><strong>Átadta:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($assignedBy, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Visszavétel ideje:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)($times['returned_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Visszavette:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($returnedBy, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Visszakerült:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($returnedTo, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Partner:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)($xea['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . $contactHtml . $phoneHtml . $emailHtml
                . '</table>'
                . '<div style="margin:0 0 18px 0;"><strong>Eszköz:</strong>'
                . '<div style="margin-top:8px;padding:12px;background:#fafafa;border:1px solid #e5e5e5;border-radius:8px;">' . $assetLine . '</div></div>'
                . $returnNoteHtml
                . '<p style="margin:18px 0 16px 0;">Üdvözlettel,<br><strong>Perfect-Phone</strong></p>'
                . '</div>'
                . '<div style="padding:16px 24px;border-top:1px solid #eee;background:#fcfcfc;text-align:left;">'
                . '<img src="cid:companylogo" alt="Perfect-Phone" style="max-height:48px;">'
                . '</div></div></div></body></html>';

          $pdfAbs = __DIR__ . '/..' . $pdfWeb;
          send_mail_with_attachment($to, $subj, $body, $from, $bcc, $pdfAbs, basename($pdfAbs));
        }
      } catch (Throwable $e) {
        // keep user flow ok, but show a warning
        flash_set('err', 'Visszavéve, de a PDF/email generálás hibázott: '.$e->getMessage());
      }

      flash_set('ok', 'Eszköz visszavéve a külsőstől.');
    } catch (Throwable $e) {
      $pdo->rollBack();
      flash_set('err', 'Visszavételi hiba: '.$e->getMessage());
    }
    header('Location: inbox.php');
    exit;
  }

  if (!in_array($action, ['accept','reject'], true) || $assignId <= 0) {
    flash_set('err', 'Hiányzó adatok.');
    header('Location: inbox.php');
    exit;
  }

  // Betöltjük az assignmentet és validáljuk
  $st = $pdo->prepare("SELECT * FROM asset_assignments WHERE id=? LIMIT 1");
  $st->execute([$assignId]);
  $as = $st->fetch(PDO::FETCH_ASSOC);

  if (!$as) {
    flash_set('err', 'Nem található átadás.');
    header('Location: inbox.php');
    exit;
  }
  if ((int)($as['to_employee_id'] ?? 0) !== $myEmpId) {
    flash_set('err', 'Nincs jogosultság ehhez az átadáshoz.');
    header('Location: inbox.php');
    exit;
  }
  if ((string)($as['status'] ?? '') !== 'pending') {
    flash_set('err', 'Ez az átadás már nem függőben van.');
    header('Location: inbox.php');
    exit;
  }

  // Lejárt kérelem ne legyen elfogadható/elutasítható, zárjuk le expired-re
  if (!empty($as['expires_at']) && strtotime((string)$as['expires_at']) < time()) {
    $pdo->prepare("UPDATE asset_assignments SET status='expired', responded_at=NOW(), response_note=COALESCE(response_note,'Lejárt automatikusan') WHERE id=?")
        ->execute([$assignId]);
    flash_set('err', 'Ez az átadás lejárt, automatikusan lezárva.');
    header('Location: inbox.php');
    exit;
  }

  $assetId = (int)($as['asset_id'] ?? 0);
  $fromEmpId = (int)($as['from_employee_id'] ?? 0);

  // Biztonság: az eszköz még mindig az előző tulajnál legyen (különben nem írjuk át)
  $stA = $pdo->prepare("SELECT current_employee_id FROM assets WHERE id=? AND is_deleted=0 LIMIT 1");
  $stA->execute([$assetId]);
  $curEmp = (int)($stA->fetchColumn() ?: 0);

  if ($curEmp !== $fromEmpId) {
    flash_set('err', 'Az eszköz közben máshoz került, nem lehet feldolgozni.');
    header('Location: inbox.php');
    exit;
  }

  $pdo->beginTransaction();
  try {
    if ($action === 'accept') {
      // status accepted + átvétel rögzítés
      $pdo->prepare("UPDATE asset_assignments SET status='accepted', responded_at=NOW(), response_note=? WHERE id=?")
          ->execute([$note, $assignId]);

      // tényleges birtokos váltás
      $pdo->prepare("UPDATE assets SET current_employee_id=? WHERE id=?")->execute([$myEmpId, $assetId]);

      flash_set('ok', 'Átvétel elfogadva, az eszköz most már nálad van.');
    } else {
      $pdo->prepare("UPDATE asset_assignments SET status='rejected', responded_at=NOW(), response_note=? WHERE id=?")
          ->execute([$note, $assignId]);

      flash_set('ok', 'Átvétel elutasítva. Az eszköz az előző munkatársnál marad.');
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('err', 'Hiba feldolgozáskor: '.$e->getMessage());
  }

  header('Location: inbox.php');
  exit;
}

// Listázzuk a pending átadásokat (a lejártakat is mutatjuk külön jelzéssel)
$sql = "
  SELECT aa.*,
         a.name AS asset_name,
         a.sku  AS asset_sku,
         a.qr_value AS asset_qr,
         (SELECT file_path FROM asset_photos p
            WHERE p.asset_id=a.id
            ORDER BY p.is_primary DESC, p.id DESC
            LIMIT 1
         ) AS photo_path
  FROM asset_assignments aa
  JOIN assets a ON a.id=aa.asset_id AND a.is_deleted=0
  WHERE aa.to_employee_id=? AND aa.status='pending'
  ORDER BY COALESCE(aa.expires_at, '9999-12-31') ASC, aa.id DESC
  LIMIT 300
";
$st = $pdo->prepare($sql);
$st->execute([$myEmpId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTimeImmutable('now');

// Külsősnél lévő eszközök (minden aktív külsős kiadás – bármely user visszaveheti)
$extRows = [];
try {
  $extRows = $pdo->query("
    SELECT aea.id AS aea_id, aea.asset_id, aea.assigned_at, aea.courier_ref, aea.note, aea.signature_path,
           eh.company_name, eh.contact_name,
           a.name AS asset_name, a.sku AS asset_sku
    FROM asset_external_assignments aea
    JOIN external_holders eh ON eh.id=aea.external_holder_id
    JOIN assets a ON a.id=aea.asset_id AND a.is_deleted=0
	    WHERE aea.status='active'
    ORDER BY aea.assigned_at DESC, aea.id DESC
    LIMIT 300
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $extRows = [];
}

require __DIR__.'/_header.php';
?>

<div class="container" style="max-width:720px">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">Átadásra vár</h4>
      <div class="text-secondary small">Itt tudod elfogadni vagy elutasítani a neked átadott eszközöket.</div>
    </div>
    <a class="btn btn-outline-secondary" href="my_assets.php">Nálam lévő eszközök</a>
  </div>

  <?php if (!$rows): ?>
    <div class="alert alert-info">Nincs függőben lévő átadás.</div>
  <?php endif; ?>

  <div class="d-grid gap-2">
    <?php foreach ($rows as $r): ?>
      <?php
        $photo = (string)($r['photo_path'] ?? '');
        $photoUrl = $photo !== '' ? photo_public_url($photo) : '';
        $fromId = (int)($r['from_employee_id'] ?? 0);
        $fromName = $empMap[$fromId] ?? ($fromId ? '#'.$fromId : '');
        $expRaw = (string)($r['expires_at'] ?? '');
        $expired = false;
        if ($expRaw !== '') {
          try {
            $exp = new DateTimeImmutable($expRaw);
            $expired = ($exp < $now);
          } catch (Throwable $e) {}
        }
      ?>
      <div class="card shadow-sm <?= $expired ? 'border-warning' : '' ?>">
        <div class="card-body d-flex gap-3">
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
                <div class="fw-semibold"><?= e($r['asset_name'] ?? '') ?></div>
                <div class="text-secondary small">#<?= (int)($r['asset_id'] ?? 0) ?></div>
              </div>
              <div class="text-end">
                <?php if ($expired): ?>
                  <span class="badge bg-warning text-dark">Lejárt</span>
                <?php else: ?>
                  <span class="badge bg-info text-dark">Függőben</span>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!empty($r['asset_sku'])): ?>
              <div class="small mt-2"><strong>Cikkszám:</strong> <?= e((string)$r['asset_sku']) ?></div>
            <?php endif; ?>
            <?php if (!empty($r['asset_qr'])): ?>
              <div class="small"><strong>QR:</strong> <?= e((string)$r['asset_qr']) ?></div>
            <?php endif; ?>
            <?php if ($fromName): ?>
              <div class="small"><strong>Átadja:</strong> <?= e($fromName) ?></div>
            <?php endif; ?>
            <?php if (!empty($r['note'])): ?>
              <div class="small"><strong>Megjegyzés:</strong> <?= e((string)$r['note']) ?></div>
            <?php endif; ?>
            <?php if (!empty($r['expires_at'])): ?>
              <div class="small text-secondary"><strong>Határidő:</strong> <?= e((string)$r['expires_at']) ?></div>
            <?php endif; ?>

            <form method="post" class="mt-3">
              <input type="hidden" name="assignment_id" value="<?= (int)($r['id'] ?? 0) ?>">
              <div class="row g-2">
                <div class="col-12">
                  <input class="form-control" name="response_note" placeholder="Megjegyzés (opcionális)">
                </div>
                <div class="col-12 d-flex gap-2">
                  <button class="btn btn-success flex-fill" name="action" value="accept" type="submit">Elfogad</button>
                  <button class="btn btn-outline-danger flex-fill" name="action" value="reject" type="submit" onclick="return confirm('Biztosan elutasítod az átvételt?');">Elutasít</button>
                </div>
              </div>
            </form>

          </div>
        </div>
      </div>
    <?php endforeach; ?>
  

<hr class="my-4">

<div class="d-flex justify-content-between align-items-center mb-2">
  <div>
    <h5 class="mb-0">Külsősnél lévő eszközök</h5>
	    <div class="text-secondary small">Minden kint lévő eszköz listája. Itt bármelyik eszközt visszaveheted a külsőstől.</div>
  </div>
</div>

<?php if (!$extRows): ?>
  <div class="alert alert-light border">Nincs külsősnél lévő eszköz.</div>
<?php else: ?>
  <div class="list-group">
    <?php foreach ($extRows as $x): ?>
      <div class="list-group-item">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="fw-semibold">
              <?= e((string)$x['asset_name']) ?>
              <?php if (!empty($x['asset_sku'])): ?>
                <span class="text-secondary small">(<?= e((string)$x['asset_sku']) ?>)</span>
              <?php endif; ?>
            </div>
            <div class="small">
              <strong>Partner:</strong> <?= e((string)$x['company_name']) ?>
              <?php if (!empty($x['contact_name'])): ?>
                — <?= e((string)$x['contact_name']) ?>
              <?php endif; ?>
            </div>
            <div class="small text-secondary">
              <strong>Átadás ideje:</strong> <?= e((string)$x['assigned_at']) ?>
              <?php if (!empty($x['courier_ref'])): ?>
                &nbsp;|&nbsp;<strong>Szállítólevél:</strong> <?= e((string)$x['courier_ref']) ?>
              <?php endif; ?>
            </div>
            <?php if (!empty($x['note'])): ?>
              <div class="small"><strong>Megjegyzés:</strong> <?= e((string)$x['note']) ?></div>
            <?php endif; ?>
            <?php if (!empty(photo_public_url((string)$x['signature_path']))): ?>
              <div class="mt-2">
                <div class="small text-secondary mb-1"><strong>Aláírás:</strong></div>
                <a href="<?= e((string)photo_public_url((string)$x['signature_path'])) ?>" target="_blank">
                  <img src="<?= e((string)photo_public_url((string)$x['signature_path'])) ?>" alt="Aláírás" style="max-width:260px;max-height:120px;border:1px solid #ddd;border-radius:8px;background:#fff">
                </a>
              </div>
            <?php endif; ?>
          </div>

          <form method="post" class="ms-3">
            <input type="hidden" name="action" value="return_external">
            <input type="hidden" name="ext_assignment_id" value="<?= (int)$x['aea_id'] ?>">
            <input type="text" name="return_note" class="form-control form-control-sm mt-2" placeholder="Megjegyzés (opcionális)">
            <button class="btn btn-outline-primary" type="submit"
                    onclick="return confirm('Biztosan visszaveszed az eszközt a külsőstől?');">
              Visszavétel
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</div>

<?php require __DIR__.'/_footer.php'; ?>

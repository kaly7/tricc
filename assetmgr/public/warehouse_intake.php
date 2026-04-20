<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require __DIR__.'/../app/warehouses.php';
require __DIR__.'/../app/pdf_mpdf.php';
require __DIR__.'/../app/mailer.php';
require_login();

$u = current_user();
$pdo = db();
$title = 'Raktárba vétel';
$page  = 'Raktárba vétel';

if (!warehouses_schema_ready($pdo)) {
  require __DIR__.'/_header.php'; ?>
  <div class="container" style="max-width:980px">
    <div class="alert alert-warning">A raktár modul még nincs migrálva. Futtasd: <code>migrations/warehouses_phase1.sql</code></div>
  </div>
  <?php require __DIR__.'/_footer.php'; exit;
}

if (!warehouse_is_admin($u)) {
  http_response_code(403);
  require __DIR__.'/_header.php'; ?>
  <div class="container" style="max-width:980px">
    <div class="alert alert-danger">Ehhez az oldalhoz nincs jogosultságod.</div>
  </div>
  <?php require __DIR__.'/_footer.php'; exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$holderEmp = (int)($_GET['employee_id'] ?? 0);
$holderWh = (int)($_GET['warehouse_filter_id'] ?? 0);
$pageNo = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($pageNo - 1) * $perPage;

$accessibleWarehouses = warehouses_for_user($u);
$allWarehouses = warehouses_all_active();

$hrEmployees = [];
try {
  $hr = db_hr();
  $hrEmployees = $hr->query("SELECT id, full_name, is_active FROM employees WHERE is_active=1 ORDER BY full_name")
                    ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $hrEmployees = [];
}

$empMap = [];
foreach ($hrEmployees as $e) $empMap[(int)$e['id']] = (string)$e['full_name'];

$warehouseMap = [];
foreach ($allWarehouses as $w) $warehouseMap[(int)$w['id']] = (string)$w['name'];

function intake_holder_label(array $asset, array $empMap, array $warehouseMap): string {
  $empId = (int)($asset['current_employee_id'] ?? 0);
  $whId = (int)($asset['current_warehouse_id'] ?? 0);
  if ($empId > 0) return 'Dolgozó: ' . ($empMap[$empId] ?? ('#'.$empId));
  if ($whId > 0) return 'Raktár: ' . ($warehouseMap[$whId] ?? ('#'.$whId));
  return 'Nincs hozzárendelve';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'bulk_intake') {
  verify_csrf();

  $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
  $note = trim((string)($_POST['note'] ?? ''));
  $recipientEmail = trim((string)($_POST['recipient_email'] ?? ''));
  $csv = trim((string)($_POST['asset_ids_csv'] ?? ''));
  $assetIds = $csv !== '' ? explode(',', $csv) : [];
  $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn($v)=>$v>0)));

  if (!warehouse_is_admin($u, $warehouseId)) {
    flash_set('err', 'Ehhez a raktárhoz nincs jogosultságod.');
    header('Location: warehouse_intake.php');
    exit;
  }
  if ($warehouseId <= 0) {
    flash_set('err', 'Válassz raktárat.');
    header('Location: warehouse_intake.php');
    exit;
  }
  if (!$assetIds) {
    flash_set('err', 'Nem jelöltél ki eszközt.');
    header('Location: warehouse_intake.php');
    exit;
  }
  if ($recipientEmail !== '' && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    flash_set('err', 'Az email cím formátuma hibás.');
    header('Location: warehouse_intake.php');
    exit;
  }

  $stWh = $pdo->prepare("SELECT id, name FROM warehouses WHERE id=? AND is_active=1 LIMIT 1");
  $stWh->execute([$warehouseId]);
  $warehouse = $stWh->fetch(PDO::FETCH_ASSOC);
  if (!$warehouse) {
    flash_set('err', 'A kiválasztott raktár nem található vagy inaktív.');
    header('Location: warehouse_intake.php');
    exit;
  }

  $in = implode(',', array_fill(0, count($assetIds), '?'));

  $stCheck = $pdo->prepare("SELECT a.id, a.name, a.sku, a.qr_value, a.current_employee_id, a.current_warehouse_id
                            FROM assets a
                            WHERE a.is_deleted=0
                              AND a.id IN ($in)
                              AND NOT EXISTS (
                                SELECT 1 FROM asset_external_assignments x
                                WHERE x.asset_id=a.id AND x.status='active'
                              )");
  $stCheck->execute($assetIds);
  $items = $stCheck->fetchAll(PDO::FETCH_ASSOC);

  if (count($items) !== count($assetIds)) {
    flash_set('err', 'A kijelölt eszközök között van olyan, amely külsősnél van vagy nem mozgatható.');
    header('Location: warehouse_intake.php');
    exit;
  }

  // Már ugyanebben a raktárban lévő eszközök tiltása
  $alreadyHere = [];
  foreach ($items as $it) {
    if ((int)($it['current_warehouse_id'] ?? 0) === $warehouseId) {
      $alreadyHere[] = (string)($it['name'] ?? ('#'.(int)$it['id']));
    }
  }
  if ($alreadyHere) {
    $label = implode(', ', array_slice($alreadyHere, 0, 5));
    if (count($alreadyHere) > 5) $label .= ' ...';
    flash_set('err', 'A kijelölt eszközök között van olyan, amely már ebben a raktárban van: '.$label);
    header('Location: warehouse_intake.php');
    exit;
  }

  $cols = [];
  foreach ($pdo->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cols[(string)$c['Field']] = true;
  }
  if (isset($cols['status'])) {
    $stPend = $pdo->prepare("SELECT asset_id FROM asset_assignments WHERE status='pending' AND asset_id IN ($in)");
    $stPend->execute($assetIds);
    if ($stPend->fetchColumn()) {
      flash_set('err', 'Van olyan kiválasztott eszköz, amelyre már van függő belső átadás.');
      header('Location: warehouse_intake.php');
      exit;
    }
  }

  $performedBy = (string)(($u['name'] ?? '') ?: ($u['full_name'] ?? '') ?: ($u['email'] ?? '') ?: ('#'.(string)($u['id'] ?? '')));
  $assetsForPdf = [];
  foreach ($items as $it) {
    $assetsForPdf[] = [
      'id' => (int)$it['id'],
      'name' => (string)$it['name'] . ' — ' . intake_holder_label($it, $empMap, $warehouseMap),
      'inventory' => (string)($it['qr_value'] ?? ''),
      'serial' => (string)($it['sku'] ?? ''),
    ];
  }

  $pdfWeb = null;
  $emailOk = null;

  $pdo->beginTransaction();
  try {
    $upd = $pdo->prepare("UPDATE assets SET current_employee_id=NULL, current_warehouse_id=? WHERE id=?");
    foreach ($items as $it) {
      $upd->execute([$warehouseId, (int)$it['id']]);
    }

    if (function_exists('generate_warehouse_intake_pdf_html')) {
      $pdfWeb = generate_warehouse_intake_pdf_html([
        'doc_date' => date('Y-m-d H:i:s'),
        'performed_by' => $performedBy,
        'warehouse' => (string)$warehouse['name'],
        'note' => $note,
        'assets' => $assetsForPdf,
      ]);
    }

    // Opcionális mentés history táblába, ha létezik
    try {
      $pdo->query("SELECT 1 FROM warehouse_intake_documents LIMIT 1");
      $ins = $pdo->prepare("INSERT INTO warehouse_intake_documents (asset_id, warehouse_id, created_by_user_id, doc_date, source_label, note, recipient_email, pdf_path, created_at)
                            VALUES (?,?,?,?,?,?,?,?,NOW())");
      foreach ($items as $it) {
        $sourceLabel = intake_holder_label($it, $empMap, $warehouseMap);
        $ins->execute([
          (int)$it['id'],
          $warehouseId,
          (int)($u['id'] ?? 0),
          date('Y-m-d H:i:s'),
          $sourceLabel,
          $note !== '' ? $note : null,
          $recipientEmail !== '' ? $recipientEmail : null,
          $pdfWeb,
        ]);
      }
    } catch (Throwable $e) {
      // ha nincs még history tábla, nem állunk meg
    }

    $pdo->commit();

    if ($recipientEmail !== '' && $pdfWeb !== null && function_exists('send_mail_with_attachment')) {
      $cfg = require __DIR__ . '/../app/config.php';
      $from = (string)($cfg['mail_from'] ?? 'no-reply@localhost');
      $bcc = (string)($cfg['mail_bcc'] ?? '');
      $pdfAbs = __DIR__ . '/..' . $pdfWeb;

      $assetItemsHtml = '';
      foreach ($assetsForPdf as $a) {
        $line = htmlspecialchars((string)$a['name'], ENT_QUOTES, 'UTF-8');
        if (!empty($a['inventory'])) $line .= ' | Leltár/QR: ' . htmlspecialchars((string)$a['inventory'], ENT_QUOTES, 'UTF-8');
        if (!empty($a['serial'])) $line .= ' | SKU/SN: ' . htmlspecialchars((string)$a['serial'], ENT_QUOTES, 'UTF-8');
        $assetItemsHtml .= '<li style="margin:0 0 6px 0;">' . $line . '</li>';
      }

      $subject = 'Perfect-Phone – Raktárba vételi bizonylat';
      $body = '<!doctype html><html lang="hu"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">'
            . '<div style="max-width:700px;margin:0 auto;padding:24px;">'
            . '<div style="background:#ffffff;border:1px solid #ddd;border-radius:10px;overflow:hidden;">'
            . '<div style="padding:24px 24px 12px 24px;">'
            . '<h2 style="margin:0 0 16px 0;font-size:22px;">Raktárba vételi értesítő</h2>'
            . '<p style="margin:0 0 16px 0;">Csatoltan küldjük a raktárba vételi bizonylatot PDF formátumban.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px 0;">'
            . '<tr><td style="padding:4px 0;width:180px;"><strong>Időpont:</strong></td><td style="padding:4px 0;">' . htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:4px 0;"><strong>Rögzítette:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($performedBy, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:4px 0;"><strong>Raktár:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)$warehouse['name'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
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
      if ($emailOk) flash_set('ok', 'Raktárba vétel rögzítve. PDF elkészült és email elküldve.');
      else flash_set('warn', 'Raktárba vétel rögzítve, PDF elkészült, de az email küldés nem sikerült.');
    } elseif ($pdfWeb !== null) {
      flash_set('ok', 'Raktárba vétel rögzítve. PDF: ' . $pdfWeb);
    } else {
      flash_set('ok', 'Raktárba vétel rögzítve.');
    }
  } catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('err', 'Hiba raktárba vételkor: ' . $e->getMessage());
  }

  header('Location: warehouse_intake.php');
  exit;
}

$where = [
  "a.is_deleted=0",
  "NOT EXISTS (SELECT 1 FROM asset_external_assignments x WHERE x.asset_id=a.id AND x.status='active')"
];
$params = [];

if ($q !== '') {
  $where[] = "(a.name LIKE ? OR a.sku LIKE ? OR a.qr_value LIKE ?)";
  $like = '%'.$q.'%';
  $params = array_merge($params, [$like, $like, $like]);
}
if ($holderEmp > 0) {
  $where[] = "a.current_employee_id=?";
  $params[] = $holderEmp;
}
if ($holderWh > 0) {
  $where[] = "a.current_warehouse_id=?";
  $params[] = $holderWh;
}

$whereSql = implode(' AND ', $where);

$stCount = $pdo->prepare("SELECT COUNT(*) FROM assets a WHERE $whereSql");
$stCount->execute($params);
$totalRows = (int)($stCount->fetchColumn() ?: 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT a.*,
          (SELECT p.file_path FROM asset_photos p WHERE p.asset_id=a.id ORDER BY p.is_primary DESC, p.id DESC LIMIT 1) AS photo_path,
          (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
             FROM asset_category ac JOIN categories c ON c.id=ac.category_id AND c.is_deleted=0
            WHERE ac.asset_id=a.id) AS cat_names
        FROM assets a
        WHERE $whereSql
        ORDER BY a.name ASC, a.id DESC
        LIMIT $perPage OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function photo_public_url_intake(string $path): string {
  $p = trim($path);
  if ($p === '') return '';
  if (preg_match('~^https?://~i', $p)) return $p;
  $p = ltrim($p, '/');
  if (substr($p, 0, 8) === 'storage/') return '/'.$p;
  return '/storage/'.$p;
}

require __DIR__.'/_header.php';
?>
<div class="container" style="max-width:1100px">
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label">Keresés</label>
          <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Név, cikkszám, QR">
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Dolgozó</label>
          <select class="form-select" name="employee_id">
            <option value="0">— összes —</option>
            <?php foreach ($hrEmployees as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $holderEmp ? 'selected' : '' ?>><?= e((string)$e['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Raktár</label>
          <select class="form-select" name="warehouse_filter_id">
            <option value="0">— összes —</option>
            <?php foreach ($allWarehouses as $w): ?>
              <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $holderWh ? 'selected' : '' ?>><?= e((string)$w['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-2 d-grid">
          <button class="btn btn-primary" type="submit">Szűrés</button>
        </div>
      </form>
    </div>
  </div>

  <form method="post" id="bulkIntakeForm" class="card shadow-sm mb-3">
    <div class="card-body">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="bulk_intake">
      <input type="hidden" name="asset_ids_csv" id="asset_ids_csv" value="">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label">Cél raktár</label>
          <select class="form-select" name="warehouse_id" id="target_warehouse_id" required>
            <option value="">— válassz raktárat —</option>
            <?php foreach ($accessibleWarehouses as $w): ?>
              <option value="<?= (int)$w['id'] ?>"><?= e((string)$w['name']) ?><?= !empty($w['location']) ? ' — '.e((string)$w['location']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">Email cím (opcionális)</label>
          <input type="email" class="form-control" name="recipient_email" placeholder="pl. raktar@ceg.hu">
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">Megjegyzés (opcionális)</label>
          <input class="form-control" name="note" placeholder="pl. gyors raktárba vétel / leltár">
        </div>
        <div class="col-12 d-flex justify-content-between align-items-center mt-2">
          <div class="form-text">A kijelölt eszközök a kiválasztott raktárhoz kerülnek.</div>
          <button class="btn btn-success" type="submit">Raktárba vétel</button>
        </div>
      </div>
    </div>
  </form>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="selectAll">
      <label class="form-check-label" for="selectAll">Oldal összes kijelölése</label>
    </div>
    <div class="text-secondary small"><?= $totalRows ?> db</div>
  </div>

  <?php if (!$rows): ?>
    <div class="alert alert-info">Nincs találat a szűrésre.</div>
  <?php else: ?>
    <div class="d-grid gap-2">
      <?php foreach ($rows as $r): ?>
        <?php
          $aid = (int)$r['id'];
          $photo = (string)($r['photo_path'] ?? '');
          $photoUrl = $photo !== '' ? photo_public_url_intake($photo) : '';
          $cats = (string)($r['cat_names'] ?? '');
          $sku = (string)($r['sku'] ?? '');
          $qr = (string)($r['qr_value'] ?? '');
          $ownerText = intake_holder_label($r, $empMap, $warehouseMap);
        ?>
        <div class="card shadow-sm">
          <div class="card-body d-flex gap-3">
            <div style="width:28px;flex:0 0 28px">
              <div class="form-check mt-1">
                <input class="form-check-input assetCb" type="checkbox" value="<?= $aid ?>" id="a<?= $aid ?>" data-current-warehouse-id="<?= (int)($r['current_warehouse_id'] ?? 0) ?>">
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
              <div class="text-secondary small">#<?= $aid ?> · <strong>Tulajdonos:</strong> <?= e($ownerText) ?></div>
              <?php if ($sku !== ''): ?><div class="small mt-2"><strong>Cikkszám:</strong> <?= e($sku) ?></div><?php endif; ?>
              <?php if ($qr !== ''): ?><div class="small"><strong>QR:</strong> <?= e($qr) ?></div><?php endif; ?>
              <?php if ($cats !== ''): ?><div class="small"><strong>Kategória:</strong> <?= e($cats) ?></div><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $pageNo ? 'active' : '' ?>">
              <a class="page-link" href="?<?= e(http_build_query(['q'=>$q,'employee_id'=>$holderEmp,'warehouse_filter_id'=>$holderWh,'page'=>$p])) ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  const selAll = document.getElementById('selectAll');
  const warehouseSel = document.getElementById('target_warehouse_id');
  const cbs = () => Array.from(document.querySelectorAll('.assetCb'));

  function refreshAlreadyHereHint() {
    const targetId = parseInt((warehouseSel && warehouseSel.value) || '0', 10);
    cbs().forEach(cb => {
      const currentWh = parseInt(cb.getAttribute('data-current-warehouse-id') || '0', 10);
      cb.disabled = (targetId > 0 && currentWh === targetId);
      if (cb.disabled) cb.checked = false;
    });
  }

  if (selAll) {
    selAll.addEventListener('change', function(){
      cbs().forEach(cb => { if (!cb.disabled) cb.checked = selAll.checked; });
    });
  }
  if (warehouseSel) {
    warehouseSel.addEventListener('change', refreshAlreadyHereHint);
    refreshAlreadyHereHint();
  }

  const form = document.getElementById('bulkIntakeForm');
  const csv = document.getElementById('asset_ids_csv');
  if (form && csv) {
    form.addEventListener('submit', function(e){
      const ids = cbs().filter(cb => cb.checked && !cb.disabled).map(cb => cb.value);
      if (!ids.length) {
        e.preventDefault();
        alert('Jelölj ki legalább 1 eszközt.');
        return;
      }
      if (!confirm('Biztosan a kiválasztott raktárhoz akarod rendelni a kijelölt eszközöket?')) {
        e.preventDefault();
        return;
      }
      csv.value = ids.join(',');
    });
  }
})();
</script>
<?php require __DIR__.'/_footer.php'; ?>

<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');

$pdo = db();
$hr  = db_hr();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('assets.php'); }

$st = $pdo->prepare("SELECT * FROM assets WHERE id=? AND is_deleted=0 LIMIT 1");
$st->execute([$id]);
$a = $st->fetch();
if (!$a) { http_response_code(404); echo "Nincs ilyen eszköz."; exit; }

require_once __DIR__.'/../app/categories.php';
$cats = fetch_categories_tree($pdo);

$st = $pdo->prepare("SELECT category_id FROM asset_category WHERE asset_id=?");
$st->execute([$id]);
$selected = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

$st = $pdo->prepare("SELECT * FROM asset_photos WHERE asset_id=? ORDER BY is_primary DESC, id DESC");
$st->execute([$id]);
$photos = $st->fetchAll();

$st = $hr->query("SELECT id, full_name, is_active FROM employees ORDER BY full_name");
$employees = $st->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();

  $name = trim((string)($_POST['name'] ?? ''));
  if ($name === '') {
    flash_set('err', 'Megnevezés kötelező.');
    redirect('asset_edit.php?id='.$id);
  }
  $sku = trim((string)($_POST['sku'] ?? '')) ?: null;
  $qr  = trim((string)($_POST['qr_value'] ?? '')) ?: null;
  $val = trim((string)($_POST['value_amount'] ?? ''));
  $val = ($val === '') ? null : (float)str_replace(',', '.', $val);
  $cur = trim((string)($_POST['value_currency'] ?? 'HUF')) ?: 'HUF';
  $note = trim((string)($_POST['note'] ?? '')) ?: null;

  // categories
  $cat_ids = $_POST['categories'] ?? [];
  if (!is_array($cat_ids)) $cat_ids = [];

  // assignment
  $newEmpRaw = trim((string)($_POST['current_employee_id'] ?? ''));
  $newEmpId = ($newEmpRaw === '') ? null : (int)$newEmpRaw;
  $oldEmpId = $a['current_employee_id'] ?? null;
  $oldEmpId = ($oldEmpId === null) ? null : (int)$oldEmpId;

  $inspectionRequired = isset($_POST['inspection_required']) ? 1 : 0;

  $pdo->beginTransaction();
  try {
    $pdo->prepare("UPDATE assets SET name=?, sku=?, qr_value=?, value_amount=?, value_currency=?, note=?, inspection_required=? WHERE id=?")
        ->execute([$name,$sku,$qr,$val,$cur,$note,$inspectionRequired,$id]);

    $pdo->prepare("DELETE FROM asset_category WHERE asset_id=?")->execute([$id]);
    if ($cat_ids) {
      $ins = $pdo->prepare("INSERT IGNORE INTO asset_category (asset_id, category_id) VALUES (?,?)");
      foreach ($cat_ids as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) $ins->execute([$id,$cid]);
      }
    }

    if ($newEmpId !== $oldEmpId) {
      $noteAssign = trim((string)($_POST['assign_note'] ?? '')) ?: null;
      $pdo->prepare("INSERT INTO asset_assignments (asset_id, from_employee_id, to_employee_id, assigned_by_user_id, note) VALUES (?,?,?,?,?)")
          ->execute([$id, $oldEmpId, $newEmpId, (int)(current_user()['id'] ?? 0), $noteAssign]);
      $pdo->prepare("UPDATE assets SET current_employee_id=? WHERE id=?")->execute([$newEmpId, $id]);
    }

    $pdo->commit();
    flash_set('ok', 'Mentve.');
  } catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('err', 'Hiba: '.$e->getMessage());
  }

  redirect('asset_edit.php?id='.$id);
}

// Felülvizsgálatok lekérdezése
$inspections = $pdo->prepare("SELECT * FROM asset_inspections WHERE asset_id=? ORDER BY inspection_date DESC, id DESC");
$inspections->execute([$id]);
$inspections = $inspections->fetchAll();

$inspectionDocMap = [];
if ($inspections) {
    $iids = array_map(static fn($r) => (int)$r['id'], $inspections);
    $ph   = implode(',', array_fill(0, count($iids), '?'));
    $docs = $pdo->prepare("SELECT * FROM asset_inspection_docs WHERE inspection_id IN ($ph) ORDER BY id ASC");
    $docs->execute($iids);
    foreach ($docs->fetchAll() as $d) {
        $inspectionDocMap[(int)$d['inspection_id']][] = $d;
    }
}

$latestInspection = $inspections[0] ?? null;
$latestNextDate   = $latestInspection ? ($latestInspection['next_date'] ?? null) : null;

function inspection_status_badge(?string $nextDate): string {
    if (!$nextDate) return '<span class="badge bg-secondary">Nincs határidő</span>';
    $today = new DateTime('today');
    $nd    = new DateTime($nextDate);
    $diff  = (int)$today->diff($nd)->days * ($nd >= $today ? 1 : -1);
    if ($diff < 0) return '<span class="badge bg-danger">Lejárt ('.htmlspecialchars($nextDate, ENT_QUOTES, 'UTF-8').')</span>';
    if ($diff <= 30) return '<span class="badge bg-warning text-dark">Közeledik ('.htmlspecialchars($nextDate, ENT_QUOTES, 'UTF-8').')</span>';
    return '<span class="badge bg-success">Rendben ('.htmlspecialchars($nextDate, ENT_QUOTES, 'UTF-8').')</span>';
}

$intervalUnitLabels = ['day' => 'nap', 'month' => 'hónap', 'year' => 'év'];

$title = 'Eszköz szerkesztése';
$page  = 'Eszköz szerkesztése';
require __DIR__.'/_header.php';

$currentEmpId = $a['current_employee_id'] ?? null;
$currentEmpId = ($currentEmpId === null) ? null : (int)$currentEmpId;
?>

<div class="container" style="max-width:1000px">
  <?php if ($m=flash_get('ok')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
  <?php if ($m=flash_get('err')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0"><?= e($a['name']) ?></h4>
      <div class="text-secondary small">#<?= (int)$a['id'] ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="assets.php">Lista</a>
      <a class="btn btn-outline-primary" href="asset_history.php?id=<?= (int)$a['id'] ?>">Történet</a>
      <form method="post" action="asset_delete.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd (archiválod) ezt az eszközt?');">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <button class="btn btn-outline-danger" type="submit">Törlés</button>
      </form>
    </div>
  </div>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="card mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Megnevezés *</label>
            <input class="form-control" name="name" required value="<?= e($a['name'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Cikkszám</label>
            <input class="form-control" name="sku" value="<?= e($a['sku'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">QR (szöveg)</label>
            <input class="form-control" name="qr_value" value="<?= e($a['qr_value'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Érték</label>
            <input class="form-control" name="value_amount" inputmode="decimal" value="<?= e((string)($a['value_amount'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Pénznem</label>
            <input class="form-control" name="value_currency" value="<?= e((string)($a['value_currency'] ?? 'HUF')) ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Kategóriák</label>
            <select class="form-select" name="categories[]" multiple size="7">
              <?php foreach ($cats as $c): $cid=(int)$c['id']; ?>
                <option value="<?= $cid ?>" <?= in_array($cid,$selected,true)?'selected':'' ?>>
                  <?= str_repeat('— ', (int)$c['_depth']) . e($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Több kijelölés: Ctrl / Cmd</div>
          </div>

          <div class="col-12">
            <label class="form-label">Birtokos (HR)</label>
            <select class="form-select" name="current_employee_id">
              <option value="">— nincs hozzárendelve —</option>
              <?php foreach ($employees as $emp): $eid=(int)$emp['id']; ?>
                <option value="<?= $eid ?>" <?= ($currentEmpId===$eid)?'selected':'' ?>>
                  <?= e($emp['full_name']) ?><?= ((int)$emp['is_active']===1)?'':' (inaktív)' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Birtokos módosításnál esemény kerül az eszköz történetbe.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Átadás megjegyzés (opcionális)</label>
            <input class="form-control" name="assign_note" placeholder="pl. sérülés, tartozékok, megjegyzés...">
          </div>

          <div class="col-12">
            <label class="form-label">Megjegyzés</label>
            <textarea class="form-control" name="note" rows="3"><?= e($a['note'] ?? '') ?></textarea>
          </div>

          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="inspection_required" id="inspection_required" value="1"
                <?= ((int)($a['inspection_required'] ?? 0) === 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="inspection_required">Felülvizsgálat / kalibráció szükséges</label>
            </div>
          </div>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <button class="btn btn-success" type="submit">Mentés</button>
        <a class="btn btn-outline-secondary" href="assets.php">Mégse</a>
      </div>
    </div>
  </form>

  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="fw-semibold">Fotók</div>
      <form method="post" action="asset_photo_upload.php" enctype="multipart/form-data" class="d-flex gap-2">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="asset_id" value="<?= (int)$a['id'] ?>">
        <input class="form-control form-control-sm" type="file" name="photo" accept="image/*" required>
        <button class="btn btn-sm btn-primary" type="submit">Feltölt</button>
      </form>
    </div>
    <div class="card-body">
      <?php if (!$photos): ?>
        <div class="text-secondary">Nincs feltöltött fotó.</div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($photos as $p): ?>
            <div class="col-6 col-md-3">
              <div class="border rounded p-2 h-100">
                <img src="<?= e($p['file_path']) ?>" class="img-fluid rounded mb-2" alt="">
                <div class="d-flex gap-2">
                  <?php if ((int)$p['is_primary'] !== 1): ?>
                    <form method="post" action="asset_photo_primary.php">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="asset_id" value="<?= (int)$a['id'] ?>">
                      <button class="btn btn-sm btn-outline-primary" type="submit">Fő</button>
                    </form>
                  <?php else: ?>
                    <span class="badge bg-success align-self-center">Fő</span>
                  <?php endif; ?>

                  <form method="post" action="asset_photo_delete.php" onsubmit="return confirm('Biztosan törlöd a képet?');">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="asset_id" value="<?= (int)$a['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Töröl</button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ((int)($a['inspection_required'] ?? 0) === 1): ?>
  <div class="card mb-3" id="inspection">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <span class="fw-semibold">Felülvizsgálat / Kalibráció</span>
        <?php if ($latestNextDate): ?>
          <span class="ms-2"><?= inspection_status_badge($latestNextDate) ?></span>
        <?php elseif (!$latestInspection): ?>
          <span class="badge bg-secondary ms-2">Még nincs bejegyzés</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">

      <form method="post" action="asset_inspection_save.php" enctype="multipart/form-data" class="border rounded p-3 mb-4 bg-light-subtle">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="asset_id" value="<?= $id ?>">
        <div class="fw-semibold mb-2 small text-secondary text-uppercase">Új bejegyzés rögzítése</div>
        <div class="row g-2">
          <div class="col-sm-6 col-md-3">
            <label class="form-label small">Elvégzés dátuma</label>
            <input type="date" class="form-control form-control-sm" name="inspection_date" value="<?= e(date('Y-m-d')) ?>" required>
          </div>
          <div class="col-sm-6 col-md-3">
            <label class="form-label small">Következő időköze</label>
            <div class="input-group input-group-sm">
              <input type="number" class="form-control" name="interval_value" min="1" max="999" placeholder="pl. 12">
              <select class="form-select" name="interval_unit" style="max-width:90px;">
                <option value="day">nap</option>
                <option value="month" selected>hónap</option>
                <option value="year">év</option>
              </select>
            </div>
            <div class="form-text">Ha üres, kézi dátum megadható.</div>
          </div>
          <div class="col-sm-6 col-md-3">
            <label class="form-label small">Következő dátuma (kézi)</label>
            <input type="date" class="form-control form-control-sm" name="next_date">
            <div class="form-text">Csak ha nincs időköz megadva.</div>
          </div>
          <div class="col-sm-6 col-md-3">
            <label class="form-label small">Dokumentum (opcionális)</label>
            <input type="file" class="form-control form-control-sm" name="doc" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf">
          </div>
          <div class="col-12">
            <label class="form-label small">Megjegyzés</label>
            <textarea class="form-control form-control-sm" name="note" rows="2" placeholder="pl. tanúsítvány száma, elvégző neve..."></textarea>
          </div>
          <div class="col-12">
            <button class="btn btn-sm btn-success" type="submit">Rögzítés</button>
          </div>
        </div>
      </form>

      <?php if ($inspections): ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Elvégzés</th>
                <th>Következő</th>
                <th>Időköz</th>
                <th>Megjegyzés</th>
                <th>Dokumentumok</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inspections as $insp): ?>
                <tr>
                  <td class="text-nowrap"><?= e((string)$insp['inspection_date']) ?></td>
                  <td class="text-nowrap"><?= inspection_status_badge($insp['next_date'] ?? null) ?></td>
                  <td class="text-nowrap small text-secondary">
                    <?php if ($insp['interval_value'] && $insp['interval_unit']): ?>
                      <?= (int)$insp['interval_value'] ?> <?= e($intervalUnitLabels[$insp['interval_unit']] ?? $insp['interval_unit']) ?>
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td class="small"><?= e((string)($insp['note'] ?? '—')) ?></td>
                  <td>
                    <?php $idocs = $inspectionDocMap[(int)$insp['id']] ?? []; ?>
                    <?php foreach ($idocs as $doc): ?>
                      <?php $isPdf = strtolower(pathinfo((string)$doc['file_path'], PATHINFO_EXTENSION)) === 'pdf'; ?>
                      <div class="d-flex align-items-center gap-1 mb-1">
                        <?php if ($isPdf): ?>
                          <a href="<?= e((string)$doc['file_path']) ?>" target="_blank" class="btn btn-xs btn-outline-danger btn-sm py-0 px-1" style="font-size:.75rem;">PDF</a>
                        <?php else: ?>
                          <a href="<?= e((string)$doc['file_path']) ?>" target="_blank">
                            <img src="<?= e((string)$doc['file_path']) ?>" alt="" style="max-height:40px;max-width:60px;border:1px solid #dee2e6;border-radius:3px;">
                          </a>
                        <?php endif; ?>
                        <form method="post" action="asset_inspection_doc_delete.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd?');">
                          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                          <input type="hidden" name="asset_id" value="<?= $id ?>">
                          <button class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:.75rem;" type="submit">×</button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                    <form method="post" action="asset_inspection_doc_upload.php" enctype="multipart/form-data" class="d-flex gap-1 mt-1">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="inspection_id" value="<?= (int)$insp['id'] ?>">
                      <input type="hidden" name="asset_id" value="<?= $id ?>">
                      <input type="file" class="form-control form-control-sm" name="doc" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf" required style="font-size:.75rem;max-width:160px;">
                      <button class="btn btn-sm btn-outline-secondary py-0" style="font-size:.75rem;" type="submit">+</button>
                    </form>
                  </td>
                  <td></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-secondary small">Még nincs rögzített felülvizsgálat.</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php require __DIR__.'/_footer.php'; ?>

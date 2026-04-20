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

  $pdo->beginTransaction();
  try {
    $pdo->prepare("UPDATE assets SET name=?, sku=?, qr_value=?, value_amount=?, value_currency=?, note=? WHERE id=?")
        ->execute([$name,$sku,$qr,$val,$cur,$note,$id]);

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
</div>

<?php require __DIR__.'/_footer.php'; ?>

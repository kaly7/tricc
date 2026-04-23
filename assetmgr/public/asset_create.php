<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');
$pdo = db();
require_once __DIR__.'/../app/categories.php';
$cats = fetch_categories_tree($pdo);

$title='Új eszköz';
$page='Új eszköz';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $name = trim((string)($_POST['name'] ?? ''));
  if ($name==='') { flash_set('err','Megnevezés kötelező.'); header('Location: asset_create.php'); exit; }
  $sku = trim((string)($_POST['sku'] ?? '')) ?: null;
  $qr = trim((string)($_POST['qr_value'] ?? '')) ?: null;
  $val = trim((string)($_POST['value_amount'] ?? ''));
  $val = ($val==='') ? null : (float)str_replace(',', '.', $val);
  $cur = trim((string)($_POST['value_currency'] ?? 'HUF')) ?: 'HUF';
  $note = trim((string)($_POST['note'] ?? '')) ?: null;

  $inspReq = isset($_POST['inspection_required']) ? 1 : 0;
  $pdo->prepare("INSERT INTO assets (name,sku,qr_value,value_amount,value_currency,note,inspection_required) VALUES (?,?,?,?,?,?,?)")
      ->execute([$name,$sku,$qr,$val,$cur,$note,$inspReq]);
  $id = (int)$pdo->lastInsertId();

  $cat_ids = $_POST['categories'] ?? [];
  if (is_array($cat_ids) && $cat_ids) {
    $st = $pdo->prepare("INSERT IGNORE INTO asset_category (asset_id, category_id) VALUES (?,?)");
    foreach ($cat_ids as $cid) {
      $cid=(int)$cid; if ($cid>0) $st->execute([$id,$cid]);
    }
  }

  flash_set('ok','Eszköz létrehozva.');
  header('Location: asset_edit.php?id='.$id);
  exit;
}

require __DIR__.'/_header.php';
?>
<div class="container" style="max-width:900px">
  <?php if ($msg=flash_get('err')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <div class="card mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Megnevezés *</label>
            <input class="form-control" name="name" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Cikkszám</label>
            <input class="form-control" name="sku">
          </div>
          <div class="col-md-6">
            <label class="form-label">QR (szöveg)</label>
            <input class="form-control" name="qr_value">
          </div>
          <div class="col-md-3">
            <label class="form-label">Érték</label>
            <input class="form-control" name="value_amount" inputmode="decimal">
          </div>
          <div class="col-md-3">
            <label class="form-label">Pénznem</label>
            <input class="form-control" name="value_currency" value="HUF">
          </div>
          <div class="col-12">
            <label class="form-label">Kategóriák</label>
            <select class="form-select" name="categories[]" multiple size="6">
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= str_repeat('— ', (int)$c['_depth']) . e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Több kijelölés: Ctrl / Cmd</div>
          </div>
          <div class="col-12">
            <label class="form-label">Megjegyzés</label>
            <textarea class="form-control" name="note" rows="3"></textarea>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="inspection_required" id="inspection_required" value="1">
              <label class="form-check-label" for="inspection_required">Felülvizsgálat / kalibráció szükséges</label>
            </div>
          </div>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <button class="btn btn-success">Mentés</button>
        <a class="btn btn-outline-secondary" href="assets.php">Mégse</a>
      </div>
    </div>
  </form>
</div>
<?php require __DIR__.'/_footer.php'; ?>

<?php
require __DIR__.'/../app/auth.php';
require_login();
require_role('editor');
$pdo = db();

$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare("
  SELECT ci.*, m.name AS manufacturer_name
  FROM catalog_items ci
  LEFT JOIN manufacturers m ON m.id=ci.manufacturer_id
  WHERE ci.id=?
");
$st->execute([$id]);
$item = $st->fetch();
if(!$item){ http_response_code(404); exit('Nincs ilyen eszköztípus'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST') {

  $category = (string)($_POST['category'] ?? $item['category']);
  $manufacturer_id = (int)($_POST['manufacturer_id'] ?? $item['manufacturer_id']);
  $model = trim((string)($_POST['model'] ?? ''));
  $doc_url = trim((string)($_POST['doc_url'] ?? '')); // NOT NULL in DB -> must be '' at minimum
  $notes = (string)($_POST['notes'] ?? '');
  $is_archived = isset($_POST['is_archived']) ? 1 : 0;

  $default_admin_url  = trim((string)($_POST['default_admin_url'] ?? ''));
  $default_reboot_url = trim((string)($_POST['default_reboot_url'] ?? ''));

  if ($manufacturer_id <= 0) $errors[] = 'Gyártó kötelező.';
  if ($model === '') $errors[] = 'Típus / modell kötelező.';

  // doc_url can be empty string, but never NULL
  if ($doc_url === null) $doc_url = '';

  if (!$errors) {
    $up = $pdo->prepare("
      UPDATE catalog_items SET
        category=:category,
        manufacturer_id=:manufacturer_id,
        model=:model,
        default_admin_url=:default_admin_url,
        default_reboot_url=:default_reboot_url,
        doc_url=:doc_url,
        notes=:notes,
        is_archived=:is_archived
      WHERE id=:id
    ");
    $up->execute([
      ':category' => $category,
      ':manufacturer_id' => $manufacturer_id,
      ':model' => $model,
      ':default_admin_url' => ($default_admin_url === '' ? null : $default_admin_url),
      ':default_reboot_url' => ($default_reboot_url === '' ? null : $default_reboot_url),
      ':doc_url' => $doc_url, // empty string OK
      ':notes' => ($notes === '' ? null : $notes),
      ':is_archived' => $is_archived,
      ':id' => $id,
    ]);

    header('Location: '.base_url('catalog_items.php?msg=updated'));
    exit;
  }

  // reflect posted values on form
  $item['category'] = $category;
  $item['manufacturer_id'] = $manufacturer_id;
  $item['model'] = $model;
  $item['default_admin_url'] = $default_admin_url;
  $item['default_reboot_url'] = $default_reboot_url;
  $item['doc_url'] = $doc_url;
  $item['notes'] = $notes;
  $item['is_archived'] = $is_archived;
}

$title = 'Eszköztípus szerkesztése';
$page  = 'Eszköz-típusok';
require __DIR__.'/_header.php';

$mans = $pdo->query("SELECT id,name FROM manufacturers WHERE is_archived=0 ORDER BY name")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h3 mb-1">Eszköztípus szerkesztése</h1>
    <div class="text-muted small">#<?= (int)$item['id'] ?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-secondary" href="<?= e(base_url('catalog_items.php')) ?>">Vissza</a>
    <a class="btn btn-outline-secondary" href="<?= e(base_url('catalog_item_files.php?id='.(int)$item['id'])) ?>">Dokumentumok</a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <?php foreach($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post">
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <label class="form-label">Kategória</label>
          <select class="form-select" name="category">
            <option value="pbx" <?= ($item['category']==='pbx'?'selected':'') ?>>Központ</option>
            <option value="endpoint" <?= ($item['category']==='endpoint'?'selected':'') ?>>Végberendezés</option>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">Gyártó</label>
          <select class="form-select" name="manufacturer_id" required>
            <option value="">— válassz —</option>
            <?php foreach($mans as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= ((int)$item['manufacturer_id']===(int)$m['id']?'selected':'') ?>>
                <?= e($m['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label">Típus / modell</label>
          <input class="form-control" name="model" value="<?= e((string)$item['model']) ?>" required>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Alapértelmezett Admin URL (opcionális)</label>
          <input class="form-control" name="default_admin_url" value="<?= e((string)($item['default_admin_url'] ?? '')) ?>" placeholder="pl. admin vagy #/advanced">
          <div class="form-text">Ez lesz használva, ha a végberendezésnél nincs felülírva.</div>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Alapértelmezett Reboot URL (opcionális)</label>
          <input class="form-control" name="default_reboot_url" value="<?= e((string)($item['default_reboot_url'] ?? '')) ?>" placeholder="pl. reboot vagy action/restart">
          <div class="form-text">Ez lesz használva, ha a végberendezésnél nincs felülírva.</div>
        </div>

        <div class="col-12">
          <label class="form-label">Dokumentáció URL</label>
          <input class="form-control" name="doc_url" value="<?= e((string)($item['doc_url'] ?? '')) ?>" placeholder="https://..." >
          <div class="form-text">Nem kötelező, de a mező nem lehet NULL (üresen is menthető).</div>
        </div>

        <div class="col-12">
          <label class="form-label">Megjegyzés</label>
          <textarea class="form-control" rows="4" name="notes"><?= e((string)($item['notes'] ?? '')) ?></textarea>
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_archived" id="arch" <?= ((int)$item['is_archived']===1?'checked':'') ?>>
            <label class="form-check-label" for="arch">Archivált</label>
          </div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Mentés</button>
          <a class="btn btn-outline-secondary" href="<?= e(base_url('catalog_items.php')) ?>">Mégse</a>
        </div>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__.'/_footer.php'; ?>

<?php
require __DIR__.'/../app/auth.php';
require_role('editor');
$pdo = db();

$mans = $pdo->query("SELECT id,name,is_archived FROM manufacturers ORDER BY is_archived, name")->fetchAll();

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {

  verify_csrf();
  $category = (string)($_POST['category'] ?? '');
  $manufacturer_id = (int)($_POST['manufacturer_id'] ?? 0);
  $model = trim((string)($_POST['model'] ?? ''));
  $doc_url = trim((string)($_POST['doc_url'] ?? ''));
  $default_admin_url = trim((string)($_POST['default_admin_url'] ?? ''));
  $default_reboot_url = trim((string)($_POST['default_reboot_url'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));

  if (!in_array($category, ['pbx','endpoint'], true)) $err = 'Kategória kötelező.';
  elseif ($manufacturer_id<=0) $err = 'Gyártó kötelező.';
  elseif ($model==='') $err = 'Típus kötelező.';
  else {
    $st = $pdo->prepare("INSERT INTO catalog_items (category, manufacturer_id, model, doc_url, default_admin_url, default_reboot_url, notes, is_archived) VALUES (?,?,?,?,?,?,?,0)");
    $st->execute([$category, $manufacturer_id, $model, $doc_url, $default_admin_url, $default_reboot_url, $notes]);
    $newId = (int)$pdo->lastInsertId();

    // optional file upload
    $okFiles = 0;
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
      $uploadDir = __DIR__ . '/../storage/catalog_files';
      if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

      $names = $_FILES['files']['name'];
      $tmp   = $_FILES['files']['tmp_name'];
      $size  = $_FILES['files']['size'];
      $type  = $_FILES['files']['type'];
      $errc  = $_FILES['files']['error'];

      $count = count($names);
      for ($i=0; $i<$count; $i++) {
        if ($errc[$i] !== UPLOAD_ERR_OK) continue;
        if (!is_uploaded_file($tmp[$i])) continue;
        $orig = (string)$names[$i];
        $stored = bin2hex(random_bytes(16)) . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $orig);
        $dest = $uploadDir . '/' . $stored;
        if (@move_uploaded_file($tmp[$i], $dest)) {
          $ins = $pdo->prepare("INSERT INTO catalog_files (catalog_item_id, original_name, stored_name, mime, size_bytes, uploaded_by, is_archived) VALUES (?,?,?,?,?,?,0)");
          $ins->execute([$newId, $orig, $stored, (string)$type[$i], (int)$size[$i], null]);
          $okFiles++;
        }
      }
    }

    if ($okFiles > 0) {
      flash_set('ok', 'Eszköz-típus létrehozva. Feltöltött fájlok: ' . $okFiles);
      redirect('catalog_item_edit.php?id=' . $newId);
    }

    flash_set('ok', 'Eszköz-típus létrehozva.');
    redirect('catalog_items.php');
  }
}

$title='Új eszköz-típus';
$page='Eszköz-típusok';
require __DIR__.'/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Új eszköz-típus</h1>
  <a class="btn btn-outline-secondary" href="<?= e(base_url('catalog_items.php')) ?>">Vissza</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="card p-3">
  <form method="post" enctype="multipart/form-data" class="row g-3">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="col-12 col-md-4">
      <label class="form-label">Kategória</label>
      <select class="form-select" name="category" required>
        <option value="pbx">Központ</option>
        <option value="endpoint">Végberendezés</option>
      </select>
    </div>

    <div class="col-12 col-md-4">
      <label class="form-label">Gyártó</label>
      <select class="form-select" name="manufacturer_id" required>
        <option value="">— válassz —</option>
        <?php foreach ($mans as $m): ?>
          <option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?><?= (int)$m['is_archived']===1?' (archív)':'' ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-12 col-md-4">
      <label class="form-label">Típus</label>
      <input class="form-control" name="model" required>
    </div>

    <div class="col-12">
      <label class="form-label">Dokumentáció URL (opcionális)</label>
      <input class="form-control" name="doc_url" placeholder="https://...">
    </div>

    <div class="col-12">
      <label class="form-label">Megjegyzés</label>
      <textarea class="form-control" name="notes" rows="4"></textarea>
    </div>

    <div class="col-12">
      <label class="form-label">Dokumentáció feltöltés (opcionális)</label>
      <input class="form-control" type="file" name="files[]" multiple>
      <div class="form-text">Több fájl is kiválasztható. Mentéskor feltöltjük és a 📎 badge-ben látszik majd.</div>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-outline-secondary" href="<?= e(base_url('catalog_items.php')) ?>">Mégse</a>
    </div>
  </form>
</div>

<?php require __DIR__.'/_footer.php'; ?>

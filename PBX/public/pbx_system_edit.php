<?php
require __DIR__.'/../app/auth.php';
require_role('editor');
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM pbx_systems WHERE id=?");
$st->execute([$id]);
$pbx = $st->fetch();
if (!$pbx) { http_response_code(404); exit('Nincs ilyen központ'); }

$mans = $pdo->query("SELECT id,name,is_archived FROM manufacturers WHERE is_archived=0 ORDER BY name")->fetchAll();
$types = $pdo->query("
  SELECT ci.id, m.name AS manufacturer_name, ci.model
  FROM catalog_items ci
  JOIN manufacturers m ON m.id=ci.manufacturer_id
  WHERE ci.category='pbx' AND ci.is_archived=0
  ORDER BY m.name, ci.model
")->fetchAll();

function ensure_catalog_item(PDO $pdo, string $category, int $manufacturer_id, string $model): int {
  // Try exact match
  $st = $pdo->prepare("SELECT id FROM catalog_items WHERE category=? AND manufacturer_id=? AND model=? LIMIT 1");
  $st->execute([$category, $manufacturer_id, $model]);
  $id = (int)($st->fetchColumn() ?: 0);
  if ($id>0) return $id;

  $ins = $pdo->prepare("INSERT INTO catalog_items (category, manufacturer_id, model, doc_url, notes, is_archived) VALUES (?,?,?,?,?,0)");
  $ins->execute([$category, $manufacturer_id, $model, '', '',]);
  return (int)$pdo->lastInsertId();
}


$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $name = trim((string)($_POST['name'] ?? ''));
  $location = trim((string)($_POST['location'] ?? ''));
  $kind = (string)($_POST['kind'] ?? ($pbx['kind'] ?? 'analog'));
  if (!in_array($kind, ['analog','digital'], true)) $kind='analog';
  $contact_name = trim((string)($_POST['contact_name'] ?? ''));
  $contact_email = trim((string)($_POST['contact_email'] ?? ''));
  $contact_phone = trim((string)($_POST['contact_phone'] ?? ''));
  $ip = trim((string)($_POST['ip'] ?? ''));
  $access_url = trim((string)($_POST['access_url'] ?? ''));
  $access_user = trim((string)($_POST['access_user'] ?? ''));
  $access_pass = trim((string)($_POST['access_pass'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));
  $is_archived = isset($_POST['is_archived']) ? 1 : 0;

  $type_mode = (string)($_POST['type_mode'] ?? 'existing');
  $catalog_item_id = null;

  if ($type_mode === 'existing') {
    $cid = (int)($_POST['catalog_item_id'] ?? 0);
    if ($cid>0) $catalog_item_id = $cid;
  } else {
    $manufacturer_id = (int)($_POST['manufacturer_id'] ?? 0);
    $model = trim((string)($_POST['model'] ?? ''));
    if ($manufacturer_id>0 && $model!=='') {
      $catalog_item_id = ensure_catalog_item($pdo, 'pbx', $manufacturer_id, $model);
    }
  }

  if ($name==='') $err='Megnevezés kötelező.';
  else {
    $up = $pdo->prepare("UPDATE pbx_systems SET name=?, location=?, kind=?, catalog_item_id=?, contact_name=?, contact_email=?, contact_phone=?, ip=?, access_url=?, access_user=?, access_pass=?, notes=?, is_archived=? WHERE id=?");
    $up->execute([$name, $location ?: null, $kind, $catalog_item_id, $contact_name ?: null, $contact_email ?: null, $contact_phone ?: null, $ip ?: null, $access_url ?: null, $access_user ?: null, $access_pass ?: null, $notes ?: null, $is_archived, $id]);
    flash_set('ok', 'Mentve.');
    redirect('pbx_system_show.php?id='.$id);
  }
}

$title='Központ szerkesztése';
$page='Központok';
require __DIR__.'/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Központ szerkesztése</h1>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_system_show.php?id='.$id)) ?>">Vissza</a>
    <a class="btn btn-outline-primary" href="<?= e(base_url('pbx_system_files.php?id='.$id)) ?>">Dokumentumok</a>
    <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_systems.php')) ?>">Lista</a>
  </div>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="card p-3">
  <form method="post" class="row g-3">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="col-12 col-md-6">
      <label class="form-label">Megnevezés</label>
      <input class="form-control" name="name" value="<?= e($pbx['name']) ?>" required>
    </div>
    <div class="col-12 col-md-6">
      <label class="form-label">Telepítés helyszíne</label>
      <input class="form-control" name="location" value="<?= e($pbx['location'] ?? '') ?>">
    </div>

    <div class="col-12 col-md-3">
      <label class="form-label">Központ típusa</label>
      <select class="form-select" name="kind">
        <option value="analog" <?= (($pbx['kind'] ?? 'analog')==='analog')?'selected':'' ?>>Analóg</option>
        <option value="digital" <?= (($pbx['kind'] ?? 'analog')==='digital')?'selected':'' ?>>Digitális / IP</option>
      </select>
    </div>


    <div class="col-12">
      <label class="form-label">Típus</label>
      <div class="d-flex flex-wrap gap-2 mb-2">
        <div class="form-check">
          <input class="form-check-input" type="radio" name="type_mode" id="tm1" value="existing" checked>
          <label class="form-check-label" for="tm1">Választás meglévőből</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="type_mode" id="tm2" value="new">
          <label class="form-check-label" for="tm2">Új típus felvitele</label>
        </div>
      </div>

      <div class="row g-2">
        <div class="col-12 col-md-6">
          <select class="form-select" name="catalog_item_id">
            <option value="">— válassz —</option>
            <?php foreach ($types as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= (int)$pbx['catalog_item_id']===(int)$t['id']?'selected':'' ?>><?= e($t['manufacturer_name'].' / '.$t['model']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <select class="form-select" name="manufacturer_id">
            <option value="">— gyártó —</option>
            <?php foreach ($mans as $m): ?>
              <option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <input class="form-control" name="model" placeholder="Típus (új)">
        </div>
      </div>
      <div class="form-text">Ha a “Választás meglévőből” van, a fenti listát használja. Új esetén gyártó+típus alapján felvesszük a katalógusba.</div>
    </div>

    <div class="col-12 col-md-4">
      <label class="form-label">Kapcsolattartó név</label>
      <input class="form-control" name="contact_name" value="<?= e($pbx['contact_name'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label">Kapcsolattartó email</label>
      <input class="form-control" name="contact_email" value="<?= e($pbx['contact_email'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label">Kapcsolattartó telefon</label>
      <input class="form-control" name="contact_phone" value="<?= e($pbx['contact_phone'] ?? '') ?>">
    </div>

    <div class="col-12 col-md-3">
      <label class="form-label">IP cím</label>
      <input class="form-control" name="ip" value="<?= e($pbx['ip'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-6">
      <label class="form-label">URL eléréshez</label>
      <input class="form-control" name="access_url" value="<?= e($pbx['access_url'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label">Felhasználó</label>
      <input class="form-control" name="access_user" value="<?= e($pbx['access_user'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label">Jelszó</label>
      <input class="form-control" name="access_pass" value="<?= e($pbx['access_pass'] ?? '') ?>">
    </div>

    <div class="col-12">
      <label class="form-label">Megjegyzés</label>
      <textarea class="form-control" name="notes" rows="4"><?= e($pbx['notes'] ?? '') ?></textarea>
    </div>

    <div class="col-12">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="is_archived" id="arch" value="1" <?= (int)$pbx['is_archived']===1?'checked':'' ?>>
        <label class="form-check-label" for="arch">Archivált</label>
      </div>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_system_show.php?id='.$id)) ?>">Mégse</a>
    </div>
  </form>
</div>

<?php require __DIR__.'/_footer.php'; ?>

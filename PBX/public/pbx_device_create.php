<?php
require __DIR__.'/../app/auth.php';
require_role('editor');
$pdo = db();

$pbx_id = (int)($_GET['pbx_id'] ?? 0);
$st = $pdo->prepare("SELECT id,name FROM pbx_systems WHERE id=?");
$st->execute([$pbx_id]);
$pbx = $st->fetch();
if (!$pbx) { http_response_code(404); exit('Nincs ilyen központ'); }

$mans = $pdo->query("SELECT id,name FROM manufacturers WHERE is_archived=0 ORDER BY name")->fetchAll();
$types = $pdo->query("
  SELECT ci.id, m.name AS manufacturer_name, ci.model
  FROM catalog_items ci
  JOIN manufacturers m ON m.id=ci.manufacturer_id
  WHERE ci.category='endpoint' AND ci.is_archived=0
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
  $extension = trim((string)($_POST['extension'] ?? ''));
  $ip = trim((string)($_POST['ip'] ?? ''));
  $access_url = trim((string)($_POST['access_url'] ?? ''));
  $access_user = trim((string)($_POST['access_user'] ?? ''));
  $access_pass = trim((string)($_POST['access_pass'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));
  $admin_url = trim((string)($_POST['admin_url'] ?? ''));
  $reboot_url = trim((string)($_POST['reboot_url'] ?? ''));

  $type_mode = (string)($_POST['type_mode'] ?? 'existing');
  $catalog_item_id = null;

  if ($type_mode === 'existing') {
    $cid = (int)($_POST['catalog_item_id'] ?? 0);
    if ($cid>0) $catalog_item_id = $cid;
  } else {
    $manufacturer_id = (int)($_POST['manufacturer_id'] ?? 0);
    $model = trim((string)($_POST['model'] ?? ''));
    if ($manufacturer_id>0 && $model!=='') {
      $catalog_item_id = ensure_catalog_item($pdo, 'endpoint', $manufacturer_id, $model);
    }
  }

  if ($extension==='') $err='Mellékszám kötelező.';
  else {
    $ins = $pdo->prepare("INSERT INTO pbx_devices (pbx_id, catalog_item_id, extension, ip, access_url, access_user, access_pass, admin_url, reboot_url, notes, is_archived) VALUES (?,?,?,?,?,?,?,?,?,?,0)");
    $ins->execute([$pbx_id, $catalog_item_id, $extension, $ip ?: null, $access_url ?: null, $access_user ?: null, $access_pass ?: null, $admin_url ?: null, $reboot_url ?: null, $notes ?: null]);
    flash_set('ok', 'Mellék létrehozva.');
    redirect('pbx_system_show.php?id='.$pbx_id);
  }
}

$title='Új mellék';
$page='Központok';
require __DIR__.'/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h3 mb-1">Új mellék</h1>
    <div class="text-muted small"><?= e($pbx['name']) ?></div>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_system_show.php?id='.$pbx_id)) ?>">Vissza</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="card p-3">
  <form method="post" class="row g-3">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="col-12 col-md-3">
      <label class="form-label">Mellékszám</label>
      <input class="form-control" name="extension" required>
    </div>

    <div class="col-12 col-md-9">
      <label class="form-label">Eszköz típusa</label>
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
              <option value="<?= (int)$t['id'] ?>"><?= e($t['manufacturer_name'].' / '.$t['model']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Csak a “Végberendezés” kategóriájú eszköz-típusok.</div>
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
    </div>

    <div class="col-12 col-md-3">
      <label class="form-label">IP cím (ha IP-s)</label>
      <input class="form-control" name="ip">
    </div>
    <div class="col-12 col-md-6">
      <label class="form-label">URL (ha van)</label>
      <input class="form-control" name="access_url" placeholder="http://...">
      <div class="form-text">Alap elérés (pl. https://10.0.0.12)</div>
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label">Admin URL (felülírás)</label>
      <input class="form-control" name="admin_url" placeholder="pl. admin vagy #/advanced">
          <div class="form-text">Ha üres, az eszköztípus alapértelmezett értéke lesz használva.</div>
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label">Reboot URL (felülírás)</label>
      <input class="form-control" name="reboot_url" placeholder="pl. reboot vagy action/restart">
          <div class="form-text">Ha üres, az eszköztípus alapértelmezett értéke lesz használva.</div>
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label">Felhasználó</label>
      <input class="form-control" name="access_user">
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label">Jelszó</label>
      <input class="form-control" name="access_pass">
    </div>

    <div class="col-12">
      <label class="form-label">Megjegyzés</label>
      <textarea class="form-control" name="notes" rows="4"></textarea>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_system_show.php?id='.$pbx_id)) ?>">Mégse</a>
    </div>
  </form>
</div>

<?php require __DIR__.'/_footer.php'; ?>

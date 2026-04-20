<?php
require __DIR__.'/../app/auth.php';
require_login();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("
  SELECT p.*, m.name AS manufacturer_name, ci.model AS type_model
  FROM pbx_systems p
  LEFT JOIN catalog_items ci ON ci.id=p.catalog_item_id
  LEFT JOIN manufacturers m ON m.id=ci.manufacturer_id
  WHERE p.id=?
");
$st->execute([$id]);
$pbx = $st->fetch();
if (!$pbx) { http_response_code(404); exit('Nincs ilyen központ'); }

$devSt = $pdo->prepare("
  SELECT d.*, m.name AS manufacturer_name, ci.model AS type_model
  FROM pbx_devices d
  LEFT JOIN catalog_items ci ON ci.id=d.catalog_item_id
  LEFT JOIN manufacturers m ON m.id=ci.manufacturer_id
  WHERE d.pbx_id=? AND d.is_archived=0
  ORDER BY d.extension
");
$devSt->execute([$id]);
$devices = $devSt->fetchAll();

$title='Központ részletek';
$page='Központok';
require __DIR__.'/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h3 mb-1"><?= e($pbx['name']) ?></h1>
    <div class="text-muted small"><?= e($pbx['location'] ?? '') ?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_systems.php')) ?>">Vissza</a>
    <?php if ((current_user()['role'] ?? 'viewer') !== 'viewer'): ?>
      <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_system_edit.php?id='.$id)) ?>">Szerkesztés</a>
    <?php endif; ?>
    <?php if (!empty($pbx['access_url'])): ?>
      <a class="btn btn-success" href="<?= e(base_url('pbx_access.php?id='.$id)) ?>">Belépés</a>
    <?php endif; ?>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="row g-3">
    <div class="col-12 col-md-3">
      <div class="text-muted small">Központ típusa</div>
      <div><?= (($pbx['kind'] ?? 'analog')==='digital') ? 'Digitális / IP' : 'Analóg' ?></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="text-muted small">Típus</div>
      <div><?= e(trim(($pbx['manufacturer_name'] ?? '').' '.$pbx['type_model'])) ?: '—' ?></div>
    </div>
    <div class="col-12 col-md-3">
      <div class="text-muted small">IP</div>
      <div><?= e($pbx['ip'] ?? '') ?: '—' ?></div>
    </div>
    <div class="col-12 col-md-3">
      <div class="text-muted small">URL</div>
      <?php if (!empty($pbx['access_url'])): ?>
        <a target="_blank" href="<?= e($pbx['access_url']) ?>"><?= e($pbx['access_url']) ?></a>
      <?php else: ?>—<?php endif; ?>
    </div>

    <div class="col-12 col-md-4">
      <div class="text-muted small">Kapcsolattartó</div>
      <div><?= e($pbx['contact_name'] ?? '') ?: '—' ?></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="text-muted small">Email</div>
      <div><?= e($pbx['contact_email'] ?? '') ?: '—' ?></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="text-muted small">Telefon</div>
      <div><?= e($pbx['contact_phone'] ?? '') ?: '—' ?></div>
    </div>

    <div class="col-12">
      <div class="text-muted small">Megjegyzés</div>
      <div><?= nl2br(e($pbx['notes'] ?? '')) ?: '—' ?></div>
    </div>
  </div>
</div>

<?php if ($devices): ?>
  <div class="card">
    <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
      <div class="fw-semibold">Mellékek ezen a központon</div>
      <?php if ((current_user()['role'] ?? 'viewer') !== 'viewer'): ?>
        <a class="btn btn-sm btn-primary" href="<?= e(base_url('pbx_device_create.php?pbx_id='.$id)) ?>">+ Új mellék</a>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Mellék</th>
            <th>Eszköz</th>
            <th>IP</th>
            <th style="width:260px">Műveletek</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
          <tr>
            <td class="fw-semibold"><?= e($d['extension']) ?></td>
            <td class="text-muted small">
              <?php if ($d['manufacturer_name'] || $d['type_model']): ?>
                <?= e(trim(($d['manufacturer_name'] ?? '').' '.$d['type_model'])) ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= e($d['ip'] ?? '') ?: '—' ?></td>
            <td>
              <div class="d-flex gap-2 flex-wrap">
                <?php if ((current_user()['role'] ?? 'viewer') !== 'viewer'): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('pbx_device_edit.php?id='.(int)$d['id'])) ?>">Szerkesztés</a>
                  <form method="post" action="<?= e(base_url('pbx_device_delete.php')) ?>" onsubmit="return confirm('Biztos törlöd (archiválod) a melléket?')" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="pbx_id" value="<?= (int)$id ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Törlés</button>
                  </form>
                <?php endif; ?>
                <?php if (!empty($d['access_url'])): ?>
                  <a class="btn btn-sm btn-success" target="_blank" href="<?= e($d['access_url']) ?>">Belépés</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <?php if ((current_user()['role'] ?? 'viewer') !== 'viewer'): ?>
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center">
        <div class="text-muted">Nincs rögzített mellék ehhez a központhoz.</div>
        <a class="btn btn-sm btn-primary" href="<?= e(base_url('pbx_device_create.php?pbx_id='.$id)) ?>">+ Új mellék</a>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__.'/_footer.php'; ?>

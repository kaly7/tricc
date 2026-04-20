<?php
require __DIR__.'/../app/auth.php';
require_login();
$pdo = db();

$show = (string)($_GET['show'] ?? 'active'); // active|archived|all
$q = trim((string)($_GET['q'] ?? ''));

$where = "p.is_archived=0";
if ($show === 'archived') $where = "p.is_archived=1";
if ($show === 'all') $where = "1=1";

$params = [];
$searchSql = "";
if ($q !== '') {
  $searchSql = " AND (p.name LIKE :q OR p.location LIKE :q OR m.name LIKE :q OR ci.model LIKE :q)";
  $params[':q'] = "%$q%";
}

$sql = "
  SELECT p.*, m.name AS manufacturer_name, ci.model AS type_model,
    (SELECT COUNT(*) FROM pbx_devices d WHERE d.pbx_id=p.id AND d.is_archived=0) AS device_count
  FROM pbx_systems p
  LEFT JOIN catalog_items ci ON ci.id=p.catalog_item_id
  LEFT JOIN manufacturers m ON m.id=ci.manufacturer_id
  WHERE $where $searchSql
  ORDER BY p.name
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$title = 'Központok';
$page  = 'Központok';
require __DIR__.'/_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Központok</h1>
  <?php if ((current_user()['role'] ?? 'viewer') !== 'viewer'): ?>
    <a class="btn btn-primary" href="<?= e(base_url('pbx_system_create.php')) ?>">+ Új központ</a>
  <?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-12 col-md-4">
    <input class="form-control" name="q" placeholder="Keresés (név, hely, gyártó, típus)" value="<?= e($q) ?>">
  </div>
  <div class="col-12 col-md-4">
    <div class="btn-group" role="group" aria-label="szűrő">
      <a class="btn btn-outline-secondary <?= $show==='active'?'active':'' ?>" href="<?= e(base_url('pbx_systems.php?show=active&q='.urlencode($q))) ?>">Aktív</a>
      <a class="btn btn-outline-secondary <?= $show==='archived'?'active':'' ?>" href="<?= e(base_url('pbx_systems.php?show=archived&q='.urlencode($q))) ?>">Archív</a>
      <a class="btn btn-outline-secondary <?= $show==='all'?'active':'' ?>" href="<?= e(base_url('pbx_systems.php?show=all&q='.urlencode($q))) ?>">Mind</a>
    </div>
  </div>
  <div class="col-12 col-md-2">
    <button class="btn btn-secondary w-100" type="submit">Keres</button>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Név</th>
          <th>Hely</th>
          <th>Típus</th>
          <th>Mellékek</th>
          <th style="width:320px">Műveletek</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="fw-semibold"><?= e($r['name']) ?> <?php if (($r['kind'] ?? 'analog')==='digital'): ?><span class="badge bg-primary ms-1">IP</span><?php else: ?><span class="badge bg-secondary ms-1">Analóg</span><?php endif; ?></td>
          <td><?= e($r['location'] ?? '') ?></td>
          <td class="text-muted small">
            <?php if ($r['manufacturer_name'] || $r['type_model']): ?>
              <?= e(trim(($r['manufacturer_name'] ?? '').' '.$r['type_model'])) ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td>
            <?php $dc = (int)$r['device_count']; ?>
            <?php if ($dc>0): ?><span class="badge bg-info text-dark"><?= $dc ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-2 flex-wrap">
              <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('pbx_system_show.php?id='.(int)$r['id'])) ?>">Részletek</a>
              <?php if ((current_user()['role'] ?? 'viewer') !== 'viewer'): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('pbx_system_edit.php?id='.(int)$r['id'])) ?>">Szerkesztés</a>
              <?php endif; ?>
              <?php if (!empty($r['access_url'])): ?>
                <a class="btn btn-sm btn-success" href="<?= e(base_url('pbx_access.php?id='.(int)$r['id'])) ?>">Belépés</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-muted">Nincs találat.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__.'/_footer.php'; ?>

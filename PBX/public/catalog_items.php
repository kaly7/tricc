<?php
require __DIR__.'/../app/auth.php';
require_login();
$pdo = db();

$show = (string)($_GET['show'] ?? 'active'); // active|archived|all
$cat  = (string)($_GET['cat'] ?? 'all'); // all|pbx|endpoint
$q = trim((string)($_GET['q'] ?? ''));

$where = "ci.is_archived=0";
if ($show === 'archived') $where = "ci.is_archived=1";
if ($show === 'all') $where = "1=1";

$whereCat = '';
if ($cat === 'pbx') $whereCat = " AND ci.category='pbx'";
if ($cat === 'endpoint') $whereCat = " AND ci.category='endpoint'";

$params = [];
$searchSql = "";
if ($q !== '') {
  $searchSql = " AND (m.name LIKE :q OR ci.model LIKE :q)";
  $params[':q'] = "%$q%";
}

$sql = "
  SELECT ci.*, m.name AS manufacturer_name,
    (SELECT COUNT(*) FROM catalog_files f WHERE f.catalog_item_id=ci.id AND f.is_archived=0) AS file_count
  FROM catalog_items ci
  JOIN manufacturers m ON m.id = ci.manufacturer_id
  WHERE $where $whereCat $searchSql
  ORDER BY m.name, ci.model
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$title = 'Eszköz-típusok';
$page  = 'Eszköz-típusok';
require __DIR__.'/_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Eszköz-típusok</h1>
  <a class="btn btn-primary" href="<?= e(base_url('catalog_item_create.php')) ?>">+ Új eszköz-típus</a>
</div>

<form class="row g-2 mb-3" method="get">
  <input type="hidden" name="show" value="<?= e($show) ?>">
  <div class="col-12 col-md-4">
    <input class="form-control" name="q" placeholder="Keresés (gyártó, típus)" value="<?= e($q) ?>">
  </div>
  <div class="col-12 col-md-4">
    <div class="btn-group" role="group" aria-label="szűrő">
      <a class="btn btn-outline-secondary <?= $show==='active'?'active':'' ?>" href="<?= e(base_url('catalog_items.php?show=active&cat=' . urlencode($cat) . '&q=' . urlencode($q))) ?>">Aktív</a>
      <a class="btn btn-outline-secondary <?= $show==='archived'?'active':'' ?>" href="<?= e(base_url('catalog_items.php?show=archived&cat=' . urlencode($cat) . '&q=' . urlencode($q))) ?>">Archív</a>
      <a class="btn btn-outline-secondary <?= $show==='all'?'active':'' ?>" href="<?= e(base_url('catalog_items.php?show=all&cat=' . urlencode($cat) . '&q=' . urlencode($q))) ?>">Mind</a>
    </div>
  </div>
  <div class="col-12 col-md-3">
    <select class="form-select" name="cat" onchange="this.form.submit()">
      <option value="all" <?= $cat==='all'?'selected':'' ?>>Mind (központ + végberendezés)</option>
      <option value="pbx" <?= $cat==='pbx'?'selected':'' ?>>Csak központ</option>
      <option value="endpoint" <?= $cat==='endpoint'?'selected':'' ?>>Csak végberendezés</option>
    </select>
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
          <th>Kategória</th>
          <th>Gyártó</th>
          <th>Típus</th>
          <th>Doksik</th>
          <th style="width:260px">Műveletek</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <?php if ($r['category']==='pbx'): ?>
              <span class="badge bg-primary">Központ</span>
            <?php else: ?>
              <span class="badge bg-info text-dark">Végberendezés</span>
            <?php endif; ?>
          </td>
          <td><?= e($r['manufacturer_name']) ?></td>
          <td><?= e($r['model']) ?></td>
          <td>
            <?php $c = (int)$r['file_count']; ?>
            <?php if ($c>0): ?>
              <span class="badge bg-success">📎 <?= $c ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="d-flex gap-2 flex-wrap">
              <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('catalog_item_edit.php?id='.(int)$r['id'])) ?>">Szerkesztés</a>
              <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('catalog_item_files.php?id='.(int)$r['id'])) ?>">Dokumentáció</a>
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

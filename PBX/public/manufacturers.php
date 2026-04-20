<?php
require_once __DIR__.'/../app/auth.php';
require_role('editor');
$pdo = db();

$show = (string)($_GET['show'] ?? 'active'); // active|archived|all
$where = "WHERE is_archived=0";
if ($show === 'archived') $where = "WHERE is_archived=1";
if ($show === 'all') $where = "WHERE 1=1";

$rows = $pdo->query("SELECT * FROM manufacturers $where ORDER BY name")->fetchAll();

$title = 'Gyártók';
$page = 'Gyártók';
include __DIR__.'/_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1 class="h4 mb-0">Gyártók</h1>
  <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
    <a class="btn btn-primary" href="<?= e(base_url('manufacturer_create.php')) ?>">+ Új gyártó</a>
  <?php endif; ?>
</div>

<div class="btn-group mb-3" role="group" aria-label="Szűrő">
  <a class="btn btn-outline-secondary <?= $show==='active'?'active':'' ?>" href="<?= e(base_url('manufacturers.php?show=active')) ?>">Aktív</a>
  <a class="btn btn-outline-secondary <?= $show==='archived'?'active':'' ?>" href="<?= e(base_url('manufacturers.php?show=archived')) ?>">Archív</a>
  <a class="btn btn-outline-secondary <?= $show==='all'?'active':'' ?>" href="<?= e(base_url('manufacturers.php?show=all')) ?>">Mind</a>
</div>

<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Név</th>
            <th>Állapot</th>
            <th style="width:220px">Műveletek</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td>
              <?php if ((int)$r['is_archived']===1): ?>
                <span class="badge bg-secondary">Archív</span>
              <?php else: ?>
                <span class="badge bg-success">Aktív</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(base_url('manufacturer_edit.php?id='.(int)$r['id'])) ?>">Szerkesztés</a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="3" class="text-secondary">Nincs találat.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__.'/_footer.php'; ?>

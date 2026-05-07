<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();

$page = 'admin_tasks';

$validStatuses = ['aktív','passzív','vár','archív'];
$filterStatus  = in_array($_GET['status'] ?? '', $validStatuses) ? $_GET['status'] : '';

if ($filterStatus) {
  $st = db()->prepare("SELECT id, title, status, system_key, color, note FROM tasks WHERE status=? ORDER BY system_key IS NULL, title");
  $st->execute([$filterStatus]);
} else {
  $st = db()->query("SELECT id, title, status, system_key, color, note FROM tasks ORDER BY system_key IS NULL, title");
}
$tasks = $st->fetchAll();

require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1 class="h5 mb-0">Feladatbank</h1>
  <a class="btn btn-sm btn-primary" href="<?= base_url('admin_task_edit.php') ?>">+ Új feladat</a>
</div>

<?php $ok = flash_get('ok'); if ($ok): ?>
  <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    <?= e($ok) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<form class="d-flex gap-2 mb-3 flex-wrap" method="get">
  <select name="status" class="form-select form-select-sm" style="width:auto">
    <option value="">Minden státusz</option>
    <?php foreach (['aktív','passzív','vár','archív'] as $s): ?>
      <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-sm btn-outline-secondary" type="submit">Szűrés</button>
  <?php if ($filterStatus): ?>
    <a class="btn btn-sm btn-link" href="?">Összes</a>
  <?php endif; ?>
</form>

<?php if (!$tasks): ?>
  <div class="alert alert-info">Nincs feladat<?= $filterStatus ? ' ebben a státuszban' : '' ?>.
    <a href="<?= base_url('admin_task_edit.php') ?>">Adj hozzá egyet.</a></div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead class="table-dark">
        <tr>
          <th style="width:32px"></th>
          <th>Feladat neve</th>
          <th>Státusz</th>
          <th>Megjegyzés</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tasks as $t):
          $sysEmoji = match($t['system_key'] ?? '') {
            'vacation'  => '🌴 ',
            'sick_leave'=> '🤒 ',
            default     => '',
          };
        ?>
        <tr>
          <td>
            <span style="display:inline-block;width:22px;height:22px;border-radius:4px;background:<?= e($t['color']) ?>"></span>
          </td>
          <td class="fw-semibold">
            <?= $sysEmoji . e($t['title']) ?>
            <?php if ($t['system_key']): ?>
              <span class="badge bg-secondary ms-1" style="font-size:.65rem">rendszer</span>
            <?php endif; ?>
          </td>
          <td><?= status_badge($t['status']) ?></td>
          <td class="text-muted small"><?= e(mb_strimwidth((string)$t['note'], 0, 60, '…')) ?></td>
          <td class="text-end text-nowrap">
            <?php if (!$t['system_key']): ?>
              <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('admin_task_edit.php?id='.(int)$t['id']) ?>">Szerkesztés</a>
            <?php else: ?>
              <span class="text-muted small">védett</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

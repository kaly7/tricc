<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();
$page = 'admin_tasks';

$from = $_GET['from'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d', strtotime('+14 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d', strtotime('+14 days'));

$st = db()->prepare("
  SELECT t.id, t.title, t.task_date, t.time_from, t.time_to, t.color, t.note,
         l.name AS location_name,
         GROUP_CONCAT(e.full_name ORDER BY e.full_name SEPARATOR ', ') AS emp_names
  FROM tasks t
  LEFT JOIN locations l ON l.id=t.location_id
  LEFT JOIN task_assignments ta ON ta.task_id=t.id
  LEFT JOIN hr.employees e ON e.id=ta.employee_id
  WHERE t.task_date BETWEEN ? AND ?
  GROUP BY t.id
  ORDER BY t.task_date, t.time_from, t.id
");
$st->execute([$from, $to]);
$tasks = $st->fetchAll();

$empMap = employees_map();
require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1 class="h5 mb-0">Feladatok kezelése</h1>
  <a class="btn btn-primary btn-sm" href="<?= base_url('admin_task_edit.php') ?>">+ Új feladat</a>
</div>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto">
    <label class="form-label small mb-1">Dátumtól</label>
    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Dátumig</label>
    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>">
  </div>
  <div class="col-auto"><button class="btn btn-outline-secondary btn-sm">Szűrés</button></div>
</form>

<?php if (!$tasks): ?>
  <div class="alert alert-info">Nincs feladat ebben az időszakban.</div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead class="table-dark">
        <tr>
          <th style="width:36px"></th>
          <th>Dátum</th>
          <th>Időpont</th>
          <th>Feladat</th>
          <th>Helyszín</th>
          <th>Dolgozók</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tasks as $t): ?>
        <tr>
          <td><span style="display:inline-block;width:22px;height:22px;border-radius:4px;background:<?= e($t['color']) ?>"></span></td>
          <td class="text-nowrap"><?= e($t['task_date']) ?></td>
          <td class="text-nowrap text-muted">
            <?= $t['time_from'] ? fmt_time($t['time_from']) : '—' ?>
            <?= $t['time_to']   ? '–'.fmt_time($t['time_to']) : '' ?>
          </td>
          <td class="fw-semibold"><?= e($t['title']) ?></td>
          <td class="text-muted"><?= e($t['location_name'] ?? '') ?></td>
          <td class="small text-muted"><?= e($t['emp_names'] ?? '—') ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= base_url('admin_task_edit.php?id='.(int)$t['id']) ?>">Szerkesztés</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

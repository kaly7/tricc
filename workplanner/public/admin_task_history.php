<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();

$page = 'admin_task_history';

$today   = date('Y-m-d');
$defFrom = date('Y-m-d', strtotime('-30 days'));
$defTo   = $today;

$filterFrom   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']   ?? '') ? $_GET['from']   : $defFrom;
$filterTo     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']     ?? '') ? $_GET['to']     : $defTo;
$filterEmp    = filter_input(INPUT_GET, 'employee_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$filterStatus = in_array($_GET['status'] ?? '', ['aktív','passzív','vár','archív']) ? $_GET['status'] : '';
$filterTaskId = filter_input(INPUT_GET, 'task_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

$employees = get_employees();
$allTasks  = db()->query("SELECT id, title, status FROM tasks ORDER BY title")->fetchAll();

$params = [$filterFrom, $filterTo];
$where  = ['ta.task_date BETWEEN ? AND ?'];

if ($filterEmp) {
  $where[]  = 'ta.employee_id = ?';
  $params[] = $filterEmp;
}
if ($filterStatus) {
  $where[]  = 't.status = ?';
  $params[] = $filterStatus;
}
if ($filterTaskId) {
  $where[]  = 't.id = ?';
  $params[] = $filterTaskId;
}

$sql = "
  SELECT ta.task_date,
         t.id AS task_id, t.title, t.status, t.color,
         e.full_name
  FROM task_assignments ta
  JOIN tasks t ON t.id = ta.task_id
  JOIN hr.employees e ON e.id = ta.employee_id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY ta.task_date DESC, t.title, e.full_name
";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Csoportosítás: dátum + feladat_id → dolgozók listája
$grouped  = [];
$empHours = [];   // full_name => óra
$taskHours= [];   // title => óra
$days     = [];   // egyedi napok

foreach ($rows as $r) {
    $key = $r['task_date'] . '|' . $r['task_id'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'task_date' => $r['task_date'],
            'task_id'   => $r['task_id'],
            'title'     => $r['title'],
            'status'    => $r['status'],
            'color'     => $r['color'],
            'employees' => [],
        ];
    }
    $grouped[$key]['employees'][] = $r['full_name'];
    $empHours[$r['full_name']]    = ($empHours[$r['full_name']] ?? 0) + 8;
    $taskHours[$r['title']]       = ($taskHours[$r['title']]    ?? 0) + 8;
    $days[$r['task_date']]        = true;
}

arsort($empHours);
arsort($taskHours);

$totalDays    = count($days);
$totalEmps    = count($empHours);
$totalEntries = count($rows);

require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Feladat-előzmények</h1>
  <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('admin_tasks.php') ?>">← Feladatbank</a>
</div>

<form method="get" class="row g-2 mb-3 align-items-end flex-wrap">
  <div class="col-auto">
    <label class="form-label small mb-1">Dátumtól</label>
    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Dátumig</label>
    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Dolgozó</label>
    <select name="employee_id" class="form-select form-select-sm">
      <option value="">Mindenki</option>
      <?php foreach ($employees as $emp): ?>
        <option value="<?= (int)$emp['id'] ?>" <?= $filterEmp === (int)$emp['id'] ? 'selected' : '' ?>>
          <?= e($emp['full_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Státusz</label>
    <select name="status" class="form-select form-select-sm">
      <option value="">Minden</option>
      <?php foreach (['aktív','passzív','vár','archív'] as $s): ?>
        <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Feladat neve</label>
    <select id="taskSelect" name="task_id" class="form-select form-select-sm" style="width:200px">
      <option value="">Minden feladat</option>
      <?php foreach ($allTasks as $t): ?>
        <option value="<?= (int)$t['id'] ?>"
                data-status="<?= e($t['status']) ?>"
                <?= $filterTaskId === (int)$t['id'] ? 'selected' : '' ?>>
          <?= e($t['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary">Szűrés</button>
    <a class="btn btn-sm btn-link" href="?">Törlés</a>
  </div>
</form>

<?php
$exportBase = base_url('admin_task_export.php') . '?' . http_build_query(array_filter([
    'from'        => $filterFrom,
    'to'          => $filterTo,
    'employee_id' => $filterEmp   ?: null,
    'status'      => $filterStatus ?: null,
    'task_id'     => $filterTaskId ?: null,
]));
?>
<div class="d-flex gap-2 mb-3">
  <a class="btn btn-sm btn-outline-success" href="<?= $exportBase ?>&format=csv">⬇ CSV</a>
  <a class="btn btn-sm btn-outline-primary"  href="<?= $exportBase ?>&format=xlsx">⬇ XLSX</a>
  <a class="btn btn-sm btn-outline-danger"   href="<?= $exportBase ?>&format=pdf">⬇ PDF</a>
</div>

<script>
(function () {
  const statusSel = document.querySelector('select[name="status"]');
  const taskSel   = document.getElementById('taskSelect');
  const allOpts   = Array.from(taskSel.options);

  function filterTasks() {
    const st  = statusSel.value;
    const cur = taskSel.value;
    let found = false;
    allOpts.forEach(opt => {
      if (opt.value === '') { opt.hidden = false; return; }
      const match = !st || opt.dataset.status === st;
      opt.hidden = !match;
      if (opt.value === cur && match) found = true;
    });
    if (!found) taskSel.value = '';
  }

  statusSel.addEventListener('change', filterTasks);
  filterTasks();
})();
</script>

<?php if (!$rows): ?>
  <div class="alert alert-info">Nincs találat a megadott szűrőkre.</div>
<?php else: ?>

<p class="text-muted small mb-2">
  <?= count($grouped) ?> sor (<?= $totalEntries ?> hozzárendelés, <?= $totalDays ?> nap)
</p>

<div class="card mb-4">
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0">
      <thead class="table-dark">
        <tr>
          <th style="width:105px">Dátum</th>
          <th style="width:22px"></th>
          <th>Feladat</th>
          <th style="width:90px">Státusz</th>
          <th>Dolgozók</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($grouped as $g): ?>
        <tr>
          <td class="text-nowrap text-muted small"><?= e($g['task_date']) ?></td>
          <td>
            <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= e($g['color']) ?>"></span>
          </td>
          <td class="fw-semibold">
            <a href="<?= base_url('admin_task_edit.php?id='.(int)$g['task_id']) ?>" class="text-decoration-none text-body">
              <?= e($g['title']) ?>
            </a>
          </td>
          <td><?= status_badge($g['status']) ?></td>
          <td class="small text-muted"><?= e(implode(', ', $g['employees'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Összesítés -->
<h2 class="h6 mb-3">Összesítés</h2>
<div class="row g-3 mb-2">

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header py-2 small fw-semibold">Dolgozónként</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th>Dolgozó</th>
              <th class="text-end" style="width:90px">Bejegyzés</th>
              <th class="text-end" style="width:90px">Munkaóra</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($empHours as $name => $hours): ?>
            <tr>
              <td><?= e($name) ?></td>
              <td class="text-end text-muted"><?= $hours / 8 ?></td>
              <td class="text-end fw-semibold"><?= $hours ?> h</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <th><?= $totalEmps ?> fő</th>
              <th class="text-end"><?= $totalEntries ?></th>
              <th class="text-end"><?= $totalEntries * 8 ?> h</th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header py-2 small fw-semibold">Feladatonként</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th>Feladat</th>
              <th class="text-end" style="width:90px">Bejegyzés</th>
              <th class="text-end" style="width:90px">Munkaóra</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($taskHours as $title => $hours): ?>
            <tr>
              <td><?= e($title) ?></td>
              <td class="text-end text-muted"><?= $hours / 8 ?></td>
              <td class="text-end fw-semibold"><?= $hours ?> h</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <th><?= count($taskHours) ?> feladat</th>
              <th class="text-end"><?= $totalEntries ?></th>
              <th class="text-end"><?= $totalEntries * 8 ?> h</th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

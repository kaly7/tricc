<?php
require_once __DIR__ . '/../app/auth.php';
require_login();

$page    = 'index';
$isAdmin = (current_user()['role'] ?? '') === 'admin';
$today   = date('Y-m-d');

$from = $_GET['from'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $today;

$days      = work_days($from, 5);
$tasks     = get_tasks_for_days($days);
$taskIdx   = index_tasks($tasks);
$employees = get_employees();

$myEmpId = my_employee_id();
if ($isAdmin) {
  $shownEmps = $employees;
} else {
  $vis = [];
  foreach ($taskIdx as $dt) foreach (array_keys($dt) as $eid) $vis[$eid] = true;
  if ($myEmpId) $vis[$myEmpId] = true;
  $shownEmps = array_filter($employees, fn($e) => isset($vis[(int)$e['id']]));
}

$prevDate  = (new DateTime($days[0]))->modify('-7 days')->format('Y-m-d');
$nextDate  = (new DateTime(end($days)))->modify('+1 day')->format('Y-m-d');
$hunDays   = ['','Hétfő','Kedd','Szerda','Csütörtök','Péntek','Szombat','Vasárnap'];
$hunMonths = ['','január','február','március','április','május','június','július','augusztus','szeptember','október','november','december'];

require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1 class="h5 mb-0">Heti munkaterv</h1>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <a class="btn btn-sm btn-outline-secondary" href="?from=<?= e($prevDate) ?>">← Előző</a>
    <a class="btn btn-sm btn-outline-secondary" href="?from=<?= e($today) ?>">Ma</a>
    <a class="btn btn-sm btn-outline-secondary" href="?from=<?= e($nextDate) ?>">Következő →</a>
    <?php if ($isAdmin): ?>
      <a class="btn btn-sm btn-primary" href="<?= base_url('admin_task_edit.php') ?>">+ Új feladat</a>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('kiosk.php') ?>" target="_blank">🖥 Kiosk</a>
  </div>
</div>

<?php if (!$shownEmps): ?>
<div class="alert alert-info">
  Nincsenek dolgozók a tervben.
  <?php if ($isAdmin): ?><a href="<?= base_url('admin_employees.php') ?>">Adj hozzá dolgozókat.</a><?php endif; ?>
</div>
<?php else: ?>

<div style="overflow-x:auto">
<table class="wp-table">
  <thead>
    <tr>
      <th style="min-width:140px">Dolgozó</th>
      <?php foreach ($days as $d):
        $dow     = (int)(new DateTime($d))->format('N');
        $isToday = ($d === $today);
        $isWe    = $dow >= 6;
        $dm      = explode('-', $d);
        $label   = $hunMonths[(int)$dm[1]].' '.(int)$dm[2].'. '.$hunDays[$dow];
      ?>
      <th class="<?= $isToday ? 'today-col' : ($isWe ? 'we-col' : '') ?>">
        <?= e($label) ?>
        <?php if ($isToday): ?><br><small class="opacity-75">ma</small><?php endif; ?>
        <?php if ($isWe):   ?><br><small class="opacity-75">hétvége</small><?php endif; ?>
      </th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($shownEmps as $emp):
      $eid = (int)$emp['id'];
    ?>
    <tr>
      <td class="emp-col">
        <?= e($emp['full_name']) ?>
        <?php if ($emp['company_division']): ?>
          <br><small class="text-muted fw-normal"><?= e($emp['company_division']) ?></small>
        <?php endif; ?>
      </td>
      <?php foreach ($days as $d):
        $isToday   = ($d === $today);
        $cellTasks = $taskIdx[$d][$eid] ?? [];
      ?>
      <td class="day-cell<?= $isToday ? ' today-cell' : '' ?>">
        <div class="day-cell-flex">
          <?php
          $overlapIds = overlapping_task_ids($cellTasks);
          foreach ($cellTasks as $t):
            $timeStr = '';
            if ($t['time_from']) {
              $timeStr = fmt_time($t['time_from']);
              if ($t['time_to']) $timeStr .= '–' . fmt_time($t['time_to']);
            }
            $bg      = e($t['color']);
            $fg      = e(contrast_color($t['color']));
            $tooltip = $t['title']
              . ($t['location_name'] ? ' · ' . $t['location_name'] : '')
              . ($t['note']          ? "\n" . $t['note']           : '')
              . (isset($overlapIds[$t['id']]) ? "\n⚠ Időbeli átfedés!" : '');
            $cls = 'tl-task' . (isset($overlapIds[$t['id']]) ? ' overlap' : '');
          ?>
          <div class="<?= $cls ?>" style="background:<?= $bg ?>;color:<?= $fg ?>" title="<?= e($tooltip) ?>">
            <?php if ($timeStr): ?>
              <span class="tl-task-time"><?= e($timeStr) ?></span>
            <?php endif; ?>
            <span class="tl-task-title"><?= e($t['title']) ?></span>
            <?php if ($t['location_name']): ?>
              <span class="tl-task-loc">· <?= e($t['location_name']) ?></span>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
              <a href="<?= base_url('admin_task_edit.php?id='.(int)$t['id']) ?>" class="tl-edit-lnk" title="Szerkesztés">✎</a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>

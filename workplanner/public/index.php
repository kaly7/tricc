<?php
require_once __DIR__ . '/../app/auth.php';
require_login();

$page    = 'index';
$isAdmin = (current_user()['role'] ?? '') === 'admin';

$head_extra = '
  .tl-task { cursor: pointer; }
  .wp-pop {
    position: fixed; z-index: 1050; display: none;
    background: #fff; border: 1px solid #dee2e6; border-radius: 10px;
    box-shadow: 0 6px 24px rgba(0,0,0,.18); padding: 14px 16px;
    min-width: 230px; max-width: 320px; font-size: .875rem; color: #212529;
  }
  .wp-pop.show { display: block; }
  .wp-pop-title { font-weight: 700; font-size: .95rem; margin-bottom: 10px;
    padding-right: 18px; line-height: 1.3; }
  .wp-pop-row { display: flex; gap: 8px; margin-bottom: 5px; }
  .wp-pop-lbl { color: #6c757d; flex-shrink: 0; width: 20px; text-align: center; }
  .wp-pop-val { color: #212529; word-break: break-word; }
  .wp-pop-close { position: absolute; top: 9px; right: 11px; cursor: pointer;
    color: #adb5bd; font-size: 1rem; line-height: 1; }
  .wp-pop-close:hover { color: #495057; }
  .wp-pop-warn { font-size: .75rem; color: #dc3545; margin-top: 6px; }
  .wp-pop-edit { display: block; margin-top: 10px; font-size: .8rem;
    text-align: right; color: #0d6efd; text-decoration: none; }
  .wp-pop-edit:hover { text-decoration: underline; }
';
$today   = date('Y-m-d');

$from = $_GET['from'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $today;

$days      = work_days($from, 5);
$tasks     = get_tasks_for_days($days);
$taskIdx   = index_tasks($tasks);
$employees = get_employees();

$empMap  = employees_map();
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
            $empNames = array_values(array_filter(array_map(
              fn($eid) => $empMap[$eid]['full_name'] ?? null, $t['employee_ids']
            )));
            $popData = htmlspecialchars(json_encode([
              'title' => $t['title'],
              'date'  => $t['task_date'],
              'from'  => $t['time_from'] ? fmt_time($t['time_from']) : null,
              'to'    => $t['time_to']   ? fmt_time($t['time_to'])   : null,
              'loc'   => $t['location_name'],
              'note'  => $t['note'],
              'emps'  => $empNames,
              'overlap' => isset($overlapIds[$t['id']]),
              'edit_id' => $isAdmin ? (int)$t['id'] : null,
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
          ?>
          <div class="<?= $cls ?>" style="background:<?= $bg ?>;color:<?= $fg ?>" data-task="<?= $popData ?>">
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

<!-- Feladat buborék -->
<div class="wp-pop" id="wpPop" role="tooltip">
  <span class="wp-pop-close" id="wpPopClose">&times;</span>
  <div class="wp-pop-title" id="wpPopTitle"></div>
  <div id="wpPopBody"></div>
</div>

<script>
(function () {
  const pop   = document.getElementById('wpPop');
  const title = document.getElementById('wpPopTitle');
  const body  = document.getElementById('wpPopBody');
  const close = document.getElementById('wpPopClose');

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function row(icon, val) {
    return `<div class="wp-pop-row"><span class="wp-pop-lbl">${icon}</span><span class="wp-pop-val">${val}</span></div>`;
  }

  function showPop(data, x, y) {
    title.textContent = data.title;

    let html = '';
    if (data.from) {
      html += row('⏱', esc(data.from) + (data.to ? '–' + esc(data.to) : ''));
    } else {
      html += row('⏱', 'Egész napos');
    }
    if (data.loc)  html += row('📍', esc(data.loc));
    if (data.emps && data.emps.length) html += row('👤', data.emps.map(esc).join(', '));
    if (data.note) html += row('📝', `<span style="white-space:pre-wrap">${esc(data.note)}</span>`);
    if (data.overlap) html += `<div class="wp-pop-warn">⚠ Időbeli átfedés!</div>`;
    if (data.edit_id) html += `<a class="wp-pop-edit" href="/admin_task_edit.php?id=${data.edit_id}">✎ Szerkesztés</a>`;

    body.innerHTML = html;
    pop.classList.add('show');
    placePop(x, y);
  }

  function placePop(x, y) {
    pop.style.left = '0'; pop.style.top = '0'; // reset a mérethez
    const pw = pop.offsetWidth, ph = pop.offsetHeight;
    const vw = window.innerWidth,  vh = window.innerHeight;
    let left = x + 14, top = y + 14;
    if (left + pw > vw - 8) left = x - pw - 14;
    if (top  + ph > vh - 8) top  = y - ph - 14;
    pop.style.left = Math.max(8, left) + 'px';
    pop.style.top  = Math.max(8, top)  + 'px';
  }

  document.querySelectorAll('.tl-task').forEach(el => {
    el.addEventListener('click', function (e) {
      if (e.target.closest('.tl-edit-lnk')) return;
      e.stopPropagation();
      try {
        const data = JSON.parse(this.dataset.task || '{}');
        showPop(data, e.clientX, e.clientY);
      } catch (_) {}
    });
  });

  close.addEventListener('click', e => { e.stopPropagation(); pop.classList.remove('show'); });
  document.addEventListener('click', () => pop.classList.remove('show'));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') pop.classList.remove('show'); });
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>

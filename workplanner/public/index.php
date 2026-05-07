<?php
require_once __DIR__ . '/../app/auth.php';
require_login();

$page    = 'index';
$isAdmin = (current_user()['role'] ?? '') === 'admin';

$head_extra = '
  .tl-task { cursor: pointer; }
  .tl-task[draggable="true"] { cursor: grab; }
  .tl-task[draggable="true"]:active { cursor: grabbing; }
  .tl-task.dragging { opacity: 0.45; outline: 2px dashed #6c757d; }
  .day-cell.drag-over { background: #dbeafe !important; outline: 2px solid #3b82f6; }
  .wp-assign-item { display:flex; align-items:center; gap:8px; padding:7px 10px;
    cursor:pointer; border-bottom:1px solid #f2f2f2; font-size:.875rem; border-radius:4px; }
  .wp-assign-item:hover { background:#f8f9fa; }
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
  .wp-pop-edit { display: block; margin-top: 10px; font-size: .8rem;
    text-align: right; color: #0d6efd; text-decoration: none; }
  .wp-pop-edit:hover { text-decoration: underline; }
  .wp-pop-remove { display: block; margin-top: 6px; font-size: .8rem;
    text-align: right; color: #dc3545; text-decoration: none; cursor: pointer; }
  .wp-pop-remove:hover { text-decoration: underline; }
';
$today = date('Y-m-d');

$from = $_GET['from'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $today;

$days      = work_days($from, 5);
$tasks     = get_tasks_for_days($days);
$taskIdx   = index_tasks($tasks);
$employees = get_employees();
$empMap    = employees_map();
$myEmpId   = my_employee_id();

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
      <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('admin_tasks.php') ?>">Feladatbank</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('admin_task_history.php') ?>">Előzmények</a>
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
      <td class="day-cell<?= $isToday ? ' today-cell' : '' ?>"
          <?php if ($isAdmin): ?>data-date="<?= e($d) ?>" data-emp-id="<?= $eid ?>"<?php endif; ?>>
        <div class="day-cell-flex">
          <?php foreach ($cellTasks as $t):
            $isArchiv = ($t['status'] === 'archív');
            $sysKey   = $t['system_key'] ?? '';
            $sysCls   = match($sysKey) {
              'vacation'  => ' task-vacation',
              'sick_leave'=> ' task-sick',
              default     => '',
            };
            $emoji = match($sysKey) {
              'vacation'  => '🌴 ',
              'sick_leave'=> '🤒 ',
              default     => '',
            };
            $bg  = $isArchiv ? '#9ca3af' : e($t['color']);
            $fg  = $isArchiv ? '#ffffff'  : e(contrast_color($t['color']));
            $statusCls = match($t['status']) {
              'passzív' => ' task-passive',
              'vár'     => ' task-waiting',
              'archív'  => ' task-archived',
              default   => '',
            };
            $cls = 'tl-task' . $statusCls . $sysCls;
            $popData = htmlspecialchars(json_encode([
              'title'         => $emoji . $t['title'],
              'date'          => $t['task_date'],
              'status'        => $t['status'],
              'note'          => $t['note'],
              'assignment_id' => (int)$t['assignment_id'],
              'edit_id'       => ($isAdmin && !$sysKey) ? (int)$t['id'] : null,
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
          ?>
          <div class="<?= $cls ?>"
               style="background:<?= $bg ?>;color:<?= $fg ?>"
               data-task="<?= $popData ?>"
               <?php if ($isAdmin): ?>
                 draggable="true"
                 data-task-id="<?= (int)$t['id'] ?>"
                 data-assignment-id="<?= (int)$t['assignment_id'] ?>"
                 data-from-emp="<?= $eid ?>"
                 data-task-date="<?= e($t['task_date']) ?>"
               <?php endif; ?>>
            <span class="tl-task-title"><?= $emoji . e($t['title']) ?></span>
            <?php if ($isAdmin && !$sysKey): ?>
              <a href="<?= base_url('admin_task_edit.php?id='.(int)$t['id']) ?>"
                 class="tl-edit-lnk" title="Szerkesztés">✎</a>
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

<?php if ($isAdmin): ?>
<!-- Feladat hozzárendelő popup -->
<div class="wp-pop" id="wpAssignPop" style="min-width:270px;max-width:320px" role="dialog">
  <span class="wp-pop-close" id="wpAssignClose">&times;</span>
  <div class="wp-pop-title">Feladat hozzárendelése</div>
  <input id="wpAssignFilter" type="text" class="form-control form-control-sm mb-2" placeholder="Szűrés…" autocomplete="off">
  <div id="wpAssignList" style="max-height:220px;overflow-y:auto"></div>
</div>
<?php endif; ?>

<script>
let _wpDragOccurred = false;
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

  function placePop(popEl, x, y) {
    popEl.style.left = '0'; popEl.style.top = '0';
    const pw = popEl.offsetWidth, ph = popEl.offsetHeight;
    const vw = window.innerWidth,  vh = window.innerHeight;
    let left = x + 14, top = y + 14;
    if (left + pw > vw - 8) left = x - pw - 14;
    if (top  + ph > vh - 8) top  = y - ph - 14;
    popEl.style.left = Math.max(8, left) + 'px';
    popEl.style.top  = Math.max(8, top)  + 'px';
  }

  function showPop(data, x, y) {
    title.textContent = data.title;
    let html = '';
    if (data.date) html += row('📅', esc(data.date));
    if (data.status) html += row('🏷', esc(data.status));
    if (data.note)   html += row('📝', `<span style="white-space:pre-wrap">${esc(data.note)}</span>`);
    if (data.edit_id) html += `<a class="wp-pop-edit" href="${BASE_URL}admin_task_edit.php?id=${data.edit_id}">✎ Szerkesztés</a>`;
    if (data.assignment_id && typeof CSRF_TOKEN !== 'undefined') {
      html += `<a class="wp-pop-remove" data-aid="${data.assignment_id}">✕ Eltávolítás</a>`;
    }
    body.innerHTML = html;

    const removeLink = body.querySelector('.wp-pop-remove');
    if (removeLink) {
      removeLink.addEventListener('click', async () => {
        if (!confirm('Eltávolítod a feladatot ebből a cellából?')) return;
        const res  = await fetch('task_unassign.php', {
          method: 'POST',
          body: new URLSearchParams({ _csrf: CSRF_TOKEN, assignment_id: removeLink.dataset.aid })
        });
        const d = await res.json();
        if (d.ok) location.reload();
        else alert('Hiba: ' + (d.error ?? 'ismeretlen'));
      });
    }

    pop.classList.add('show');
    placePop(pop, x, y);
  }

  document.querySelectorAll('.tl-task').forEach(el => {
    el.addEventListener('click', function (e) {
      if (_wpDragOccurred) return;
      if (e.target.closest('.tl-edit-lnk')) return;
      e.stopPropagation();
      try {
        const data = JSON.parse(this.dataset.task || '{}');
        showPop(data, e.clientX, e.clientY);
      } catch (_) {}
    });
  });

  close.addEventListener('click', e => { e.stopPropagation(); pop.classList.remove('show'); });
  document.addEventListener('click', () => { pop.classList.remove('show'); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { pop.classList.remove('show'); } });
})();

<?php if ($isAdmin): ?>
const CSRF_TOKEN  = '<?= csrf_token() ?>';
const BASE_URL    = '<?= base_url() ?>';
const TASK_BANK   = <?= json_encode(get_tasks_bank(['aktív','passzív','vár','archív']), JSON_UNESCAPED_UNICODE) ?>;

(function () {
  // ── Feladatválasztó popup ────────────────────────────
  const aPop    = document.getElementById('wpAssignPop');
  const aClose  = document.getElementById('wpAssignClose');
  const aFilter = document.getElementById('wpAssignFilter');
  const aList   = document.getElementById('wpAssignList');
  let   aCell   = null;

  function placePop(popEl, x, y) {
    popEl.style.left = '0'; popEl.style.top = '0';
    const pw = popEl.offsetWidth, ph = popEl.offsetHeight;
    const vw = window.innerWidth,  vh = window.innerHeight;
    let left = x + 14, top = y + 14;
    if (left + pw > vw - 8) left = x - pw - 14;
    if (top  + ph > vh - 8) top  = y - ph - 14;
    popEl.style.left = Math.max(8, left) + 'px';
    popEl.style.top  = Math.max(8, top)  + 'px';
  }

  function renderTaskList(filter) {
    const q = filter.trim().toLowerCase();
    const items = TASK_BANK.filter(t => !q || t.title.toLowerCase().includes(q));
    if (!items.length) {
      aList.innerHTML = '<div class="text-muted small p-2">Nincs találat.</div>';
      return;
    }
    aList.innerHTML = items.map(t =>
      `<div class="wp-assign-item" data-id="${t.id}">
        <span style="display:inline-block;width:14px;height:14px;border-radius:3px;flex-shrink:0;background:${t.color}"></span>
        <span>${t.title.replace(/</g,'&lt;')}</span>
        <span class="ms-auto badge bg-${{'aktív':'success','passzív':'secondary','vár':'warning'}[t.status]||'secondary'} small">${t.status}</span>
      </div>`
    ).join('');

    aList.querySelectorAll('.wp-assign-item').forEach(el => {
      el.addEventListener('click', async () => {
        const body = new URLSearchParams({
          _csrf:       CSRF_TOKEN,
          task_id:     el.dataset.id,
          employee_id: aCell.dataset.empId,
          task_date:   aCell.dataset.date
        });
        const res  = await fetch('task_assign.php', { method: 'POST', body });
        const data = await res.json();
        if (data.ok) location.reload();
        else alert('Hiba: ' + (data.error ?? 'ismeretlen'));
      });
    });
  }

  aFilter.addEventListener('input', () => renderTaskList(aFilter.value));
  aClose.addEventListener('click', e => { e.stopPropagation(); aPop.classList.remove('show'); });

  document.querySelectorAll('td.day-cell').forEach(cell => {
    cell.addEventListener('click', e => {
      if (_wpDragOccurred) return;
      if (e.target.closest('.tl-task')) return;
      e.stopPropagation();
      aCell = cell;
      aFilter.value = '';
      renderTaskList('');
      aPop.classList.add('show');
      placePop(aPop, e.clientX, e.clientY);
      aFilter.focus();
    });
  });

  document.addEventListener('click', () => aPop.classList.remove('show'));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') aPop.classList.remove('show'); });

  // ── Drag-and-drop ────────────────────────────────────
  let dragState = null;

  document.addEventListener('keydown', e => { if ((e.key === 'Control' || e.key === 'Meta') && dragState) dragState.action = 'copy'; });
  document.addEventListener('keyup',   e => { if ((e.key === 'Control' || e.key === 'Meta') && dragState) dragState.action = 'move'; });

  document.querySelectorAll('.tl-task[draggable="true"]').forEach(el => {
    el.addEventListener('mousemove', e => {
      const rect   = el.getBoundingClientRect();
      const isCopy = (e.clientX - rect.left) < rect.width * 0.45;
      el.style.cursor = isCopy ? 'copy' : 'grab';
    });
    el.addEventListener('mouseleave', () => { el.style.cursor = ''; });

    el.addEventListener('dragstart', e => {
      _wpDragOccurred = true;
      const rect   = el.getBoundingClientRect();
      const isCopy = (e.clientX - rect.left) < rect.width * 0.45
                     || e.ctrlKey || e.metaKey;
      dragState = {
        taskId:   el.dataset.taskId,
        fromEmp:  el.dataset.fromEmp,
        fromDate: el.dataset.taskDate,
        action:   isCopy ? 'copy' : 'move'
      };
      e.dataTransfer.effectAllowed = isCopy ? 'copy' : 'move';
      e.dataTransfer.setData('text/plain', el.dataset.taskId);
      el.classList.add('dragging');
    });
    el.addEventListener('dragend', () => {
      el.classList.remove('dragging');
      dragState = null;
      setTimeout(() => { _wpDragOccurred = false; }, 0);
    });
  });

  document.querySelectorAll('td.day-cell').forEach(cell => {
    cell.addEventListener('dragover', e => {
      if (!dragState) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = dragState.action === 'copy' ? 'copy' : 'move';
      cell.classList.add('drag-over');
    });
    cell.addEventListener('dragleave', () => cell.classList.remove('drag-over'));
    cell.addEventListener('drop', async e => {
      e.preventDefault();
      cell.classList.remove('drag-over');
      if (!dragState) return;
      if (dragState.fromEmp === cell.dataset.empId && dragState.fromDate === cell.dataset.date) return;

      const body = new URLSearchParams({
        _csrf:     CSRF_TOKEN,
        action:    dragState.action,
        task_id:   dragState.taskId,
        from_emp:  dragState.fromEmp,
        from_date: dragState.fromDate,
        to_emp:    cell.dataset.empId,
        to_date:   cell.dataset.date
      });
      try {
        const res  = await fetch('task_move.php', { method: 'POST', body });
        const data = await res.json();
        if (data.ok) location.reload();
        else alert('Hiba: ' + (data.error ?? 'ismeretlen'));
      } catch {
        alert('Hálózati hiba.');
      }
    });
  });
})();
<?php endif; ?>
</script>

<?php require __DIR__ . '/_footer.php'; ?>

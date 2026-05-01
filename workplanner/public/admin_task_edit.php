<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();

$page = 'admin_tasks';
$id   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

$task = null;
$assigned_ids = [];
if ($id) {
  $task = db()->prepare("SELECT t.*, l.name AS location_name FROM tasks t LEFT JOIN locations l ON l.id=t.location_id WHERE t.id=?")->execute([$id]) ? null : null;
  $st = db()->prepare("SELECT t.*, l.name AS location_name FROM tasks t LEFT JOIN locations l ON l.id=t.location_id WHERE t.id=?");
  $st->execute([$id]);
  $task = $st->fetch();
  if (!$task) { flash_set('err','Feladat nem található.'); redirect('admin_tasks.php'); }
  $asgn = db()->prepare("SELECT employee_id FROM task_assignments WHERE task_id=?");
  $asgn->execute([$id]);
  $assigned_ids = array_column($asgn->fetchAll(), 'employee_id');
}

$employees = get_employees();
$locations = get_locations();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();

  $title     = trim((string)($_POST['title'] ?? ''));
  $loc_name  = trim((string)($_POST['location_name'] ?? ''));
  $task_date = trim((string)($_POST['task_date'] ?? ''));
  $time_from = ($_POST['time_from'] ?? '') !== '' ? (string)$_POST['time_from'] : null;
  $time_to   = ($_POST['time_to']   ?? '') !== '' ? (string)$_POST['time_to']   : null;
  $color     = preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#0d6efd';
  $note      = trim((string)($_POST['note'] ?? ''));
  $emp_ids   = array_map('intval', (array)($_POST['employee_ids'] ?? []));
  $emp_ids   = array_filter($emp_ids);

  if ($title === '') $err = 'A megnevezés kötelező.';
  elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $task_date)) $err = 'Érvényes dátum szükséges.';

  // Átfedés ellenőrzés (egész napos = 00:00–23:59 kezelt)
  $overlapWarning = [];
  if (!$err && $emp_ids && empty($_POST['force'])) {
    $newFrom = $time_from ?? '00:00';
    $newTo   = $time_to   ?? '23:59';
    $ph = implode(',', array_fill(0, count($emp_ids), '?'));
    $st = db()->prepare("
      SELECT t.id, t.title, t.time_from, t.time_to,
             GROUP_CONCAT(DISTINCT e.full_name ORDER BY e.full_name SEPARATOR ', ') AS emp_names
      FROM tasks t
      JOIN task_assignments ta ON ta.task_id = t.id AND ta.employee_id IN ($ph)
      JOIN hr.employees e ON e.id = ta.employee_id
      WHERE t.task_date = ? AND t.id != ?
        AND COALESCE(t.time_from, '00:00') < ?
        AND COALESCE(t.time_to,   '23:59') > ?
      GROUP BY t.id ORDER BY t.time_from
    ");
    $st->execute([...$emp_ids, $task_date, $id ?? 0, $newTo, $newFrom]);
    $overlapWarning = $st->fetchAll();
  }

  if (!$err && !$overlapWarning) {
    // Helyszín kezelés: mentés/frissítés
    $location_id = null;
    if ($loc_name !== '') {
      $st = db()->prepare("SELECT id, color FROM locations WHERE name=?");
      $st->execute([$loc_name]);
      $loc = $st->fetch();
      if ($loc) {
        $location_id = (int)$loc['id'];
        // Ha a szín változott, frissítjük a helyszínt is
        if ($loc['color'] !== $color) {
          db()->prepare("UPDATE locations SET color=? WHERE id=?")->execute([$color, $location_id]);
        }
        db()->prepare("UPDATE locations SET use_count=use_count+1 WHERE id=?")->execute([$location_id]);
      } else {
        db()->prepare("INSERT INTO locations (name, color, use_count) VALUES (?,?,1)")->execute([$loc_name, $color]);
        $location_id = (int)db()->lastInsertId();
      }
    }

    $uid = (int)(current_user()['id'] ?? 0);

    if ($id) {
      db()->prepare("UPDATE tasks SET title=?,location_id=?,task_date=?,time_from=?,time_to=?,color=?,note=? WHERE id=?")
         ->execute([$title, $location_id, $task_date, $time_from, $time_to, $color, $note ?: null, $id]);
      db()->prepare("DELETE FROM task_assignments WHERE task_id=?")->execute([$id]);
      audit('task_update', 'task', $id, ['title'=>$title,'date'=>$task_date]);
    } else {
      db()->prepare("INSERT INTO tasks (title,location_id,task_date,time_from,time_to,color,note,created_by) VALUES (?,?,?,?,?,?,?,?)")
         ->execute([$title, $location_id, $task_date, $time_from, $time_to, $color, $note ?: null, $uid]);
      $id = (int)db()->lastInsertId();
      audit('task_create', 'task', $id, ['title'=>$title,'date'=>$task_date]);
    }

    // Hozzárendelések
    $ins = db()->prepare("INSERT IGNORE INTO task_assignments (task_id, employee_id) VALUES (?,?)");
    foreach ($emp_ids as $eid) $ins->execute([$id, $eid]);

    touch_last_modified();

    // Email küldés ha kérték
    if (!empty($_POST['send_email']) && $emp_ids) {
      send_task_notification($id, $emp_ids);
    }

    flash_set('ok', 'Feladat mentve.');
    redirect('admin_tasks.php');
  } // !$err && !$overlapWarning
}

// Negyedórás időpontok
$time_options = [];
for ($h = 0; $h < 24; $h++) {
  for ($m = 0; $m < 60; $m += 15) {
    $time_options[] = sprintf('%02d:%02d', $h, $m);
  }
}

// Következő szín ha új helyszín
$next_color = next_location_color();

$title_val     = $task['title'] ?? '';
$loc_name_val  = $task['location_name'] ?? '';
$date_val      = $task['task_date'] ?? date('Y-m-d');
$time_from_val = $task['time_from'] ? substr($task['time_from'],0,5) : '';
$time_to_val   = $task['time_to']   ? substr($task['time_to'],0,5)   : '';
$color_val     = $task['color'] ?? '#3b82f6';
$note_val      = $task['note'] ?? '';

require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?= $task ? 'Feladat szerkesztése' : 'Új feladat' ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= base_url('admin_tasks.php') ?>">← Vissza</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<?php if (!empty($overlapWarning)): ?>
<div class="alert alert-warning" style="max-width:780px">
  <strong>⚠ Időbeli átfedés!</strong>
  Az alábbi feladatok ütköznek az itt megadott időintervallumal:
  <ul class="mb-2 mt-1">
    <?php foreach ($overlapWarning as $ow): ?>
    <li>
      <strong><?= e($ow['title']) ?></strong>
      <?php if ($ow['time_from']): ?>
        (<?= e(fmt_time($ow['time_from'])) ?>–<?= e($ow['time_to'] ? fmt_time($ow['time_to']) : '…') ?>)
      <?php else: ?>
        <em>(egész napos)</em>
      <?php endif; ?>
      — <?= e($ow['emp_names']) ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <form method="post" class="d-inline">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="force" value="1">
    <input type="hidden" name="title"         value="<?= e($title) ?>">
    <input type="hidden" name="location_name" value="<?= e($loc_name) ?>">
    <input type="hidden" name="task_date"     value="<?= e($task_date) ?>">
    <input type="hidden" name="time_from"     value="<?= e($time_from ?? '') ?>">
    <input type="hidden" name="time_to"       value="<?= e($time_to ?? '') ?>">
    <input type="hidden" name="color"         value="<?= e($color) ?>">
    <input type="hidden" name="note"          value="<?= e($note) ?>">
    <?php foreach ($emp_ids as $eid): ?>
      <input type="hidden" name="employee_ids[]" value="<?= (int)$eid ?>">
    <?php endforeach; ?>
    <?php if (!empty($_POST['send_email'])): ?>
      <input type="hidden" name="send_email" value="1">
    <?php endif; ?>
    <button type="submit" class="btn btn-warning btn-sm">Mentés mindenképp</button>
  </form>
  <span class="ms-2 text-muted small">vagy módosítsd a fenti adatokat és mentsd újra.</span>
</div>
<?php endif; ?>

<div class="card" style="max-width:780px">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="row g-3">

        <div class="col-12">
          <label class="form-label fw-semibold">Feladat megnevezése <span class="text-danger">*</span></label>
          <input class="form-control" name="title" value="<?= e($title_val) ?>" required autofocus>
        </div>

        <div class="col-md-8">
          <label class="form-label fw-semibold">Helyszín / munkavégzés</label>
          <div class="position-relative">
            <input class="form-control" name="location_name" id="loc-input"
              value="<?= e($loc_name_val) ?>" autocomplete="off"
              placeholder="Írj be vagy válassz a listából…">
            <div id="loc-ac" style="position:absolute;z-index:100;background:#fff;border:1px solid #ced4da;border-radius:0 0 6px 6px;width:100%;display:none;max-height:200px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.1)"></div>
          </div>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold">Szín</label>
          <div class="input-group">
            <input type="color" class="form-control form-control-color" name="color" id="color-input" value="<?= e($color_val) ?>">
            <span class="input-group-text small" id="color-preview" style="background:<?= e($color_val) ?>;min-width:70px;color:<?= e(contrast_color($color_val)) ?>">Szín</span>
          </div>
          <div class="form-text">Helyszín választásakor automatikusan ajánlt.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold">Dátum <span class="text-danger">*</span></label>
          <input type="date" class="form-control" name="task_date" value="<?= e($date_val) ?>" required>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold">Kezdés</label>
          <select class="form-select" name="time_from">
            <option value="">— egész napos —</option>
            <?php foreach ($time_options as $t): ?>
              <option value="<?= $t ?>" <?= $t === $time_from_val ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold">Befejezés</label>
          <select class="form-select" name="time_to">
            <option value="">—</option>
            <?php foreach ($time_options as $t): ?>
              <option value="<?= $t ?>" <?= $t === $time_to_val ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold">Dolgozók <span class="text-danger">*</span></label>
          <div class="row g-1" style="max-height:280px;overflow-y:auto;border:1px solid #dee2e6;border-radius:6px;padding:8px">
            <?php foreach ($employees as $emp): ?>
            <div class="col-md-4 col-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="employee_ids[]"
                  value="<?= (int)$emp['id'] ?>" id="emp_<?= (int)$emp['id'] ?>"
                  <?= in_array((int)$emp['id'], array_map('intval',$assigned_ids)) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="emp_<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="mt-1 d-flex gap-2">
            <button type="button" class="btn btn-link btn-sm p-0" onclick="document.querySelectorAll('[name=\'employee_ids[]\']').forEach(c=>c.checked=true)">Mind kijelöl</button>
            <button type="button" class="btn btn-link btn-sm p-0 text-muted" onclick="document.querySelectorAll('[name=\'employee_ids[]\']').forEach(c=>c.checked=false)">Törlés</button>
          </div>
        </div>

        <div class="col-12">
          <label class="form-label">Megjegyzés</label>
          <textarea class="form-control" name="note" rows="2"><?= e($note_val) ?></textarea>
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="send_email" value="1" id="sendEmail">
            <label class="form-check-label" for="sendEmail">Email értesítés küldése az érintett dolgozóknak</label>
          </div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Mentés</button>
          <?php if ($task): ?>
            <a class="btn btn-outline-danger" href="admin_task_delete.php?id=<?= (int)$id ?>&_csrf=<?= csrf_token() ?>"
               onclick="return confirm('Törlöd a feladatot?')">Törlés</a>
          <?php endif; ?>
          <a class="btn btn-outline-secondary" href="<?= base_url('admin_tasks.php') ?>">Mégsem</a>
        </div>

      </div>
    </form>
  </div>
</div>

<?php
$locs_json = json_encode(array_map(fn($l) => ['name'=>$l['name'],'color'=>$l['color']], $locations), JSON_UNESCAPED_UNICODE);
$next_color_js = e($next_color);
?>
<script>
const LOCATIONS = <?= $locs_json ?>;
const NEXT_COLOR = '<?= $next_color_js ?>';

const locInput  = document.getElementById('loc-input');
const locAc     = document.getElementById('loc-ac');
const colorInput = document.getElementById('color-input');
const colorPreview = document.getElementById('color-preview');

function updateColorPreview(hex) {
  colorPreview.style.background = hex;
  const r=parseInt(hex.slice(1,3),16),g=parseInt(hex.slice(3,5),16),b=parseInt(hex.slice(5,7),16);
  const lum=(0.299*r+0.587*g+0.114*b)/255;
  colorPreview.style.color = lum>0.55?'#1a1a1a':'#ffffff';
}

colorInput.addEventListener('input', () => updateColorPreview(colorInput.value));
updateColorPreview(colorInput.value);

locInput.addEventListener('input', function() {
  const q = this.value.trim().toLowerCase();
  const matches = LOCATIONS.filter(l => l.name.toLowerCase().includes(q)).slice(0, 10);
  if (!matches.length || !q) { locAc.style.display = 'none'; return; }
  locAc.innerHTML = '';
  matches.forEach(l => {
    const div = document.createElement('div');
    div.style.cssText = 'padding:7px 12px;cursor:pointer;border-bottom:1px solid #f2f2f2;font-size:.9rem';
    div.innerHTML = `<span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:${l.color};margin-right:6px;vertical-align:middle"></span>${l.name.replace(/</g,'&lt;')}`;
    // mousedown: megakadályozza, hogy az input elveszítse a fókuszt
    div.addEventListener('mousedown', e => e.preventDefault());
    div.addEventListener('click', () => setLoc(l.name, l.color));
    locAc.appendChild(div);
  });
  locAc.style.display = 'block';
});

locInput.addEventListener('blur', () => { locAc.style.display = 'none'; });

locInput.addEventListener('change', function() {
  // Ha nincs mentett helyszín → next_color javasolt
  const found = LOCATIONS.find(l => l.name.toLowerCase() === this.value.trim().toLowerCase());
  if (!found && this.value.trim()) {
    colorInput.value = NEXT_COLOR;
    updateColorPreview(NEXT_COLOR);
  }
});

function setLoc(name, color) {
  locInput.value = name;
  colorInput.value = color;
  updateColorPreview(color);
  locAc.style.display = 'none';
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>

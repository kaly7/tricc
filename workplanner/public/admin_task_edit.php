<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();

$page = 'admin_tasks';
$id   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

$task = null;
if ($id) {
  $st = db()->prepare("SELECT id, title, status, color, note FROM tasks WHERE id=?");
  $st->execute([$id]);
  $task = $st->fetch();
  if (!$task) { flash_set('err','Feladat nem található.'); redirect('admin_tasks.php'); }
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();

  $title  = trim((string)($_POST['title']  ?? ''));
  $status = (string)($_POST['status'] ?? 'aktív');
  $color  = preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#0d6efd';
  $note   = trim((string)($_POST['note'] ?? ''));

  $validStatuses = ['aktív','passzív','vár','archív'];
  if ($title === '') $err = 'A megnevezés kötelező.';
  elseif (!in_array($status, $validStatuses, true)) $err = 'Érvénytelen státusz.';

  if (!$err) {
    $uid = (int)(current_user()['id'] ?? 0);
    if ($id) {
      db()->prepare("UPDATE tasks SET title=?, status=?, color=?, note=?, updated_at=NOW() WHERE id=?")
         ->execute([$title, $status, $color, $note ?: null, $id]);
      audit('task_update', 'task', $id, ['title'=>$title,'status'=>$status]);
    } else {
      db()->prepare("INSERT INTO tasks (title, status, color, note, created_by) VALUES (?,?,?,?,?)")
         ->execute([$title, $status, $color, $note ?: null, $uid]);
      $id = (int)db()->lastInsertId();
      audit('task_create', 'task', $id, ['title'=>$title,'status'=>$status]);
    }
    flash_set('ok', 'Feladat mentve.');
    redirect('admin_tasks.php');
  }
}

$title_val  = $task['title']  ?? '';
$status_val = $task['status'] ?? 'aktív';
$color_val  = $task['color']  ?? next_task_color();
$note_val   = $task['note']   ?? '';

require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?= $task ? 'Feladat szerkesztése' : 'Új feladat' ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= base_url('admin_tasks.php') ?>">← Vissza</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="card" style="max-width:560px">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="row g-3">

        <div class="col-12">
          <label class="form-label fw-semibold">Feladat neve <span class="text-danger">*</span></label>
          <input class="form-control" name="title" value="<?= e($title_val) ?>" required autofocus>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-semibold">Státusz</label>
          <select class="form-select" name="status">
            <?php foreach (['aktív','passzív','vár','archív'] as $s): ?>
              <option value="<?= $s ?>" <?= $status_val === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-semibold">Szín</label>
          <div class="input-group">
            <input type="color" class="form-control form-control-color" name="color" id="color-input" value="<?= e($color_val) ?>">
            <span class="input-group-text small" id="color-preview"
                  style="background:<?= e($color_val) ?>;min-width:70px;color:<?= e(contrast_color($color_val)) ?>">Szín</span>
          </div>
        </div>

        <div class="col-12">
          <label class="form-label">Megjegyzés</label>
          <textarea class="form-control" name="note" rows="3"><?= e($note_val) ?></textarea>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Mentés</button>
          <?php if ($task): ?>
            <a class="btn btn-outline-danger"
               href="<?= base_url('admin_task_delete.php?id='.(int)$id.'&_csrf='.csrf_token()) ?>"
               onclick="return confirm('Törlöd a feladatot? A hozzárendelések is elvesznek.')">Törlés</a>
          <?php endif; ?>
          <a class="btn btn-outline-secondary" href="<?= base_url('admin_tasks.php') ?>">Mégsem</a>
        </div>

      </div>
    </form>
  </div>
</div>

<script>
const colorInput   = document.getElementById('color-input');
const colorPreview = document.getElementById('color-preview');
function updateColorPreview(hex) {
  colorPreview.style.background = hex;
  const r=parseInt(hex.slice(1,3),16), g=parseInt(hex.slice(3,5),16), b=parseInt(hex.slice(5,7),16);
  colorPreview.style.color = ((0.299*r+0.587*g+0.114*b)/255) > 0.55 ? '#1a1a1a' : '#ffffff';
}
colorInput.addEventListener('input', () => updateColorPreview(colorInput.value));
updateColorPreview(colorInput.value);
</script>

<?php require __DIR__ . '/_footer.php'; ?>

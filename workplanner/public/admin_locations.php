<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();
$page = 'admin_locations';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $lid   = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
  $name  = trim((string)($_POST['name'] ?? ''));
  $color = preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#6c757d';
  if ($lid && $name) {
    db()->prepare("UPDATE locations SET name=?, color=? WHERE id=?")->execute([$name, $color, $lid]);
    // Frissítsük a feladatok színét is ha az admin akarja
    if (!empty($_POST['update_tasks'])) {
      db()->prepare("UPDATE tasks SET color=? WHERE location_id=?")->execute([$color, $lid]);
    }
    flash_set('ok','Helyszín mentve.');
  }
  redirect('admin_locations.php');
}

$locations = db()->query("SELECT id, name, color, use_count FROM locations ORDER BY use_count DESC, name")->fetchAll();
require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Helyszínek &amp; színek</h1>
</div>

<?php if (!$locations): ?>
  <div class="alert alert-info">Még nincs mentett helyszín. Feladat létrehozásakor automatikusan kerülnek ide.</div>
<?php else: ?>
<div class="card" style="max-width:700px">
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-dark"><tr><th>Szín</th><th>Helyszín neve</th><th>Használat</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($locations as $l): ?>
        <tr>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <td>
              <input type="color" name="color" value="<?= e($l['color']) ?>"
                class="form-control form-control-color p-0 border-0" style="width:36px;height:30px">
            </td>
            <td><input type="text" name="name" class="form-control form-control-sm" value="<?= e($l['name']) ?>"></td>
            <td class="text-muted small"><?= (int)$l['use_count'] ?> feladat</td>
            <td class="text-end">
              <label class="form-check-label small me-2 text-muted">
                <input type="checkbox" name="update_tasks" value="1" class="form-check-input me-1">meglévő feladatokat is
              </label>
              <button class="btn btn-sm btn-outline-primary">Mentés</button>
            </td>
          </form>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

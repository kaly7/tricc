<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();
$page = 'admin_employees';

// POST: hozzáadás / törlés / sorrend mentés
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();

  if (isset($_POST['add_id'])) {
    $eid = filter_input(INPUT_POST, 'add_id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($eid) {
      $maxSort = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM wp_employees")->fetchColumn();
      db()->prepare("INSERT IGNORE INTO wp_employees (employee_id, sort_order) VALUES (?,?)")
         ->execute([$eid, $maxSort + 10]);
    }
  } elseif (isset($_POST['remove_id'])) {
    $eid = filter_input(INPUT_POST, 'remove_id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($eid) {
      db()->prepare("DELETE FROM wp_employees WHERE employee_id=?")->execute([$eid]);
    }
  } elseif (isset($_POST['sort_order']) && is_array($_POST['sort_order'])) {
    $upd = db()->prepare("UPDATE wp_employees SET sort_order=? WHERE employee_id=?");
    foreach ($_POST['sort_order'] as $eid => $ord) {
      $upd->execute([(int)$ord, (int)$eid]);
    }
    flash_set('ok', 'Sorrend mentve.');
  }
  redirect('admin_employees.php');
}

// Workplannerbe felvett dolgozók (sort_order szerint)
$wpEmps = db()->query("SELECT employee_id, sort_order FROM wp_employees ORDER BY sort_order, employee_id")->fetchAll();
$wpIds  = array_column($wpEmps, 'employee_id');

// Összes HR dolgozó (akiket még lehet felvenni)
$allHr  = get_all_hr_employees();
$available = array_filter($allHr, fn($e) => !in_array((int)$e['id'], array_map('intval', $wpIds)));

// Nevek map a felvettekhez
$hrMap = [];
foreach ($allHr as $e) $hrMap[(int)$e['id']] = $e;

require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Dolgozók a tervben</h1>
</div>

<div class="row g-4">

  <!-- Felvett dolgozók -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Aktív dolgozók (<?= count($wpEmps) ?>)</strong>
        <span class="text-muted small">Sorrendet húzással vagy számmal adhatod meg</span>
      </div>
      <?php if (!$wpEmps): ?>
        <div class="card-body text-muted">Még nincs felvett dolgozó. Adj hozzá a jobb oldali listából.</div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <ul class="list-group list-group-flush" id="sort-list">
          <?php foreach ($wpEmps as $i => $we):
            $emp = $hrMap[(int)$we['employee_id']] ?? null;
            if (!$emp) continue;
          ?>
          <li class="list-group-item d-flex align-items-center gap-2 py-2" data-id="<?= (int)$we['employee_id'] ?>">
            <span class="text-muted" style="cursor:grab">☰</span>
            <span class="flex-grow-1">
              <strong><?= e($emp['full_name']) ?></strong>
              <?php if ($emp['company_division']): ?>
                <small class="text-muted ms-1"><?= e($emp['company_division']) ?></small>
              <?php endif; ?>
            </span>
            <input type="number" name="sort_order[<?= (int)$we['employee_id'] ?>]"
              value="<?= (int)$we['sort_order'] ?>"
              class="form-control form-control-sm text-center sort-input"
              style="width:65px" min="0" max="9999">
            <button type="submit" name="remove_id" value="<?= (int)$we['employee_id'] ?>"
              class="btn btn-sm btn-outline-danger"
              onclick="return confirm('Kiveszed <?= e(addslashes($emp['full_name'])) ?>-t a tervből?')"
              formnovalidate>✕</button>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="card-footer">
          <button class="btn btn-sm btn-outline-primary" type="submit">Sorrend mentése</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Hozzáadható dolgozók -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">
        <strong>Hozzáadható HR dolgozók</strong>
        <input type="text" id="filter-input" class="form-control form-control-sm mt-2"
          placeholder="🔍 Szűrés névre…">
      </div>
      <div style="max-height:480px;overflow-y:auto">
        <?php if (!$available): ?>
          <div class="p-3 text-muted">Mindenki fel van véve.</div>
        <?php else: ?>
        <ul class="list-group list-group-flush" id="avail-list">
          <?php foreach ($available as $emp): ?>
          <li class="list-group-item d-flex align-items-center justify-content-between py-2 avail-item">
            <span>
              <strong><?= e($emp['full_name']) ?></strong>
              <?php if ($emp['company_division']): ?>
                <small class="text-muted ms-1"><?= e($emp['company_division']) ?></small>
              <?php endif; ?>
            </span>
            <form method="post" class="ms-2">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <button class="btn btn-sm btn-outline-success" name="add_id" value="<?= (int)$emp['id'] ?>">+ Hozzáad</button>
            </form>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script>
// Szűrő a hozzáadható listán
document.getElementById('filter-input')?.addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.avail-item').forEach(li => {
    li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
});
</script>

<?php require __DIR__ . '/_footer.php'; ?>

<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
require_admin();
$u   = current_user();
$pdo = db();

// POST: jármű kiosztása
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $vehicleId  = (int)($_POST['vehicle_id'] ?? 0);
  $employeeId = (int)($_POST['employee_id'] ?? 0);

  if ($vehicleId <= 0 || $employeeId <= 0) {
    flash_set('err', 'Hiányzó adatok.'); redirect('admin_vehicles.php');
  }

  // Jármű szabad-e?
  $existing = get_active_assignment($vehicleId);
  if ($existing) {
    flash_set('err', 'Ez a jármű jelenleg ' . e(employee_name((int)$existing['employee_id'])) . ' nyilvántartásában van. Előbb vissza kell adni.'); redirect('admin_vehicles.php');
  }

  try {
    $pdo->prepare("INSERT INTO vehicle_assignments (vehicle_id, employee_id, assigned_by_user_id) VALUES (?,?,?)")
        ->execute([$vehicleId, $employeeId, (int)$u['id']]);
    $newId = (int)$pdo->lastInsertId();
    audit('vehicle_assigned', 'vehicle_assignments', $newId, ['vehicle_id' => $vehicleId, 'employee_id' => $employeeId]);
    flash_set('ok', 'Jármű sikeresen kiosztva.');
  } catch (Throwable $e) {
    flash_set('err', 'Hiba: ' . $e->getMessage());
  }
  redirect('admin_vehicles.php');
}

// Összes aktív jármű + ki használja
$vehicles  = get_all_vehicles();
$employees = get_all_employees();

// Aktív hozzárendelések map: vehicle_id => assignment
$assignMap = [];
try {
  $rows = $pdo->query("SELECT * FROM vehicle_assignments WHERE status='active'")->fetchAll();
  foreach ($rows as $r) $assignMap[(int)$r['vehicle_id']] = $r;
} catch (Throwable $e) {}

$title = 'Járművek & kiosztás';
$page  = 'admin_vehicles';
require '_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Járművek &amp; kiosztás</h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#assignModal">+ Kiosztás</button>
</div>

<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead class="table-dark">
      <tr>
        <th>Rendszám</th>
        <th>Jármű</th>
        <th>Azonosító</th>
        <th>Jelenlegi használó</th>
        <th>Kiosztva</th>
        <th>Checklist sablon</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($vehicles as $v):
      $asgn = $assignMap[(int)$v['id']] ?? null;
      $tplCount = count(get_checklist_template((int)$v['id']));
    ?>
      <tr>
        <td>
          <a href="<?= e(base_url('admin_vehicle_history.php?vehicle_id=' . $v['id'])) ?>" class="text-decoration-none">
            <span class="plate" style="font-size:.95rem"><?= e($v['license_plate'] ?? '–') ?></span>
          </a>
        </td>
        <td>
          <a href="<?= e(base_url('admin_vehicle_history.php?vehicle_id=' . $v['id'])) ?>" class="text-decoration-none text-dark">
            <?= e($v['make'] . ' ' . $v['model']) ?>
          </a>
          <?= $v['archived'] ? ' <span class="badge bg-secondary">archivált</span>' : '' ?>
        </td>
        <td class="text-muted small"><?= e($v['vehicle_identifier'] ?? '') ?></td>
        <td>
          <?php if ($asgn): ?>
            <a href="<?= e(base_url('admin_employee_history.php?employee_id=' . $asgn['employee_id'])) ?>" class="text-decoration-none fw-bold">
              <?= e(employee_name((int)$asgn['employee_id'])) ?>
            </a>
          <?php else: ?>
            <span class="text-muted">– szabad –</span>
          <?php endif; ?>
        </td>
        <td class="text-muted small">
          <?= $asgn ? e(date('Y.m.d', strtotime($asgn['assigned_at']))) : '' ?>
        </td>
        <td>
          <?php if ($tplCount > 0): ?>
            <span class="badge bg-success"><?= $tplCount ?> tétel</span>
          <?php else: ?>
            <span class="badge bg-warning text-dark">Nincs sablon</span>
          <?php endif; ?>
          <a href="<?= e(base_url('admin_checklist_template.php?vehicle_id=' . $v['id'])) ?>" class="btn btn-outline-secondary btn-sm ms-1">✏ Sablon</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Kiosztás modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <div class="modal-header">
          <h5 class="modal-title">Jármű kiosztása</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Jármű</label>
            <select name="vehicle_id" class="form-select" required>
              <option value="">– Válassz járművet –</option>
              <?php foreach ($vehicles as $v): if ($v['archived']) continue; ?>
                <option value="<?= (int)$v['id'] ?>" <?= isset($assignMap[(int)$v['id']]) ? 'class="text-muted"' : '' ?>>
                  <?= e(vehicle_label($v)) ?>
                  <?= isset($assignMap[(int)$v['id']]) ? ' [foglalt]' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Dolgozó</label>
            <select name="employee_id" class="form-select" required>
              <option value="">– Válassz dolgozót –</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégsem</button>
          <button type="submit" class="btn btn-primary">Kiosztás</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require '_footer.php'; ?>

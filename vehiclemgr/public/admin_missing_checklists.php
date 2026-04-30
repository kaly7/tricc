<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
require_admin();
$pdo = db();

// Aktív hozzárendelések ahol ma nincs daily checklist
$missing = [];
try {
  $rows = $pdo->query("SELECT va.* FROM vehicle_assignments va WHERE va.status='active'")->fetchAll();
  foreach ($rows as $asgn) {
    $hasTpl = count(get_checklist_template((int)$asgn['vehicle_id'])) > 0;
    if (!$hasTpl) continue; // sablont nem definiált járműnél nem jelezzük
    if (!has_daily_checklist_today((int)$asgn['id'])) {
      $veh = get_vehicle((int)$asgn['vehicle_id']);
      $missing[] = ['assignment' => $asgn, 'vehicle' => $veh];
    }
  }
} catch (Throwable $e) {}

// Hiányzó checklistek az elmúlt 7 napra – per jármű
$history = [];
try {
  $st = $pdo->query("
    SELECT va.vehicle_id, va.employee_id, dates.d
    FROM vehicle_assignments va
    JOIN (
      SELECT CURDATE() - INTERVAL n DAY AS d FROM
        (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) t
    ) dates ON 1=1
    WHERE va.status='active'
    AND NOT EXISTS (
      SELECT 1 FROM checklist_submissions cs
      WHERE cs.assignment_id=va.id AND cs.type='daily' AND DATE(cs.submitted_at)=dates.d
    )
    AND EXISTS (
      SELECT 1 FROM checklist_templates ct WHERE ct.vehicle_id=va.vehicle_id AND ct.is_active=1
    )
    ORDER BY va.vehicle_id, dates.d DESC
  ");
  $history = $st->fetchAll();
} catch (Throwable $e) {}

$title = 'Hiányzó checklistek';
$page  = 'admin_missing';
require '_header.php';
?>

<h4 class="mb-3">Hiányzó checklistek</h4>

<!-- Mai hiányzók -->
<h5>Ma hiányzó (<?= date('Y.m.d') ?>)</h5>
<?php if (empty($missing)): ?>
  <div class="alert alert-success">✓ Ma mindenki kitöltötte a checklistet.</div>
<?php else: ?>
  <div class="table-responsive mb-4">
    <table class="table table-hover align-middle">
      <thead class="table-warning"><tr><th>Jármű</th><th>Használó</th><th>Kiosztva</th></tr></thead>
      <tbody>
      <?php foreach ($missing as $item):
        $asgn = $item['assignment']; $veh = $item['vehicle'];
      ?>
        <tr>
          <td><?php if ($veh): ?><span class="plate" style="font-size:.9rem"><?= e($veh['license_plate'] ?? '–') ?></span> <?= e($veh['make'] . ' ' . $veh['model']) ?><?php else: ?>–<?php endif; ?></td>
          <td><?= e(employee_name((int)$asgn['employee_id'])) ?></td>
          <td class="text-muted small"><?= e(date('Y.m.d', strtotime($asgn['assigned_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- Elmúlt 7 nap hiányzói -->
<?php if (!empty($history)): ?>
  <h5>Hiányzók – elmúlt 7 nap</h5>
  <div class="table-responsive">
    <table class="table table-sm table-hover">
      <thead class="table-light"><tr><th>Dátum</th><th>Jármű</th><th>Dolgozó</th></tr></thead>
      <tbody>
      <?php foreach ($history as $row):
        $veh = get_vehicle((int)$row['vehicle_id']);
      ?>
        <tr>
          <td><?= e($row['d']) ?></td>
          <td><?= $veh ? '<span class="plate" style="font-size:.85rem">' . e($veh['license_plate']) . '</span> ' . e($veh['make'] . ' ' . $veh['model']) : "#{$row['vehicle_id']}" ?></td>
          <td><?= e(employee_name((int)$row['employee_id'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require '_footer.php'; ?>

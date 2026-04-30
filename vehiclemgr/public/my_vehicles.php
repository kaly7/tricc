<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
$u       = current_user();
$isAdmin = ($u['role'] ?? '') === 'admin';
$myEmpId = my_employee_id();

$title = 'Járműveim';
$page  = 'my_vehicles';

// Saját aktív hozzárendelések
$assignments = get_employee_assignments($myEmpId);

// Hozzájuk a jármű adatok + checklist státusz
$items = [];
foreach ($assignments as $asgn) {
  $v = get_vehicle((int)$asgn['vehicle_id']);
  if (!$v) continue;
  $hasDaily = has_daily_checklist_today((int)$asgn['id']);
  // Van-e függő transfer ebből a hozzárendelésből
  $pendingTransfer = null;
  try {
    $st = db()->prepare("SELECT * FROM vehicle_transfers WHERE assignment_id=? AND status='pending' LIMIT 1");
    $st->execute([$asgn['id']]);
    $pendingTransfer = $st->fetch() ?: null;
  } catch (Throwable $e) {}
  $items[] = ['assignment' => $asgn, 'vehicle' => $v, 'has_daily' => $hasDaily, 'pending_transfer' => $pendingTransfer];
}

require '_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Nálam lévő járművek</h4>
</div>

<?php if (empty($items)): ?>
  <div class="alert alert-info">Jelenleg nincs hozzád rendelt jármű.</div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($items as $item):
    $asgn  = $item['assignment'];
    $v     = $item['vehicle'];
    $hasD  = $item['has_daily'];
    $pt    = $item['pending_transfer'];
  ?>
    <div class="col-12 col-md-6 col-lg-4">
      <div class="card vehicle-card h-100 <?= !$hasD ? 'border-danger' : 'border-success' ?>">
        <div class="card-body">
          <div class="mb-2">
            <span class="plate"><?= e($v['license_plate'] ?? '–') ?></span>
          </div>
          <div class="fw-bold"><?= e($v['make'] . ' ' . $v['model']) ?></div>
          <?php if (!empty($v['vehicle_identifier'])): ?>
            <div class="text-muted small"><?= e($v['vehicle_identifier']) ?></div>
          <?php endif; ?>
          <div class="text-muted small mt-1">Km-óra: <?= number_format((int)$v['odometer_km'], 0, '.', ' ') ?> km</div>
          <div class="mt-2">
            <?php if (!$hasD): ?>
              <span class="badge bg-danger">⚠ Mai checklist hiányzik</span>
            <?php else: ?>
              <span class="badge bg-success">✓ Mai checklist kész</span>
            <?php endif; ?>
          </div>

          <?php if ($pt): ?>
            <div class="alert alert-warning py-1 px-2 mt-2 mb-0 small">
              <?= $pt['type'] === 'return_to_fleet' ? 'Visszaadás folyamatban (admin jóváhagyásra vár)' : 'Átadás folyamatban (fogadóra vár)' ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="card-footer bg-transparent d-flex flex-wrap gap-2">
          <a href="<?= e(base_url('checklist.php?assignment_id=' . $asgn['id'])) ?>" class="btn btn-<?= !$hasD ? 'danger' : 'outline-success' ?> btn-sm flex-fill">
            <?= !$hasD ? '📋 Checklist kitöltése' : '📋 Checklist' ?>
          </a>
          <a href="<?= e(base_url('notes.php?vehicle_id=' . $v['id'])) ?>" class="btn btn-outline-secondary btn-sm flex-fill">🔧 Hibajegyek</a>
          <?php if (!$pt): ?>
          <a href="<?= e(base_url('transfer.php?assignment_id=' . $asgn['id'])) ?>" class="btn btn-outline-primary btn-sm flex-fill">↔ Átadás / visszaadás</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require '_footer.php'; ?>

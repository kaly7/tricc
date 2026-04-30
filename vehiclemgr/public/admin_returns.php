<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mailer.php';
require_login();
require_admin();
$u   = current_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $transferId = (int)($_POST['transfer_id'] ?? 0);
  $action     = (string)($_POST['action'] ?? '');

  try {
    $st = $pdo->prepare("SELECT vt.*, va.vehicle_id, va.employee_id FROM vehicle_transfers vt JOIN vehicle_assignments va ON va.id=vt.assignment_id WHERE vt.id=? AND vt.type='return_to_fleet' AND vt.status='pending' LIMIT 1");
    $st->execute([$transferId]);
    $transfer = $st->fetch();
    if (!$transfer) throw new RuntimeException('Transfer nem található.');

    $pdo->beginTransaction();

    if ($action === 'accept') {
      $pdo->prepare("UPDATE vehicle_assignments SET status='returned', returned_at=NOW() WHERE id=?")->execute([$transfer['assignment_id']]);
      $pdo->prepare("UPDATE vehicle_transfers SET status='accepted', responded_at=NOW(), responded_by_user_id=? WHERE id=?")->execute([(int)$u['id'], $transferId]);
      $pdo->commit();
      audit('return_accepted', 'vehicle_transfers', $transferId, ['vehicle_id' => $transfer['vehicle_id']]);
      flash_set('ok', 'Visszaadás elfogadva. A jármű visszakerült a telepre.');
    } elseif ($action === 'reject') {
      $pdo->prepare("UPDATE vehicle_transfers SET status='rejected', responded_at=NOW(), responded_by_user_id=? WHERE id=?")->execute([(int)$u['id'], $transferId]);
      $pdo->commit();
      audit('return_rejected', 'vehicle_transfers', $transferId);
      flash_set('ok', 'Visszaadás elutasítva. A jármű a dolgozónál marad.');
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('err', 'Hiba: ' . $e->getMessage());
  }
  redirect('admin_returns.php');
}

// Függő visszaadások
$pending = [];
try {
  $st = $pdo->query("SELECT vt.*, va.vehicle_id, va.employee_id FROM vehicle_transfers vt JOIN vehicle_assignments va ON va.id=vt.assignment_id WHERE vt.type='return_to_fleet' AND vt.status='pending' ORDER BY vt.initiated_at ASC");
  foreach ($st->fetchAll() as $row) {
    $veh = get_vehicle((int)$row['vehicle_id']);
    // Visszaadási checklist megléte
    $hasCl = false;
    try {
      $cs = $pdo->prepare("SELECT COUNT(*) FROM checklist_submissions WHERE transfer_id=? AND type='return'");
      $cs->execute([$row['id']]);
      $hasCl = (int)$cs->fetchColumn() > 0;
    } catch (Throwable $e) {}
    // Fotók
    $photos = [];
    try {
      $ps = $pdo->prepare("SELECT cp.filename FROM checklist_photos cp JOIN checklist_submissions cs ON cs.id=cp.submission_id WHERE cs.transfer_id=? AND cs.type='return'");
      $ps->execute([$row['id']]);
      $photos = $ps->fetchAll();
    } catch (Throwable $e) {}
    $pending[] = ['transfer' => $row, 'vehicle' => $veh, 'has_checklist' => $hasCl, 'photos' => $photos];
  }
} catch (Throwable $e) {}

// Nemrég lezárt visszaadások (utolsó 30 nap)
$recent = [];
try {
  $st = $pdo->query("SELECT vt.*, va.vehicle_id, va.employee_id FROM vehicle_transfers vt JOIN vehicle_assignments va ON va.id=vt.assignment_id WHERE vt.type='return_to_fleet' AND vt.status IN ('accepted','rejected') AND vt.responded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY vt.responded_at DESC LIMIT 20");
  foreach ($st->fetchAll() as $row) {
    $veh = get_vehicle((int)$row['vehicle_id']);
    $recent[] = ['transfer' => $row, 'vehicle' => $veh];
  }
} catch (Throwable $e) {}

$title = 'Visszaadások kezelése';
$page  = 'admin_returns';
require '_header.php';
?>

<h4 class="mb-3">Visszaadások kezelése</h4>

<?php if (empty($pending)): ?>
  <div class="alert alert-success">Nincs függőben lévő visszaadási kérés.</div>
<?php else: ?>
  <h5 class="text-warning">⏳ Jóváhagyásra vár (<?= count($pending) ?>)</h5>
  <?php foreach ($pending as $item):
    $tr  = $item['transfer'];
    $veh = $item['vehicle'];
  ?>
  <div class="card mb-3 border-warning">
    <div class="card-body">
      <?php if ($veh): ?>
        <div class="mb-1"><span class="plate"><?= e($veh['license_plate'] ?? '–') ?></span>
          <span class="ms-2 fw-bold"><?= e($veh['make'] . ' ' . $veh['model']) ?></span>
        </div>
      <?php endif; ?>
      <p class="mb-1">Visszaadó: <strong><?= e(employee_name((int)$tr['employee_id'])) ?></strong></p>
      <p class="text-muted small mb-1">Kezdeményezve: <?= e(date('Y.m.d H:i', strtotime($tr['initiated_at']))) ?></p>

      <?php if ($item['has_checklist']): ?>
        <span class="badge bg-success mb-2">✓ Visszaadási checklist kitöltve</span>
      <?php else: ?>
        <span class="badge bg-warning text-dark mb-2">⚠ Checklist hiányzik</span>
      <?php endif; ?>

      <?php if (!empty($item['photos'])): ?>
        <div class="photo-grid mb-2">
        <?php foreach ($item['photos'] as $ph): ?>
          <a href="<?= e(base_url('photo.php?f=' . urlencode($ph['filename']))) ?>" target="_blank">
            <img src="<?= e(base_url('photo.php?f=' . urlencode($ph['filename']) . '&thumb=1')) ?>" class="photo-thumb" alt="fotó">
          </a>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="card-footer d-flex gap-2">
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="transfer_id" value="<?= (int)$tr['id'] ?>">
        <input type="hidden" name="action" value="accept">
        <button type="submit" class="btn btn-success">✓ Elfogadom – visszaveszem a telepre</button>
      </form>
      <form method="post" class="d-inline" onsubmit="return confirm('Elutasítod a visszaadást? A jármű a dolgozónál marad.')">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="transfer_id" value="<?= (int)$tr['id'] ?>">
        <input type="hidden" name="action" value="reject">
        <button type="submit" class="btn btn-outline-danger">✗ Elutasítom</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($recent)): ?>
  <h5 class="mt-4 text-muted">Nemrég lezárt (30 nap)</h5>
  <table class="table table-sm table-hover">
    <thead><tr><th>Jármű</th><th>Dolgozó</th><th>Lezárva</th><th>Eredmény</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $item):
      $tr = $item['transfer']; $veh = $item['vehicle'];
    ?>
      <tr>
        <td><?= $veh ? e(vehicle_label($veh)) : "#{$tr['vehicle_id']}" ?></td>
        <td><?= e(employee_name((int)$tr['employee_id'])) ?></td>
        <td><?= e(date('Y.m.d H:i', strtotime($tr['responded_at']))) ?></td>
        <td><span class="badge <?= $tr['status'] === 'accepted' ? 'bg-success' : 'bg-danger' ?>"><?= $tr['status'] === 'accepted' ? 'Elfogadva' : 'Elutasítva' ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php require '_footer.php'; ?>

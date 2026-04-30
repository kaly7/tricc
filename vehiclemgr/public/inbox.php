<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mailer.php';
require_login();
$u       = current_user();
$myEmpId = my_employee_id();
$pdo     = db();

$title = 'Bejövő átadások';
$page  = 'inbox';

// POST: elfogad / visszautasít
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $transferId = (int)($_POST['transfer_id'] ?? 0);
  $action     = (string)($_POST['action'] ?? '');

  try {
    $st = $pdo->prepare("SELECT vt.*, va.vehicle_id FROM vehicle_transfers vt JOIN vehicle_assignments va ON va.id=vt.assignment_id WHERE vt.id=? AND vt.to_employee_id=? AND vt.status='pending' AND vt.type='transfer_to_employee' LIMIT 1");
    $st->execute([$transferId, $myEmpId]);
    $transfer = $st->fetch();
    if (!$transfer) throw new RuntimeException('Transfer nem található.');

    $pdo->beginTransaction();

    if ($action === 'accept') {
      // Eredeti hozzárendelés visszaadva
      $pdo->prepare("UPDATE vehicle_assignments SET status='returned', returned_at=NOW() WHERE id=?")->execute([$transfer['assignment_id']]);

      // Új hozzárendelés az átvevőnek
      $pdo->prepare("INSERT INTO vehicle_assignments (vehicle_id, employee_id, assigned_by_user_id, assigned_at) VALUES (?,?,?,NOW())")
          ->execute([$transfer['vehicle_id'], $myEmpId, (int)$u['id']]);
      $newAssignId = (int)$pdo->lastInsertId();

      // Transfer lezárása
      $pdo->prepare("UPDATE vehicle_transfers SET status='accepted', responded_at=NOW(), responded_by_user_id=?, new_assignment_id=? WHERE id=?")
          ->execute([(int)$u['id'], $newAssignId, $transferId]);

      $pdo->commit();
      audit('transfer_accepted', 'vehicle_transfers', $transferId, ['new_assignment_id' => $newAssignId]);

      // Admin értesítés
      $v      = get_vehicle((int)$transfer['vehicle_id']);
      $vLabel = $v ? vehicle_label($v) : "#{$transfer['vehicle_id']}";
      $from   = employee_name((int)$transfer['from_employee_id']);
      $to     = employee_name($myEmpId);
      notify_admin(
        "Jármű átadás elfogadva: {$vLabel}",
        "<p><strong>{$to}</strong> elfogadta a(z) <strong>{$vLabel}</strong> jármű átadását <strong>{$from}</strong>-tól.</p>"
      );

      flash_set('ok', 'Átvétel elfogadva. Kérjük töltsd ki az átvételi checklistet.');
      header('Location: ' . base_url('checklist.php?assignment_id=' . $newAssignId . '&type=takeover&transfer_id=' . $transferId));
      exit;

    } elseif ($action === 'reject') {
      $pdo->prepare("UPDATE vehicle_transfers SET status='rejected', responded_at=NOW(), responded_by_user_id=? WHERE id=?")->execute([(int)$u['id'], $transferId]);
      $pdo->commit();
      audit('transfer_rejected', 'vehicle_transfers', $transferId);

      // Admin értesítés
      $v      = get_vehicle((int)$transfer['vehicle_id']);
      $vLabel = $v ? vehicle_label($v) : "#{$transfer['vehicle_id']}";
      $from   = employee_name((int)$transfer['from_employee_id']);
      $to     = employee_name($myEmpId);
      notify_admin(
        "Jármű átadás visszautasítva: {$vLabel}",
        "<p><strong>{$to}</strong> visszautasította a(z) <strong>{$vLabel}</strong> jármű átadását <strong>{$from}</strong>-tól.</p><p>A jármű továbbra is {$from} nyilvántartásában marad.</p>"
      );

      flash_set('ok', 'Átadás visszautasítva. A jármű az átadónál marad.');
      redirect('inbox.php');
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('err', 'Hiba: ' . $e->getMessage());
    redirect('inbox.php');
  }
}

// Bejövő, elfogadásra váró átadások
$pending = [];
try {
  $st = $pdo->prepare("SELECT vt.*, va.vehicle_id, va.employee_id AS from_assignment_employee FROM vehicle_transfers vt JOIN vehicle_assignments va ON va.id=vt.assignment_id WHERE vt.to_employee_id=? AND vt.status='pending' AND vt.type='transfer_to_employee' ORDER BY vt.initiated_at DESC");
  $st->execute([$myEmpId]);
  $rows = $st->fetchAll();
  foreach ($rows as $row) {
    $veh = get_vehicle((int)$row['vehicle_id']);
    $pending[] = ['transfer' => $row, 'vehicle' => $veh];
  }
} catch (Throwable $e) {}

require '_header.php';
?>

<h4 class="mb-3">Bejövő átadások</h4>

<?php if (empty($pending)): ?>
  <div class="alert alert-info">Nincs függőben lévő átadás a számodra.</div>
<?php else: ?>
  <?php foreach ($pending as $item):
    $tr = $item['transfer'];
    $veh = $item['vehicle'];
  ?>
  <div class="card mb-3">
    <div class="card-body">
      <?php if ($veh): ?>
        <div class="mb-2"><span class="plate"><?= e($veh['license_plate'] ?? '–') ?></span>
          <span class="ms-2 fw-bold"><?= e($veh['make'] . ' ' . $veh['model']) ?></span>
        </div>
      <?php endif; ?>
      <p class="mb-1">
        <strong><?= e(employee_name((int)$tr['from_employee_id'])) ?></strong> szeretné átadni neked ezt a járművet.
      </p>
      <p class="text-muted small mb-0">Kezdeményezve: <?= e(date('Y.m.d H:i', strtotime($tr['initiated_at']))) ?></p>
    </div>
    <div class="card-footer d-flex gap-2">
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="transfer_id" value="<?= (int)$tr['id'] ?>">
        <input type="hidden" name="action" value="accept">
        <button type="submit" class="btn btn-success">✓ Elfogadom</button>
      </form>
      <form method="post" class="d-inline" onsubmit="return confirm('Biztosan visszautasítod az átadást?')">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="transfer_id" value="<?= (int)$tr['id'] ?>">
        <input type="hidden" name="action" value="reject">
        <button type="submit" class="btn btn-outline-danger">✗ Visszautasítom</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require '_footer.php'; ?>

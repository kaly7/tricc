<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mailer.php';
require_login();
$u       = current_user();
$myEmpId = my_employee_id();
$pdo     = db();

$assignmentId = (int)($_GET['assignment_id'] ?? 0);
$checklistDone = isset($_GET['checklist_done']);

// Hozzárendelés – csak a sajáthoz
$asgn = null;
try {
  $st = $pdo->prepare("SELECT * FROM vehicle_assignments WHERE id=? AND employee_id=? AND status='active' LIMIT 1");
  $st->execute([$assignmentId, $myEmpId]);
  $asgn = $st->fetch() ?: null;
} catch (Throwable $e) {}

if (!$asgn) { flash_set('err', 'Hozzárendelés nem található.'); redirect('my_vehicles.php'); }

$v = get_vehicle((int)$asgn['vehicle_id']);
if (!$v) { flash_set('err', 'Jármű nem található.'); redirect('my_vehicles.php'); }

// Van-e már függő transfer?
$existingTransfer = null;
try {
  $st = $pdo->prepare("SELECT * FROM vehicle_transfers WHERE assignment_id=? AND status='pending' LIMIT 1");
  $st->execute([$assignmentId]);
  $existingTransfer = $st->fetch() ?: null;
} catch (Throwable $e) {}

// POST – transfer kezdeményezés
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'initiate' && !$existingTransfer) {
    $type        = (string)($_POST['type'] ?? '');
    $toEmployeeId = (int)($_POST['to_employee_id'] ?? 0);

    if (!in_array($type, ['return_to_fleet', 'transfer_to_employee'])) {
      flash_set('err', 'Érvénytelen típus.'); redirect('transfer.php?assignment_id=' . $assignmentId);
    }
    if ($type === 'transfer_to_employee' && $toEmployeeId <= 0) {
      flash_set('err', 'Válassz dolgozót az átadáshoz.'); redirect('transfer.php?assignment_id=' . $assignmentId);
    }
    if ($type === 'transfer_to_employee' && $toEmployeeId === $myEmpId) {
      flash_set('err', 'Magadnak nem adhatod át.'); redirect('transfer.php?assignment_id=' . $assignmentId);
    }

    try {
      $pdo->beginTransaction();

      $pdo->prepare("INSERT INTO vehicle_transfers (assignment_id, type, from_employee_id, to_employee_id, initiated_by_user_id) VALUES (?,?,?,?,?)")
          ->execute([$assignmentId, $type, $myEmpId, $type === 'transfer_to_employee' ? $toEmployeeId : null, (int)$u['id']]);
      $transferId = (int)$pdo->lastInsertId();

      $pdo->commit();
      audit('transfer_initiated', 'vehicle_transfers', $transferId, ['type' => $type, 'vehicle_id' => $v['id']]);

      // Admin értesítés átadásnál
      if ($type === 'transfer_to_employee') {
        $fromName = employee_name($myEmpId);
        $toName   = employee_name($toEmployeeId);
        $vLabel   = vehicle_label($v);
        notify_admin(
          "Jármű átadás kezdeményezve: {$vLabel}",
          "<p><strong>{$fromName}</strong> átadná a(z) <strong>{$vLabel}</strong> járművet <strong>{$toName}</strong> részére.</p><p>Fogadó jóváhagyására vár.</p>"
        );
      }

      // Visszaadásnál: átadási checklist kitöltésre irányít
      $checkUrl = base_url('checklist.php?assignment_id=' . $assignmentId . '&type=' . ($type === 'return_to_fleet' ? 'return' : 'transfer') . '&transfer_id=' . $transferId);
      flash_set('ok', 'Transfer kezdeményezve. Kérjük töltsd ki a ' . ($type === 'return_to_fleet' ? 'visszaadási' : 'átadási') . ' checklistet.');
      header('Location: ' . $checkUrl);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set('err', 'Hiba: ' . $e->getMessage());
      redirect('transfer.php?assignment_id=' . $assignmentId);
    }
  }

  // Transfer visszavonás
  if ($action === 'cancel' && $existingTransfer) {
    try {
      $pdo->prepare("UPDATE vehicle_transfers SET status='cancelled', responded_at=NOW(), responded_by_user_id=? WHERE id=? AND status='pending'")
          ->execute([(int)$u['id'], (int)$existingTransfer['id']]);
      audit('transfer_cancelled', 'vehicle_transfers', (int)$existingTransfer['id']);
      flash_set('ok', 'Transfer visszavonva.');
    } catch (Throwable $e) {
      flash_set('err', 'Hiba: ' . $e->getMessage());
    }
    redirect('transfer.php?assignment_id=' . $assignmentId);
  }
}

$employees = get_all_employees();
$title = 'Átadás / visszaadás – ' . vehicle_label($v);
$page  = 'my_vehicles';

require '_header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= e(base_url('my_vehicles.php')) ?>" class="btn btn-outline-secondary btn-sm">← Vissza</a>
  <h5 class="mb-0">Átadás / visszaadás</h5>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <span class="plate"><?= e($v['license_plate'] ?? '–') ?></span>
    <span class="ms-2 fw-bold"><?= e($v['make'] . ' ' . $v['model']) ?></span>
  </div>
</div>

<?php if ($existingTransfer): ?>
  <div class="alert alert-warning">
    <strong>Folyamatban lévő kérés:</strong>
    <?= $existingTransfer['type'] === 'return_to_fleet' ? 'Visszaadás a járműtelepre – admin jóváhagyásra vár.' : 'Átadás ' . e(employee_name((int)$existingTransfer['to_employee_id'])) . ' részére – fogadóra vár.' ?>
  </div>

  <?php
  // Van-e már checklist kitöltve ehhez a transfer-hez?
  $hasTransferChecklist = false;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM checklist_submissions WHERE transfer_id=? AND assignment_id=? AND type IN ('return','transfer')");
    $st->execute([$existingTransfer['id'], $assignmentId]);
    $hasTransferChecklist = (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {}
  $clType = $existingTransfer['type'] === 'return_to_fleet' ? 'return' : 'transfer';
  ?>
  <?php if (!$hasTransferChecklist): ?>
    <div class="alert alert-info">
      A <?= $existingTransfer['type'] === 'return_to_fleet' ? 'visszaadási' : 'átadási' ?> checklist még nincs kitöltve.
      <a href="<?= e(base_url('checklist.php?assignment_id=' . $assignmentId . '&type=' . $clType . '&transfer_id=' . $existingTransfer['id'])) ?>" class="btn btn-warning btn-sm ms-2">📋 Checklist kitöltése</a>
    </div>
  <?php else: ?>
    <div class="alert alert-success">✓ A checklist kitöltve.</div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="cancel">
    <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Biztosan visszavonod?')">Transfer visszavonása</button>
  </form>

<?php else: ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="initiate">

    <div class="mb-3">
      <label class="form-label fw-bold">Mit szeretnél csinálni?</label>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="type" id="t_return" value="return_to_fleet" checked onchange="toggleToEmp(false)">
        <label class="form-check-label" for="t_return">Visszaadom a járműtelepre (admin jóváhagyás szükséges)</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="type" id="t_transfer" value="transfer_to_employee" onchange="toggleToEmp(true)">
        <label class="form-check-label" for="t_transfer">Átadom egy másik dolgozónak</label>
      </div>
    </div>

    <div id="toEmpDiv" style="display:none" class="mb-3">
      <label class="form-label">Átadás kinek?</label>
      <select name="to_employee_id" class="form-select">
        <option value="">– Válassz dolgozót –</option>
        <?php foreach ($employees as $emp): if ((int)$emp['id'] === $myEmpId) continue; ?>
          <option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" class="btn btn-primary">Tovább →</button>
  </form>

  <script>
  function toggleToEmp(show) {
    document.getElementById('toEmpDiv').style.display = show ? '' : 'none';
  }
  </script>

<?php endif; ?>

<?php require '_footer.php'; ?>

<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
$u       = current_user();
$myEmpId = my_employee_id();
$isAdmin = ($u['role'] ?? '') === 'admin';
$pdo     = db();

$vehicleId = (int)($_GET['vehicle_id'] ?? 0);
$v = $vehicleId ? get_vehicle($vehicleId) : null;
if (!$v) { flash_set('err', 'Jármű nem található.'); redirect('my_vehicles.php'); }

// Ellenőrzés: csak az adhatja hozzá aki aktívan használja, vagy admin
$myAssignment = null;
try {
  $st = $pdo->prepare("SELECT * FROM vehicle_assignments WHERE vehicle_id=? AND employee_id=? AND status='active' LIMIT 1");
  $st->execute([$vehicleId, $myEmpId]);
  $myAssignment = $st->fetch() ?: null;
} catch (Throwable $e) {}

if (!$myAssignment && !$isAdmin) {
  flash_set('err', 'Nincs aktív hozzárendelésed ehhez a járműhöz.');
  redirect('my_vehicles.php');
}

// POST – hibajegy hozzáadása / lezárása
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'add') {
    $text = trim((string)($_POST['note_text'] ?? ''));
    if ($text === '') { flash_set('err', 'A megjegyzés nem lehet üres.'); redirect('notes.php?vehicle_id=' . $vehicleId); }
    try {
      $pdo->prepare("INSERT INTO vehicle_notes (vehicle_id, employee_id, assignment_id, note_text) VALUES (?,?,?,?)")
          ->execute([$vehicleId, $myEmpId, $myAssignment ? (int)$myAssignment['id'] : null, $text]);
      audit('note_added', 'vehicle_notes', (int)$pdo->lastInsertId(), ['vehicle_id' => $vehicleId]);
      flash_set('ok', 'Hibajegy rögzítve.');
    } catch (Throwable $e) { flash_set('err', 'Hiba: ' . $e->getMessage()); }
    redirect('notes.php?vehicle_id=' . $vehicleId);
  }

  if ($action === 'resolve' && $isAdmin) {
    $noteId = (int)($_POST['note_id'] ?? 0);
    try {
      $pdo->prepare("UPDATE vehicle_notes SET status='resolved', resolved_at=NOW(), resolved_by_user_id=? WHERE id=? AND vehicle_id=?")->execute([(int)$u['id'], $noteId, $vehicleId]);
      audit('note_resolved', 'vehicle_notes', $noteId);
      flash_set('ok', 'Hibajegy lezárva.');
    } catch (Throwable $e) { flash_set('err', 'Hiba: ' . $e->getMessage()); }
    redirect('notes.php?vehicle_id=' . $vehicleId);
  }
}

// Hibajegyek listája
$notes = [];
try {
  $notes = $pdo->prepare("SELECT * FROM vehicle_notes WHERE vehicle_id=? ORDER BY created_at DESC");
  $notes->execute([$vehicleId]);
  $notes = $notes->fetchAll();
} catch (Throwable $e) { $notes = []; }

$title = 'Hibajegyek – ' . vehicle_label($v);
$page  = 'my_vehicles';
require '_header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= e(base_url('my_vehicles.php')) ?>" class="btn btn-outline-secondary btn-sm">← Vissza</a>
  <h5 class="mb-0">Hibajegyek</h5>
</div>
<div class="card mb-3">
  <div class="card-body py-2">
    <span class="plate"><?= e($v['license_plate'] ?? '–') ?></span>
    <span class="ms-2 fw-bold"><?= e($v['make'] . ' ' . $v['model']) ?></span>
  </div>
</div>

<!-- Új hibajegy -->
<div class="card mb-4">
  <div class="card-header">Új hibajegy / megjegyzés</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add">
      <div class="mb-2">
        <textarea name="note_text" class="form-control" rows="3" placeholder="Leírás (pl. sérülés, hibajelenség...)" required></textarea>
      </div>
      <button type="submit" class="btn btn-warning">🔧 Hibajegy rögzítése</button>
    </form>
  </div>
</div>

<!-- Hibajegy lista -->
<?php if (empty($notes)): ?>
  <div class="alert alert-info">Nincs még rögzített hibajegy.</div>
<?php else: ?>
  <?php foreach ($notes as $note): ?>
  <div class="card mb-2 <?= $note['status'] === 'resolved' ? 'border-success opacity-75' : 'border-warning' ?>">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <span class="badge <?= $note['status'] === 'open' ? 'bg-warning text-dark' : 'bg-success' ?> me-2">
            <?= $note['status'] === 'open' ? 'Nyitott' : 'Lezárt' ?>
          </span>
          <span class="text-muted small"><?= e(employee_name((int)$note['employee_id'])) ?> – <?= e(date('Y.m.d H:i', strtotime($note['created_at']))) ?></span>
        </div>
        <?php if ($isAdmin && $note['status'] === 'open'): ?>
        <form method="post" class="ms-2">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="resolve">
          <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
          <button type="submit" class="btn btn-outline-success btn-sm">✓ Lezár</button>
        </form>
        <?php endif; ?>
      </div>
      <p class="mt-2 mb-0"><?= nl2br(e($note['note_text'])) ?></p>
      <?php if ($note['resolved_at']): ?>
        <p class="text-muted small mt-1 mb-0">Lezárva: <?= e(date('Y.m.d H:i', strtotime($note['resolved_at']))) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require '_footer.php'; ?>

<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
$u       = current_user();
$myEmpId = my_employee_id();
$pdo     = db();

$assignmentId = (int)($_GET['assignment_id'] ?? 0);
$type         = (string)($_GET['type'] ?? 'daily'); // daily | takeover | return | transfer
$transferId   = (int)($_GET['transfer_id'] ?? 0);

// Hozzárendelés ellenőrzés (csak a sajáthoz fér hozzá)
$asgn = null;
try {
  $st = $pdo->prepare("SELECT * FROM vehicle_assignments WHERE id=? AND employee_id=? AND status='active' LIMIT 1");
  $st->execute([$assignmentId, $myEmpId]);
  $asgn = $st->fetch() ?: null;
} catch (Throwable $e) {}

if (!$asgn) {
  flash_set('err', 'Hozzárendelés nem található.');
  redirect('my_vehicles.php');
}

$v = get_vehicle((int)$asgn['vehicle_id']);
if (!$v) { flash_set('err', 'Jármű nem található.'); redirect('my_vehicles.php'); }

// Ha napi és ma már kitöltötte: csak megtekintés
$todayDone = has_daily_checklist_today($assignmentId);
$viewOnly  = ($type === 'daily' && $todayDone);

// Sablon tételek
$templateItems = get_checklist_template((int)$v['id']);

// POST feldolgozás
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$viewOnly) {
  verify_csrf();

  $odometer  = isset($_POST['odometer_km'])  && $_POST['odometer_km']  !== '' ? (int)$_POST['odometer_km']  : null;
  $hourMeter = isset($_POST['hour_meter'])   && $_POST['hour_meter']   !== '' ? (float)$_POST['hour_meter'] : null;
  $notes     = trim((string)($_POST['notes'] ?? ''));
  $postType  = in_array($_POST['sub_type'] ?? '', ['daily','takeover','return','transfer']) ? $_POST['sub_type'] : $type;

  try {
    $pdo->beginTransaction();

    $pdo->prepare("INSERT INTO checklist_submissions (vehicle_id, employee_id, assignment_id, type, odometer_km, hour_meter, notes, transfer_id) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([(int)$v['id'], $myEmpId, $assignmentId, $postType, $odometer, $hourMeter, $notes ?: null, $transferId ?: null]);
    $submissionId = (int)$pdo->lastInsertId();

    foreach ($templateItems as $item) {
      $itemId  = (int)$item['id'];
      $isOk    = isset($_POST["item_{$itemId}"]) && $_POST["item_{$itemId}"] === 'ok' ? 1 : 0;
      $itemNote = trim((string)($_POST["note_{$itemId}"] ?? ''));
      $pdo->prepare("INSERT INTO checklist_answers (submission_id, template_item_id, is_ok, note) VALUES (?,?,?,?)")
          ->execute([$submissionId, $itemId, $isOk, $itemNote ?: null]);
    }

    // Fotók feldolgozása
    if (!empty($_FILES['photos']['tmp_name'])) {
      $photoDir = __DIR__ . '/../storage/photos/';
      foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
        if (!is_uploaded_file($tmp)) continue;
        $origName = (string)($_FILES['photos']['name'][$i] ?? 'foto.jpg');
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','heic','heif'])) continue;
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($tmp, $photoDir . $filename)) {
          $pdo->prepare("INSERT INTO checklist_photos (submission_id, filename, original_name) VALUES (?,?,?)")
              ->execute([$submissionId, $filename, $origName]);
        }
      }
    }

    $pdo->commit();
    audit('checklist_submitted', 'checklist_submissions', $submissionId, ['type' => $postType, 'vehicle_id' => $v['id']]);

    // Ha return/transfer típus: visszairányítás a transfer oldalra
    if ($transferId && in_array($postType, ['return','transfer'])) {
      flash_set('ok', 'Checklist elmentve.');
      redirect('transfer.php?assignment_id=' . $assignmentId . '&transfer_id=' . $transferId . '&checklist_done=1');
    }

    flash_set('ok', 'Checklist sikeresen elmentve.');
    redirect('my_vehicles.php');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('err', 'Hiba: ' . $e->getMessage());
  }
}

// Mai/utolsó submission megtekintéshez
$lastSubmission = null;
$lastAnswers    = [];
$lastPhotos     = [];
if ($viewOnly) {
  try {
    $st = $pdo->prepare("SELECT * FROM checklist_submissions WHERE assignment_id=? AND type='daily' AND DATE(submitted_at)=CURDATE() ORDER BY submitted_at DESC LIMIT 1");
    $st->execute([$assignmentId]);
    $lastSubmission = $st->fetch() ?: null;
    if ($lastSubmission) {
      $st2 = $pdo->prepare("SELECT * FROM checklist_answers WHERE submission_id=?");
      $st2->execute([$lastSubmission['id']]);
      foreach ($st2->fetchAll() as $ans) $lastAnswers[(int)$ans['template_item_id']] = $ans;
      $st3 = $pdo->prepare("SELECT * FROM checklist_photos WHERE submission_id=?");
      $st3->execute([$lastSubmission['id']]);
      $lastPhotos = $st3->fetchAll();
    }
  } catch (Throwable $e) {}
}

$typeLabel = match($type) { 'takeover' => 'Átvételi', 'return' => 'Visszaadási', 'transfer' => 'Átadási', default => 'Napi' };
$title = $typeLabel . ' checklist – ' . vehicle_label($v);
$page  = 'my_vehicles';

require '_header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= e(base_url('my_vehicles.php')) ?>" class="btn btn-outline-secondary btn-sm">← Vissza</a>
  <h5 class="mb-0"><?= e($typeLabel) ?> checklist</h5>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <span class="plate"><?= e($v['license_plate'] ?? '–') ?></span>
    <span class="ms-2 fw-bold"><?= e($v['make'] . ' ' . $v['model']) ?></span>
    <?php if (!empty($v['vehicle_identifier'])): ?>
      <span class="text-muted ms-2 small"><?= e($v['vehicle_identifier']) ?></span>
    <?php endif; ?>
  </div>
</div>

<?php if ($viewOnly && $lastSubmission): ?>
  <div class="alert alert-success">
    ✓ A mai napi checklist már ki van töltve (<?= e(date('H:i', strtotime($lastSubmission['submitted_at']))) ?>).
  </div>
  <!-- Megtekintés mód -->
  <?php if ($lastSubmission['odometer_km'] !== null): ?>
    <p><strong>Km-óra:</strong> <?= number_format((int)$lastSubmission['odometer_km'], 0, '.', ' ') ?> km</p>
  <?php endif; ?>
  <?php if ($lastSubmission['hour_meter'] !== null): ?>
    <p><strong>Üzemóra:</strong> <?= e($lastSubmission['hour_meter']) ?></p>
  <?php endif; ?>
  <?php if (!empty($templateItems)): ?>
    <div class="list-group mb-3">
    <?php foreach ($templateItems as $item): $ans = $lastAnswers[(int)$item['id']] ?? null; ?>
      <div class="list-group-item <?= ($ans && !$ans['is_ok']) ? 'list-group-item-danger' : '' ?>">
        <span class="me-2"><?= ($ans && $ans['is_ok']) ? '✅' : '❌' ?></span>
        <?= e($item['item_text']) ?>
        <?php if ($ans && !empty($ans['note'])): ?>
          <div class="text-muted small ms-4"><?= e($ans['note']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($lastSubmission['notes'])): ?>
    <p><strong>Megjegyzés:</strong> <?= e($lastSubmission['notes']) ?></p>
  <?php endif; ?>
  <?php if (!empty($lastPhotos)): ?>
    <div class="photo-grid mb-3">
    <?php foreach ($lastPhotos as $ph): ?>
      <a href="<?= e(base_url('photo.php?f=' . urlencode($ph['filename']))) ?>" target="_blank">
        <img src="<?= e(base_url('photo.php?f=' . urlencode($ph['filename']) . '&thumb=1')) ?>" class="photo-thumb" alt="fotó">
      </a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>
  <?php if (empty($templateItems)): ?>
    <div class="alert alert-warning">Ehhez a járműhöz még nincs checklist sablon definiálva. Kérj az adminisztrátortól checklistet.</div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="sub_type" value="<?= e($type) ?>">

    <!-- Km-óra / üzemóra -->
    <div class="row g-2 mb-3">
      <div class="col-6">
        <label class="form-label">Km-óra állás</label>
        <input type="number" name="odometer_km" class="form-control" min="0" placeholder="km">
      </div>
      <div class="col-6">
        <label class="form-label">Üzemóra</label>
        <input type="number" name="hour_meter" class="form-control" step="0.1" min="0" placeholder="h">
      </div>
    </div>

    <!-- Checklist tételek -->
    <?php if (!empty($templateItems)): ?>
    <h6>Ellenőrzési tételek</h6>
    <?php foreach ($templateItems as $item): $itemId = (int)$item['id']; ?>
      <div class="checklist-item">
        <div class="d-flex align-items-start gap-2">
          <div class="flex-fill fw-medium"><?= e($item['item_text']) ?></div>
          <div class="checklist-toggle d-flex gap-1">
            <input type="radio" class="btn-check" name="item_<?= $itemId ?>" id="ok_<?= $itemId ?>" value="ok" checked>
            <label class="btn btn-sm btn-outline-success" for="ok_<?= $itemId ?>">✓ OK</label>
            <input type="radio" class="btn-check" name="item_<?= $itemId ?>" id="nok_<?= $itemId ?>" value="nok">
            <label class="btn btn-sm btn-outline-danger" for="nok_<?= $itemId ?>">✗ Hiba</label>
          </div>
        </div>
        <div class="mt-1">
          <input type="text" name="note_<?= $itemId ?>" class="form-control form-control-sm" placeholder="Megjegyzés (opcionális)">
        </div>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Általános megjegyzés -->
    <div class="mb-3 mt-3">
      <label class="form-label">Általános megjegyzés</label>
      <textarea name="notes" class="form-control" rows="3" placeholder="Egyéb észrevétel, hiba..."></textarea>
    </div>

    <!-- Fotó feltöltés -->
    <div class="mb-3">
      <label class="form-label">Fotók feltöltése <span class="text-muted small">(pl. sérülés dokumentálása)</span></label>
      <input type="file" name="photos[]" class="form-control" multiple accept="image/*" capture="environment">
      <div class="form-text">Több kép is feltölthető egyszerre. Kamerával közvetlenül is készíthető.</div>
    </div>

    <button type="submit" class="btn btn-primary w-100">💾 Checklist mentése</button>
  </form>
<?php endif; ?>

<?php require '_footer.php'; ?>

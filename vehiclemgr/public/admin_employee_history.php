<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
require_admin();
$pdo = db();

$employeeId = (int)($_GET['employee_id'] ?? 0);
if ($employeeId <= 0) { flash_set('err', 'Hiányzó dolgozó azonosító.'); redirect('admin_vehicles.php'); }

$empName = employee_name($employeeId);

// Összes hozzárendelés ehhez a dolgozóhoz
$assignments = [];
try {
  $st = $pdo->prepare("SELECT * FROM vehicle_assignments WHERE employee_id=? ORDER BY assigned_at DESC");
  $st->execute([$employeeId]);
  $assignments = $st->fetchAll();
} catch (Throwable $e) {}

// Checklistek (utolsó 60)
$checklists = [];
try {
  $st = $pdo->prepare("
    SELECT cs.*,
           COUNT(ca.id) AS total_items,
           SUM(CASE WHEN ca.is_ok=0 THEN 1 ELSE 0 END) AS failed_items,
           COUNT(cp.id) AS photo_count
    FROM checklist_submissions cs
    LEFT JOIN checklist_answers ca ON ca.submission_id=cs.id
    LEFT JOIN checklist_photos cp ON cp.submission_id=cs.id
    WHERE cs.employee_id=?
    GROUP BY cs.id
    ORDER BY cs.submitted_at DESC
    LIMIT 60
  ");
  $st->execute([$employeeId]);
  $checklists = $st->fetchAll();
} catch (Throwable $e) {}

// Hibajegyek
$notes = [];
try {
  $st = $pdo->prepare("SELECT * FROM vehicle_notes WHERE employee_id=? ORDER BY created_at DESC");
  $st->execute([$employeeId]);
  $notes = $st->fetchAll();
} catch (Throwable $e) {}

$tab = (string)($_GET['tab'] ?? 'assignments');
if (!in_array($tab, ['assignments','checklists','notes'])) $tab = 'assignments';

$title = 'Előzmények – ' . $empName;
$page  = 'admin_vehicles';
require '_header.php';

function typeLabel2(string $t): string {
  return match($t) { 'daily'=>'Napi', 'takeover'=>'Átvételi', 'return'=>'Visszaadási', 'transfer'=>'Átadási', default=>$t };
}
function typeBadge2(string $t): string {
  return match($t) { 'daily'=>'bg-primary', 'takeover'=>'bg-success', 'return'=>'bg-warning text-dark', 'transfer'=>'bg-info text-dark', default=>'bg-secondary' };
}
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= e(base_url('admin_vehicles.php')) ?>" class="btn btn-outline-secondary btn-sm">← Vissza</a>
  <div>
    <h5 class="mb-0">👤 <?= e($empName) ?></h5>
    <div class="text-muted small">Dolgozói előzmények</div>
  </div>
</div>

<!-- Gyors statisztika -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-4">
    <div class="card text-center py-2">
      <div class="fs-4 fw-bold text-secondary"><?= count($assignments) ?></div>
      <div class="text-muted small">Hozzárendelés összesen</div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card text-center py-2">
      <div class="fs-4 fw-bold text-primary"><?= count($checklists) ?></div>
      <div class="text-muted small">Checklist kitöltés</div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card text-center py-2">
      <?php $openNotes = count(array_filter($notes, fn($n) => $n['status']==='open')); ?>
      <div class="fs-4 fw-bold <?= $openNotes>0?'text-danger':'text-success' ?>"><?= count($notes) ?></div>
      <div class="text-muted small">Hibajegy (<?= $openNotes ?> nyitott)</div>
    </div>
  </div>
</div>

<!-- Tabok -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tab==='assignments'?'active':'' ?>" href="?employee_id=<?= $employeeId ?>&tab=assignments">
      Járművek <span class="badge bg-secondary ms-1"><?= count($assignments) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='checklists'?'active':'' ?>" href="?employee_id=<?= $employeeId ?>&tab=checklists">
      Checklistek <span class="badge bg-secondary ms-1"><?= count($checklists) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='notes'?'active':'' ?>" href="?employee_id=<?= $employeeId ?>&tab=notes">
      Hibajegyek <span class="badge <?= $openNotes>0?'bg-danger':'bg-secondary' ?> ms-1"><?= count($notes) ?></span>
    </a>
  </li>
</ul>

<!-- JÁRMŰVEK TAB -->
<?php if ($tab === 'assignments'): ?>
  <?php if (empty($assignments)): ?>
    <div class="alert alert-info">Nincs hozzárendelési előzmény.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr><th>Jármű</th><th>Kiosztva</th><th>Visszaadva</th><th>Időtartam</th><th>Állapot</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($assignments as $asgn):
          $veh = get_vehicle((int)$asgn['vehicle_id']);
        ?>
          <tr>
            <td>
              <?php if ($veh): ?>
                <span class="plate" style="font-size:.9rem"><?= e($veh['license_plate'] ?? '–') ?></span>
                <span class="ms-1"><?= e($veh['make'] . ' ' . $veh['model']) ?></span>
              <?php else: ?>–<?php endif; ?>
            </td>
            <td><?= e(date('Y.m.d', strtotime($asgn['assigned_at']))) ?></td>
            <td><?= $asgn['returned_at'] ? e(date('Y.m.d', strtotime($asgn['returned_at']))) : '–' ?></td>
            <td class="text-muted small">
              <?php
                $from = new DateTime($asgn['assigned_at']);
                $to   = $asgn['returned_at'] ? new DateTime($asgn['returned_at']) : new DateTime();
                echo $from->diff($to)->days . ' nap';
              ?>
            </td>
            <td>
              <span class="badge <?= $asgn['status']==='active'?'bg-success':'bg-secondary' ?>">
                <?= $asgn['status']==='active' ? 'Aktív' : 'Lezárt' ?>
              </span>
            </td>
            <td>
              <?php if ($veh): ?>
                <a href="<?= e(base_url('admin_vehicle_history.php?vehicle_id=' . $veh['id'])) ?>" class="btn btn-outline-secondary btn-sm">Jármű előzmény</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<!-- CHECKLISTEK TAB -->
<?php elseif ($tab === 'checklists'): ?>
  <?php if (empty($checklists)): ?>
    <div class="alert alert-info">Még nincs kitöltött checklist.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle table-sm">
        <thead class="table-light">
          <tr><th>Dátum</th><th>Típus</th><th>Jármű</th><th>Km-óra</th><th>Tételek</th><th>Fotók</th></tr>
        </thead>
        <tbody>
        <?php foreach ($checklists as $cl):
          $veh = get_vehicle((int)$cl['vehicle_id']);
        ?>
          <tr class="<?= (int)$cl['failed_items']>0 ? 'table-warning' : '' ?>">
            <td class="text-nowrap"><?= e(date('Y.m.d H:i', strtotime($cl['submitted_at']))) ?></td>
            <td><span class="badge <?= typeBadge2($cl['type']) ?>"><?= typeLabel2($cl['type']) ?></span></td>
            <td>
              <?php if ($veh): ?>
                <a href="<?= e(base_url('admin_vehicle_history.php?vehicle_id=' . $veh['id'] . '&tab=checklists&view_cl=' . $cl['id'])) ?>" class="text-decoration-none">
                  <span class="plate" style="font-size:.8rem"><?= e($veh['license_plate'] ?? '–') ?></span>
                </a>
              <?php else: ?>–<?php endif; ?>
            </td>
            <td><?= $cl['odometer_km'] !== null ? number_format((int)$cl['odometer_km'], 0, '.', ' ') . ' km' : '–' ?></td>
            <td>
              <?php if ((int)$cl['total_items'] > 0): ?>
                <?php if ((int)$cl['failed_items'] > 0): ?>
                  <span class="text-danger fw-bold">⚠ <?= (int)$cl['failed_items'] ?> hiba / <?= (int)$cl['total_items'] ?></span>
                <?php else: ?>
                  <span class="text-success">✓ <?= (int)$cl['total_items'] ?> OK</span>
                <?php endif; ?>
              <?php else: ?>–<?php endif; ?>
            </td>
            <td>
              <?php if ((int)$cl['photo_count'] > 0): ?>
                <span class="badge bg-info text-dark">📷 <?= (int)$cl['photo_count'] ?></span>
              <?php else: ?>–<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<!-- HIBAJEGYEK TAB -->
<?php elseif ($tab === 'notes'): ?>
  <?php if (empty($notes)): ?>
    <div class="alert alert-info">Nincs rögzített hibajegy.</div>
  <?php else: ?>
    <?php foreach ($notes as $note):
      $veh = get_vehicle((int)$note['vehicle_id']);
    ?>
    <div class="card mb-2 <?= $note['status']==='open'?'border-warning':'border-success opacity-75' ?>">
      <div class="card-body py-2">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="badge <?= $note['status']==='open'?'bg-warning text-dark':'bg-success' ?> me-2">
              <?= $note['status']==='open'?'Nyitott':'Lezárt' ?>
            </span>
            <?php if ($veh): ?>
              <a href="<?= e(base_url('admin_vehicle_history.php?vehicle_id=' . $veh['id'] . '&tab=notes')) ?>" class="text-decoration-none">
                <span class="plate" style="font-size:.85rem"><?= e($veh['license_plate'] ?? '–') ?></span>
              </a>
            <?php endif; ?>
            <span class="text-muted small ms-2"><?= e(date('Y.m.d H:i', strtotime($note['created_at']))) ?></span>
          </div>
        </div>
        <p class="mt-2 mb-0"><?= nl2br(e($note['note_text'])) ?></p>
        <?php if ($note['resolved_at']): ?>
          <p class="text-muted small mt-1 mb-0">Lezárva: <?= e(date('Y.m.d H:i', strtotime($note['resolved_at']))) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<?php require '_footer.php'; ?>

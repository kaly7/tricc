<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
require_admin();
$pdo = db();

$vehicleId = (int)($_GET['vehicle_id'] ?? 0);
$v = $vehicleId ? get_vehicle($vehicleId) : null;
if (!$v) { flash_set('err', 'Jármű nem található.'); redirect('admin_vehicles.php'); }

$tab = (string)($_GET['tab'] ?? 'checklists');
if (!in_array($tab, ['checklists','notes','assignments','transfers'])) $tab = 'checklists';

// Szűrők
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to']   ?? ''));
$perPage  = 25;
$pageNum  = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($pageNum - 1) * $perPage;

function typeLabel(string $t): string {
  return match($t) { 'daily'=>'Napi','takeover'=>'Átvételi','return'=>'Visszaadási','transfer'=>'Átadási',default=>$t };
}
function typeBadge(string $t): string {
  return match($t) { 'daily'=>'bg-primary','takeover'=>'bg-success','return'=>'bg-warning text-dark','transfer'=>'bg-info text-dark',default=>'bg-secondary' };
}
function pagerUrl(array $extra = []): string {
  $base = array_filter([
    'vehicle_id' => (int)$_GET['vehicle_id'],
    'tab'        => $_GET['tab'] ?? '',
    'date_from'  => $_GET['date_from'] ?? '',
    'date_to'    => $_GET['date_to']   ?? '',
  ]);
  return '?' . http_build_query(array_merge($base, $extra));
}

// --- Gyors számok (szűrő nélkül) ---
$totalAssignments = 0; $totalChecklists = 0; $totalNotes = 0; $openNotes = 0; $totalTransfers = 0;
try {
  $totalAssignments = (int)$pdo->prepare("SELECT COUNT(*) FROM vehicle_assignments WHERE vehicle_id=?")->execute([$vehicleId]) ? (function() use ($pdo,$vehicleId){ $s=$pdo->prepare("SELECT COUNT(*) FROM vehicle_assignments WHERE vehicle_id=?");$s->execute([$vehicleId]);return (int)$s->fetchColumn(); })() : 0;
  // gyorsabb inline:
  $s=$pdo->prepare("SELECT COUNT(*) FROM vehicle_assignments WHERE vehicle_id=?"); $s->execute([$vehicleId]); $totalAssignments=(int)$s->fetchColumn();
  $s=$pdo->prepare("SELECT COUNT(*) FROM checklist_submissions WHERE vehicle_id=?"); $s->execute([$vehicleId]); $totalChecklists=(int)$s->fetchColumn();
  $s=$pdo->prepare("SELECT COUNT(*) FROM vehicle_notes WHERE vehicle_id=?"); $s->execute([$vehicleId]); $totalNotes=(int)$s->fetchColumn();
  $s=$pdo->prepare("SELECT COUNT(*) FROM vehicle_notes WHERE vehicle_id=? AND status='open'"); $s->execute([$vehicleId]); $openNotes=(int)$s->fetchColumn();
  $s=$pdo->prepare("SELECT COUNT(*) FROM vehicle_transfers vt JOIN vehicle_assignments va ON va.id=vt.assignment_id WHERE va.vehicle_id=?"); $s->execute([$vehicleId]); $totalTransfers=(int)$s->fetchColumn();
} catch (Throwable $e) {}

// ---- ADATOK BETÖLTÉSE tab szerint ----

// CHECKLISTEK
$checklists = []; $clTotal = 0; $viewClDetail = null; $viewClAnswers = []; $viewClPhotos = [];
if ($tab === 'checklists') {
  $viewClId = (int)($_GET['view_cl'] ?? 0);

  // Szűrt összesítő
  $w = ['cs.vehicle_id=?']; $p = [$vehicleId];
  if ($dateFrom !== '') { $w[] = 'DATE(cs.submitted_at) >= ?'; $p[] = $dateFrom; }
  if ($dateTo   !== '') { $w[] = 'DATE(cs.submitted_at) <= ?'; $p[] = $dateTo; }
  $where = implode(' AND ', $w);

  try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM checklist_submissions cs WHERE $where");
    $s->execute($p); $clTotal = (int)$s->fetchColumn();

    $s = $pdo->prepare("
      SELECT cs.id, cs.type, cs.submitted_at, cs.employee_id, cs.odometer_km, cs.hour_meter,
             COUNT(ca.id) AS total_items,
             SUM(CASE WHEN ca.is_ok=0 THEN 1 ELSE 0 END) AS failed_items,
             COUNT(DISTINCT cp.id) AS photo_count
      FROM checklist_submissions cs
      LEFT JOIN checklist_answers ca ON ca.submission_id=cs.id
      LEFT JOIN checklist_photos cp ON cp.submission_id=cs.id
      WHERE $where
      GROUP BY cs.id
      ORDER BY cs.submitted_at DESC
      LIMIT $perPage OFFSET $offset
    ");
    $s->execute($p);
    $checklists = $s->fetchAll();
  } catch (Throwable $e) {}

  // Részletes nézet
  if ($viewClId > 0) {
    try {
      $s = $pdo->prepare("SELECT * FROM checklist_submissions WHERE id=? AND vehicle_id=? LIMIT 1");
      $s->execute([$viewClId, $vehicleId]);
      $viewClDetail = $s->fetch() ?: null;

      if ($viewClDetail) {
        $s = $pdo->prepare("
          SELECT ca.is_ok, ca.note, ct.item_text
          FROM checklist_answers ca
          JOIN checklist_templates ct ON ct.id=ca.template_item_id
          WHERE ca.submission_id=?
          ORDER BY ct.item_order, ct.id
        ");
        $s->execute([$viewClId]);
        $viewClAnswers = $s->fetchAll();

        $s = $pdo->prepare("SELECT * FROM checklist_photos WHERE submission_id=?");
        $s->execute([$viewClId]);
        $viewClPhotos = $s->fetchAll();
      }
    } catch (Throwable $e) {}
  }
}

// HIBAJEGYEK
$notes = []; $notesTotal = 0;
if ($tab === 'notes') {
  $w = ['vehicle_id=?']; $p = [$vehicleId];
  if ($dateFrom !== '') { $w[] = 'DATE(created_at) >= ?'; $p[] = $dateFrom; }
  if ($dateTo   !== '') { $w[] = 'DATE(created_at) <= ?'; $p[] = $dateTo; }
  $filterStatus = trim((string)($_GET['status'] ?? ''));
  if (in_array($filterStatus, ['open','resolved'])) { $w[] = 'status=?'; $p[] = $filterStatus; }
  $where = implode(' AND ', $w);
  try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM vehicle_notes WHERE $where");
    $s->execute($p); $notesTotal = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT * FROM vehicle_notes WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    $s->execute($p); $notes = $s->fetchAll();
  } catch (Throwable $e) {}
}

// HOZZÁRENDELÉSEK
$assignments = []; $assignTotal = 0;
if ($tab === 'assignments') {
  try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM vehicle_assignments WHERE vehicle_id=?");
    $s->execute([$vehicleId]); $assignTotal = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT * FROM vehicle_assignments WHERE vehicle_id=? ORDER BY assigned_at DESC LIMIT $perPage OFFSET $offset");
    $s->execute([$vehicleId]); $assignments = $s->fetchAll();
  } catch (Throwable $e) {}
}

// ÁTADÁSOK
$transfers = []; $transferTotal = 0;
if ($tab === 'transfers') {
  $w = ['va.vehicle_id=?']; $p = [$vehicleId];
  if ($dateFrom !== '') { $w[] = 'DATE(vt.initiated_at) >= ?'; $p[] = $dateFrom; }
  if ($dateTo   !== '') { $w[] = 'DATE(vt.initiated_at) <= ?'; $p[] = $dateTo; }
  $where = implode(' AND ', $w);
  try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM vehicle_transfers vt JOIN vehicle_assignments va ON va.id=vt.assignment_id WHERE $where");
    $s->execute($p); $transferTotal = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT vt.* FROM vehicle_transfers vt JOIN vehicle_assignments va ON va.id=vt.assignment_id WHERE $where ORDER BY vt.initiated_at DESC LIMIT $perPage OFFSET $offset");
    $s->execute($p); $transfers = $s->fetchAll();
  } catch (Throwable $e) {}
}

$failedChecklists = 0;
try {
  $s = $pdo->prepare("SELECT COUNT(DISTINCT cs.id) FROM checklist_submissions cs JOIN checklist_answers ca ON ca.submission_id=cs.id WHERE cs.vehicle_id=? AND ca.is_ok=0");
  $s->execute([$vehicleId]); $failedChecklists = (int)$s->fetchColumn();
} catch (Throwable $e) {}

$title = 'Előzmények – ' . vehicle_label($v);
$page  = 'admin_vehicles';
require '_header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= e(base_url('admin_vehicles.php')) ?>" class="btn btn-outline-secondary btn-sm">← Vissza</a>
  <div>
    <h5 class="mb-0">
      <span class="plate" style="font-size:1rem"><?= e($v['license_plate'] ?? '–') ?></span>
      <span class="ms-2"><?= e($v['make'] . ' ' . $v['model']) ?></span>
    </h5>
    <?php if (!empty($v['vehicle_identifier'])): ?>
      <div class="text-muted small"><?= e($v['vehicle_identifier']) ?></div>
    <?php endif; ?>
  </div>
</div>

<!-- Gyors statisztika -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="card text-center py-2">
      <div class="fs-4 fw-bold text-primary"><?= $totalChecklists ?></div>
      <div class="text-muted small">Checklist összesen</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2">
      <div class="fs-4 fw-bold <?= $openNotes > 0 ? 'text-danger' : 'text-success' ?>"><?= $openNotes ?></div>
      <div class="text-muted small">Nyitott hibajegy</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2">
      <div class="fs-4 fw-bold text-secondary"><?= $totalAssignments ?></div>
      <div class="text-muted small">Hozzárendelés</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2">
      <div class="fs-4 fw-bold <?= $failedChecklists > 0 ? 'text-warning' : 'text-success' ?>"><?= $failedChecklists ?></div>
      <div class="text-muted small">Hibás checklist</div>
    </div>
  </div>
</div>

<!-- Tabok -->
<ul class="nav nav-tabs mb-0">
  <li class="nav-item"><a class="nav-link <?= $tab==='checklists'?'active':'' ?>" href="<?= e(pagerUrl(['tab'=>'checklists','p'=>1])) ?>">Checklistek <span class="badge bg-secondary ms-1"><?= $totalChecklists ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='notes'?'active':'' ?>" href="<?= e(pagerUrl(['tab'=>'notes','p'=>1])) ?>">Hibajegyek <span class="badge <?= $openNotes>0?'bg-danger':'bg-secondary' ?> ms-1"><?= $totalNotes ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='assignments'?'active':'' ?>" href="<?= e(pagerUrl(['tab'=>'assignments','p'=>1])) ?>">Hozzárendelések <span class="badge bg-secondary ms-1"><?= $totalAssignments ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='transfers'?'active':'' ?>" href="<?= e(pagerUrl(['tab'=>'transfers','p'=>1])) ?>">Átadások <span class="badge bg-secondary ms-1"><?= $totalTransfers ?></span></a></li>
</ul>

<!-- Szűrő sáv (checklistek, hibajegyek, átadások tabon) -->
<?php if (in_array($tab, ['checklists','notes','transfers'])): ?>
<div class="bg-light border border-top-0 rounded-bottom p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="col-auto">
      <label class="form-label form-label-sm mb-1">Dátumtól</label>
      <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
    </div>
    <div class="col-auto">
      <label class="form-label form-label-sm mb-1">Dátumig</label>
      <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
    </div>
    <?php if ($tab === 'notes'): ?>
    <div class="col-auto">
      <label class="form-label form-label-sm mb-1">Állapot</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">Összes</option>
        <option value="open"     <?= ($_GET['status']??'')==='open'     ? 'selected' : '' ?>>Nyitott</option>
        <option value="resolved" <?= ($_GET['status']??'')==='resolved' ? 'selected' : '' ?>>Lezárt</option>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-auto">
      <button type="submit" class="btn btn-secondary btn-sm">Szűrés</button>
      <a href="<?= e(pagerUrl(['tab'=>$tab,'p'=>1,'date_from'=>'','date_to'=>'','status'=>''])) ?>" class="btn btn-outline-secondary btn-sm">Törlés</a>
    </div>
    <?php if ($dateFrom || $dateTo): ?>
      <div class="col-auto text-muted small align-self-end">
        Szűrt találat: <strong><?= $tab==='checklists'?$clTotal:($tab==='notes'?$notesTotal:$transferTotal) ?></strong>
      </div>
    <?php endif; ?>
  </form>
</div>
<?php else: ?>
<div class="mb-3"></div>
<?php endif; ?>

<?php
// Lapozó helper
function pager(int $total, int $perPage, int $current): string {
  $pages = (int)ceil($total / $perPage);
  if ($pages <= 1) return '';
  $html = '<nav class="mt-3"><ul class="pagination pagination-sm mb-0">';
  $start = max(1, $current - 3);
  $end   = min($pages, $current + 3);
  if ($current > 1) $html .= '<li class="page-item"><a class="page-link" href="' . e(pagerUrl(['p'=>$current-1])) . '">‹</a></li>';
  if ($start > 1)   $html .= '<li class="page-item disabled"><span class="page-link">1</span></li><li class="page-item disabled"><span class="page-link">…</span></li>';
  for ($i = $start; $i <= $end; $i++) {
    $html .= '<li class="page-item ' . ($i===$current?'active':'') . '"><a class="page-link" href="' . e(pagerUrl(['p'=>$i])) . '">' . $i . '</a></li>';
  }
  if ($end < $pages) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li><li class="page-item"><a class="page-link" href="' . e(pagerUrl(['p'=>$pages])) . '">' . $pages . '</a></li>';
  if ($current < $pages) $html .= '<li class="page-item"><a class="page-link" href="' . e(pagerUrl(['p'=>$current+1])) . '">›</a></li>';
  $html .= '</ul></nav>';
  return $html;
}
?>

<!-- ===== CHECKLISTEK ===== -->
<?php if ($tab === 'checklists'): ?>

<?php if ($viewClDetail): ?>
  <!-- Részletes nézet -->
  <div class="card mb-3 border-primary" id="cl-detail">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>
        <span class="badge <?= typeBadge($viewClDetail['type']) ?>"><?= typeLabel($viewClDetail['type']) ?></span>
        <strong class="ms-2"><?= e(employee_name((int)$viewClDetail['employee_id'])) ?></strong>
        <span class="text-muted ms-2"><?= e(date('Y.m.d H:i', strtotime($viewClDetail['submitted_at']))) ?></span>
        <?php if ($viewClDetail['odometer_km'] !== null): ?>
          <span class="ms-2 text-muted small">📍 <?= number_format((int)$viewClDetail['odometer_km'],0,'.',' ') ?> km</span>
        <?php endif; ?>
        <?php if ($viewClDetail['hour_meter'] !== null): ?>
          <span class="ms-1 text-muted small">⏱ <?= e($viewClDetail['hour_meter']) ?> h</span>
        <?php endif; ?>
      </span>
      <a href="<?= e(pagerUrl(['tab'=>'checklists','p'=>$pageNum])) ?>" class="btn-close" title="Bezár"></a>
    </div>
    <div class="card-body">
      <?php if (!empty($viewClAnswers)): ?>
        <div class="list-group mb-3">
        <?php foreach ($viewClAnswers as $ans): ?>
          <div class="list-group-item py-2 <?= !$ans['is_ok'] ? 'list-group-item-danger' : '' ?>">
            <span class="me-2"><?= $ans['is_ok'] ? '✅' : '❌' ?></span>
            <?= e($ans['item_text']) ?>
            <?php if (!empty($ans['note'])): ?>
              <div class="text-muted small ms-4 mt-1"><?= e($ans['note']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-muted">Nincs tételes válasz (sablon nélkül lett kitöltve).</p>
      <?php endif; ?>
      <?php if (!empty($viewClDetail['notes'])): ?>
        <div class="alert alert-light py-2 mb-3"><strong>Megjegyzés:</strong> <?= nl2br(e($viewClDetail['notes'])) ?></div>
      <?php endif; ?>
      <?php if (!empty($viewClPhotos)): ?>
        <div class="photo-grid">
        <?php foreach ($viewClPhotos as $ph): ?>
          <a href="<?= e(base_url('photo.php?f=' . urlencode($ph['filename']))) ?>" target="_blank">
            <img src="<?= e(base_url('photo.php?f=' . urlencode($ph['filename']) . '&thumb=1')) ?>" class="photo-thumb" alt="fotó">
          </a>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (empty($checklists)): ?>
  <div class="alert alert-info">Nincs checklist a megadott feltételekkel.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-sm">
      <thead class="table-light">
        <tr><th>Dátum</th><th>Típus</th><th>Dolgozó</th><th>Km-óra</th><th>Tételek</th><th>Fotók</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($checklists as $cl): ?>
        <tr class="<?= (int)$cl['failed_items']>0 ? 'table-warning' : '' ?>">
          <td class="text-nowrap"><?= e(date('Y.m.d H:i', strtotime($cl['submitted_at']))) ?></td>
          <td><span class="badge <?= typeBadge($cl['type']) ?>"><?= typeLabel($cl['type']) ?></span></td>
          <td><?= e(employee_name((int)$cl['employee_id'])) ?></td>
          <td><?= $cl['odometer_km'] !== null ? number_format((int)$cl['odometer_km'],0,'.',' ').' km' : '–' ?></td>
          <td>
            <?php if ((int)$cl['total_items'] > 0): ?>
              <?php if ((int)$cl['failed_items'] > 0): ?>
                <span class="text-danger fw-bold">⚠ <?= (int)$cl['failed_items'] ?> hiba / <?= (int)$cl['total_items'] ?></span>
              <?php else: ?>
                <span class="text-success">✓ <?= (int)$cl['total_items'] ?> OK</span>
              <?php endif; ?>
            <?php else: ?>–<?php endif; ?>
          </td>
          <td><?= (int)$cl['photo_count'] > 0 ? '<span class="badge bg-info text-dark">📷 '.(int)$cl['photo_count'].'</span>' : '–' ?></td>
          <td>
            <a href="<?= e(pagerUrl(['tab'=>'checklists','view_cl'=>$cl['id'],'p'=>$pageNum]).'#cl-detail') ?>" class="btn btn-outline-secondary btn-sm">Részletek</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager($clTotal, $perPage, $pageNum) ?>
<?php endif; ?>

<!-- ===== HIBAJEGYEK ===== -->
<?php elseif ($tab === 'notes'): ?>

<?php if (empty($notes)): ?>
  <div class="alert alert-info">Nincs hibajegy a megadott feltételekkel.</div>
<?php else: ?>
  <?php foreach ($notes as $note): ?>
  <div class="card mb-2 <?= $note['status']==='open' ? 'border-warning' : 'border-success opacity-75' ?>">
    <div class="card-body py-2">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <span class="badge <?= $note['status']==='open' ? 'bg-warning text-dark' : 'bg-success' ?> me-2">
            <?= $note['status']==='open' ? 'Nyitott' : 'Lezárt' ?>
          </span>
          <strong><?= e(employee_name((int)$note['employee_id'])) ?></strong>
          <span class="text-muted small ms-2"><?= e(date('Y.m.d H:i', strtotime($note['created_at']))) ?></span>
        </div>
        <?php if ($note['status']==='open'): ?>
        <form method="post" action="notes.php">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="resolve">
          <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
          <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
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
  <?= pager($notesTotal, $perPage, $pageNum) ?>
<?php endif; ?>

<!-- ===== HOZZÁRENDELÉSEK ===== -->
<?php elseif ($tab === 'assignments'): ?>

<?php if (empty($assignments)): ?>
  <div class="alert alert-info">Nincs hozzárendelési előzmény.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead class="table-light">
        <tr><th>Dolgozó</th><th>Kiosztva</th><th>Visszaadva</th><th>Időtartam</th><th>Állapot</th></tr>
      </thead>
      <tbody>
      <?php foreach ($assignments as $asgn): ?>
        <tr>
          <td>
            <a href="<?= e(base_url('admin_employee_history.php?employee_id=' . $asgn['employee_id'])) ?>" class="text-decoration-none">
              <?= e(employee_name((int)$asgn['employee_id'])) ?>
            </a>
          </td>
          <td><?= e(date('Y.m.d', strtotime($asgn['assigned_at']))) ?></td>
          <td><?= $asgn['returned_at'] ? e(date('Y.m.d', strtotime($asgn['returned_at']))) : '–' ?></td>
          <td class="text-muted small">
            <?php $d = (new DateTime($asgn['assigned_at']))->diff($asgn['returned_at'] ? new DateTime($asgn['returned_at']) : new DateTime()); echo $d->days . ' nap'; ?>
          </td>
          <td><span class="badge <?= $asgn['status']==='active'?'bg-success':'bg-secondary' ?>"><?= $asgn['status']==='active'?'Aktív':'Visszaadva' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager($assignTotal, $perPage, $pageNum) ?>
<?php endif; ?>

<!-- ===== ÁTADÁSOK ===== -->
<?php elseif ($tab === 'transfers'): ?>

<?php if (empty($transfers)): ?>
  <div class="alert alert-info">Nincs átadási előzmény a megadott feltételekkel.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-sm">
      <thead class="table-light">
        <tr><th>Dátum</th><th>Típus</th><th>Átadó</th><th>Átvevő</th><th>Állapot</th><th>Lezárva</th></tr>
      </thead>
      <tbody>
      <?php foreach ($transfers as $tr): ?>
        <tr>
          <td class="text-nowrap"><?= e(date('Y.m.d H:i', strtotime($tr['initiated_at']))) ?></td>
          <td><span class="badge <?= $tr['type']==='return_to_fleet'?'bg-warning text-dark':'bg-info text-dark' ?>"><?= $tr['type']==='return_to_fleet'?'Telepre':'Dolgozónak' ?></span></td>
          <td><?= e(employee_name((int)$tr['from_employee_id'])) ?></td>
          <td><?= $tr['to_employee_id'] ? e(employee_name((int)$tr['to_employee_id'])) : '<span class="text-muted">járműtelep</span>' ?></td>
          <td>
            <?php $sc=match($tr['status']){'accepted'=>'bg-success','rejected'=>'bg-danger','cancelled'=>'bg-secondary',default=>'bg-warning text-dark'}; ?>
            <span class="badge <?= $sc ?>"><?= match($tr['status']){'accepted'=>'Elfogadva','rejected'=>'Elutasítva','cancelled'=>'Visszavonva',default=>'Függőben'} ?></span>
          </td>
          <td class="text-muted small"><?= $tr['responded_at'] ? e(date('Y.m.d', strtotime($tr['responded_at']))) : '–' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager($transferTotal, $perPage, $pageNum) ?>
<?php endif; ?>

<?php endif; ?>

<?php require '_footer.php'; ?>

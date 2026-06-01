<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
$u = Auth::user();
$isAdmin = isset($u['role_id']) && (int)$u['role_id']===1;
$pdo = Db::pdo();
$uid = (int)($u['id'] ?? 0);

// Divízió preferencia mentése — a layout include ELŐTT, hogy header() működjön
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_div_pref'])) {
  $divPref = json_encode([
    'ids'    => array_map('intval', (array)($_POST['div_ids'] ?? [])),
    'no_div' => isset($_POST['no_div']),
  ]);
  $pdo->prepare("INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value), updated_at=NOW()")
      ->execute([$uid, 'vehicle_div_filter', $divPref]);
  $qs = http_build_query(array_filter(['q' => $_POST['q'] ?? '', 'archived' => $_POST['archived'] ?? '']));
  header('Location: /vehicles.php' . ($qs ? '?' . $qs : '')); exit;
}

require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

// Divízió preferencia betöltése
$savedDivRow = $pdo->prepare("SELECT pref_value FROM user_preferences WHERE user_id=? AND pref_key='vehicle_div_filter' LIMIT 1");
$savedDivRow->execute([$uid]);
$savedPref   = json_decode((string)($savedDivRow->fetchColumn() ?: '{}'), true) ?? [];
// Régi formátum (tömb) visszafelé kompatibilitás
if (array_is_list($savedPref ?? [])) $savedPref = ['ids' => $savedPref, 'no_div' => false];
$filterDivIds  = array_map('intval', (array)($savedPref['ids']    ?? []));
$filterNoDiv   = (bool)($savedPref['no_div'] ?? false);

$allDivisions = $pdo->query("SELECT id, name FROM vehicle_divisions WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$q = trim((string)($_GET['q'] ?? ''));
$show_archived = isset($_GET['archived']) && $_GET['archived']=='1';

$where = ' WHERE 1=1 ';
$params = [];
if (!$show_archived) { $where .= ' AND v.archived=0 '; }
if ($q !== '') {
  $where .= ' AND (v.license_plate LIKE ? OR v.vehicle_identifier LIKE ? OR v.make LIKE ? OR v.model LIKE ?) ';
  $like = '%'.$q.'%';
  $params[]=$like; $params[]=$like; $params[]=$like; $params[]=$like;
}
if (!empty($filterDivIds) && !$filterNoDiv) {
  $in = implode(',', $filterDivIds);
  $where .= " AND v.division_id IN ($in) ";
} elseif ($filterNoDiv && empty($filterDivIds)) {
  $where .= " AND v.division_id IS NULL ";
} elseif ($filterNoDiv && !empty($filterDivIds)) {
  $in = implode(',', $filterDivIds);
  $where .= " AND (v.division_id IN ($in) OR v.division_id IS NULL) ";
}

$sql = "SELECT v.*, vt.name AS type_name, d.name AS division_name
        FROM vehicles v
        JOIN vehicle_types vt ON vt.id=v.vehicle_type_id
        LEFT JOIN vehicle_divisions d ON d.id=v.division_id
        $where
        ORDER BY v.archived ASC, v.license_plate ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fuelLabel($f){
  return match($f){
    'petrol' => 'benzin',
    'diesel' => 'diesel',
    'electric' => 'elektromos',
    'hybrid' => 'hibrid',
    default => $f
  };
}
function fmtKm($n){ return number_format((int)$n, 0, '.', ' '); }

// Determine alert severity for a vehicle row
// returns: '' | 'warning' | 'danger'
function vehicleSeverity(array $v): string {
  $odo = (int)($v['odometer_km'] ?? 0);

  // Oil
  $oilInt = (int)($v['oil_interval_km'] ?? 0);
  $lastOilKm = isset($v['last_oil_km']) && $v['last_oil_km']!==null ? (int)$v['last_oil_km'] : null;
  $oilRem = null;
  if ($lastOilKm!==null && $oilInt>0) {
    $oilRem = ($lastOilKm + $oilInt) - $odo;
  }

  // Service km
  $srvIntKm = isset($v['service_interval_km']) && $v['service_interval_km']!==null ? (int)$v['service_interval_km'] : null;
  $lastSrvKm = isset($v['last_service_km']) && $v['last_service_km']!==null ? (int)$v['last_service_km'] : null;
  $srvRemKm = null;
  if ($lastSrvKm!==null && $srvIntKm!==null && $srvIntKm>0) {
    $srvRemKm = ($lastSrvKm + $srvIntKm) - $odo;
  }

  // Service time (days)
  $srvIntMo = isset($v['service_interval_months']) && $v['service_interval_months']!==null ? (int)$v['service_interval_months'] : null;
  $lastSrvDate = $v['last_service_date'] ?? null;
  $srvRemDays = null;
  if ($lastSrvDate && $srvIntMo!==null && $srvIntMo>0) {
    try {
      $d = new DateTime($lastSrvDate);
      $d->modify('+'.$srvIntMo.' months');
      $due = $d;
      $now = new DateTime();
      $srvRemDays = (int)$now->diff($due)->format('%r%a');
    } catch (Throwable $e) {}
  }

  // Danger if any overdue
  if (($oilRem!==null && $oilRem < 0) || ($srvRemKm!==null && $srvRemKm < 0) || ($srvRemDays!==null && $srvRemDays < 0)) {
    return 'danger';
  }
  // Warning if near due (thresholds)
  if (($oilRem!==null && $oilRem <= 500) || ($srvRemKm!==null && $srvRemKm <= 500) || ($srvRemDays!==null && $srvRemDays <= 14)) {
    return 'warning';
  }
  return '';
}

function severityIcon(string $sev): string {
  if ($sev==='danger')  return '<span class="me-2" title="Lejárt esedékesség" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#dc3545;"></span>';
  if ($sev==='warning') return '<span class="me-2" title="Közelgő esedékesség" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ffc107;"></span>';
  return '';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Jármű nyilvántartás</h1>
  <div class="d-flex gap-2">
    <?php if ($isAdmin): ?>
      <a class="btn btn-outline-secondary" href="/vehicle_types.php">Fajták</a>
      <a class="btn btn-outline-secondary" href="/vehicle_fuel_import.php">Üzemanyag import</a>
      <a class="btn btn-outline-secondary" href="/vehicle_import.php">Jármű import</a>
      <a class="btn btn-outline-warning" href="/vehicle_gps_missing.php">GPS hiány</a>
      <a class="btn btn-primary" href="/vehicle_create.php">Új jármű</a>
    <?php endif; ?>
  </div>
</div>

<div class="card p-3 mb-3">
  <form method="post">
    <input type="hidden" name="save_div_pref" value="1">
    <input type="hidden" name="q" value="<?= h($q) ?>">
    <input type="hidden" name="archived" value="<?= $show_archived ? '1' : '' ?>">
    <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
      <span class="text-muted small fw-semibold">Divízió:</span>
      <?php foreach ($allDivisions as $div): ?>
        <div class="form-check form-check-inline mb-0">
          <input class="form-check-input" type="checkbox" name="div_ids[]"
            id="vdiv<?= (int)$div['id'] ?>" value="<?= (int)$div['id'] ?>"
            <?= in_array((int)$div['id'], $filterDivIds, true) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="vdiv<?= (int)$div['id'] ?>"><?= h($div['name']) ?></label>
        </div>
      <?php endforeach; ?>
      <div class="form-check form-check-inline mb-0">
        <input class="form-check-input" type="checkbox" name="no_div" id="vdiv_none"
          <?= $filterNoDiv ? 'checked' : '' ?>>
        <label class="form-check-label small fst-italic" for="vdiv_none">Nincs divízió</label>
      </div>
      <button type="submit" class="btn btn-sm btn-outline-secondary">Mentés</button>
      <?php if (!empty($filterDivIds) || $filterNoDiv): ?>
        <span class="badge bg-primary"><?= count($filterDivIds) + ($filterNoDiv ? 1 : 0) ?> szűrő aktív</span>
      <?php endif; ?>
    </div>
  </form>
  <hr class="my-2">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-8">
      <label class="form-label">Keresés (rendszám, gyártmány, típus)</label>
      <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="pl. ABC-123, Ford, Transit">
    </div>
    <div class="col-md-2">
      <div class="form-check form-switch mt-4">
        <input class="form-check-input" type="checkbox" name="archived" value="1" id="arch" <?= $show_archived?'checked':'' ?>>
        <label class="form-check-label" for="arch">Archiváltak is</label>
      </div>
    </div>
    <div class="col-md-2 d-grid">
      <button class="btn btn-primary">Szűrés</button>
    </div>
  </form>
</div>

<div class="card p-0">
  <table class="table table-striped m-0 align-middle">
    <thead>
      <tr>
        <th>Azonosító</th>
        <th>Gyártmány</th>
        <th>Típus</th>
        <th>Divízió</th>
        <th>Üzemanyag</th>
        <th>Fajta</th>
        <th class="text-end">Km óra</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): $sev = vehicleSeverity($r); ?>
        <tr>
          <td class="fw-semibold">
            <?= severityIcon($sev) ?>
            <?php $rowLabel = trim((string)($r['license_plate'] ?? '')) !== '' ? (string)$r['license_plate'] : (string)($r['vehicle_identifier'] ?? ''); ?>
            <a class="text-decoration-none" href="/vehicle.php?id=<?= (int)$r['id'] ?>"><?= h($rowLabel) ?></a>
            <?= ((int)$r['archived']===1)?'<span class="badge bg-secondary ms-1">archiv</span>':'' ?>
          </td>
          <td><?= h($r['make']) ?></td>
          <td><?= h($r['model']) ?></td>
          <td class="text-muted small"><?= h($r['division_name'] ?? '–') ?></td>
          <td><?= h(fuelLabel($r['fuel_type'])) ?></td>
          <td><?= h($r['type_name']) ?></td>
          <td class="text-end"><?= fmtKm((int)$r['odometer_km']) ?></td>
          <td class="text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="/vehicle.php?id=<?= (int)$r['id'] ?>">Megnyit</a>
            <a class="btn btn-sm btn-outline-secondary" href="/vehicle_edit.php?id=<?= (int)$r['id'] ?>">Szerkesztés</a>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?>
        <tr><td colspan="8" class="text-muted">Nincs találat.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>

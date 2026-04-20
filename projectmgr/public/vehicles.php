<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
$u = Auth::user();
$isAdmin = isset($u['role_id']) && (int)$u['role_id']===1;
$pdo = Db::pdo();

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

$sql = "SELECT v.*, vt.name AS type_name
        FROM vehicles v
        JOIN vehicle_types vt ON vt.id=v.vehicle_type_id
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
      <a class="btn btn-primary" href="/vehicle_create.php">Új jármű</a>
    <?php endif; ?>
  </div>
</div>

<div class="card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-8">
      <label class="form-label">Keresés (rendszám, gyártmány, típus)</label>
      <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="pl. ABC-123, Ford, Transit">
    </div>
    <div class="col-md-2">
      <div class="form-check form-switch">
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
        <th>Üzemanyag</th>
        <th>Fajta</th>
        <th>Tengely</th>
        <th>Km óra</th>
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
          <td><?= h(fuelLabel($r['fuel_type'])) ?></td>
          <td><?= h($r['type_name']) ?></td>
          <td><?= (int)$r['axle_count'] ?></td>
          <td><?= fmtKm((int)$r['odometer_km']) ?></td>
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

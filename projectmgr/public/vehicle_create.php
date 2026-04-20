<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$user = Auth::user();
$isAdmin = isset($user['role_id']) && (int)$user['role_id'] === 1;
if (!$isAdmin) { http_response_code(403); exit('Nincs jogosultság.'); }

$types = $pdo->query("SELECT id, name FROM vehicle_types WHERE is_active=1 ORDER BY sort, name")->fetchAll(PDO::FETCH_ASSOC);
$divisions = $pdo->query("SELECT id, name FROM vehicle_divisions WHERE is_active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!\App\Csrf::check($_POST['csrf_token'] ?? null)) {
	http_response_code(400);
	exit('CSRF hiba.');
    }
  $plate = trim((string)($_POST['license_plate'] ?? ''));
  $make  = trim((string)($_POST['make'] ?? ''));
  $model = trim((string)($_POST['model'] ?? ''));
  $fuel  = (string)($_POST['fuel_type'] ?? 'diesel');
  $typeId = (int)($_POST['vehicle_type_id'] ?? 0);
  $divisionId = ($_POST['division_id'] ?? '')==='' ? null : (int)$_POST['division_id'];
  $axles = max(1, min(5, (int)($_POST['axle_count'] ?? 2)));
  $odo = max(0, (int)($_POST['odometer_km'] ?? 0));
  $oilInt = max(0, (int)($_POST['oil_interval_km'] ?? 15000));
  $srvKm = ($_POST['service_interval_km'] ?? '')==='' ? null : max(0, (int)$_POST['service_interval_km']);
  $srvMo = ($_POST['service_interval_months'] ?? '')==='' ? null : max(0, (int)$_POST['service_interval_months']);

  $fuelAllowed = ['petrol','diesel','electric','hybrid'];
  if (!in_array($fuel, $fuelAllowed, true)) $fuel='diesel';
  if ($plate==='') $err='A rendszám kötelező.';
  if ($typeId<=0) $err='A fajta kötelező.';

  if (!$err) {
    try {
      $pdo->beginTransaction();
      $st = $pdo->prepare("INSERT INTO vehicles (license_plate, make, model, fuel_type, vehicle_type_id, division_id, axle_count, odometer_km, oil_interval_km, service_interval_km, service_interval_months)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)");
      $st->execute([$plate,$make,$model,$fuel,$typeId,$divisionId,$axles,$odo,$oilInt,$srvKm,$srvMo]);
      $vid = (int)$pdo->lastInsertId();

      $axSt = $pdo->prepare("INSERT INTO vehicle_axles (vehicle_id, axle_no, wheels_count) VALUES (?,?,?)");
      for ($i=1;$i<=$axles;$i++) $axSt->execute([$vid,$i,2]);

      $pdo->commit();
      Helpers::flash('success','Jármű létrehozva.');
      header('Location: /vehicle.php?id='.$vid); exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = 'Hiba mentés közben: '.$e->getMessage();
    }
  }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Új jármű</h1>
  <a class="btn btn-outline-secondary" href="/vehicles.php">Vissza</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="card p-3">
  <form method="post" class="row g-3">
    <?= \App\Csrf::field() ?>
    <div class="col-md-3"><label class="form-label">Rendszám</label><input class="form-control" name="license_plate" required value="<?= h($_POST['license_plate'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Gyártmány</label><input class="form-control" name="make" value="<?= h($_POST['make'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Típus</label><input class="form-control" name="model" value="<?= h($_POST['model'] ?? '') ?>"></div>
    <div class="col-md-3">
      <label class="form-label">Üzemanyag</label>
      <?php $fuel = $_POST['fuel_type'] ?? 'diesel'; ?>
      <select class="form-select" name="fuel_type">
        <option value="petrol" <?= $fuel==='petrol'?'selected':'' ?>>benzin</option>
        <option value="diesel" <?= $fuel==='diesel'?'selected':'' ?>>diesel</option>
        <option value="electric" <?= $fuel==='electric'?'selected':'' ?>>elektromos</option>
        <option value="hybrid" <?= $fuel==='hybrid'?'selected':'' ?>>hibrid</option>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label">Fajta</label>
      <?php $sel=(int)($_POST['vehicle_type_id'] ?? 0); ?>
      <select class="form-select" name="vehicle_type_id" required>
        <option value="">— válassz —</option>
        <?php foreach($types as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $sel===(int)$t['id']?'selected':'' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Divízió</label>
      <?php $dsel = $_POST['division_id'] ?? ''; ?>
      <select class="form-select" name="division_id">
        <option value="">—</option>
        <?php foreach($divisions as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (string)$dsel===(string)$d['id']?'selected':'' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Tengelyek száma</label>
      <?php $ax=(int)($_POST['axle_count'] ?? 2); ?>
      <select class="form-select" name="axle_count"><?php for($i=1;$i<=5;$i++): ?><option value="<?= $i ?>" <?= $ax===$i?'selected':'' ?>><?= $i ?></option><?php endfor; ?></select>
    </div>
    <div class="col-md-3"><label class="form-label">Km óra állás</label><input class="form-control" type="number" min="0" name="odometer_km" value="<?= h($_POST['odometer_km'] ?? 0) ?>"></div>
    <div class="col-md-3"><label class="form-label">Olajcsere periódus (km)</label><input class="form-control" type="number" min="0" name="oil_interval_km" value="<?= h($_POST['oil_interval_km'] ?? 15000) ?>"></div>
    <div class="col-md-3"><label class="form-label">Szerviz periódus (km)</label><input class="form-control" type="number" min="0" name="service_interval_km" value="<?= h($_POST['service_interval_km'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">Szerviz periódus (hónap)</label><input class="form-control" type="number" min="0" name="service_interval_months" value="<?= h($_POST['service_interval_months'] ?? '') ?>"></div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-outline-secondary" href="/vehicles.php">Mégse</a>
    </div>
  </form>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>

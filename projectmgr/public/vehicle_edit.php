<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$user = Auth::user();
$isAdmin = isset($user['role_id']) && (int)$user['role_id'] === 1;
if (!$isAdmin) { http_response_code(403); exit('Nincs jogosultság.'); }

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(400); exit('Hibás ID'); }

$st = $pdo->prepare("SELECT * FROM vehicles WHERE id=?");
$st->execute([$id]);
$v = $st->fetch(PDO::FETCH_ASSOC);
if (!$v) { http_response_code(404); exit('Jármű nem található'); }

$ax = $pdo->prepare("SELECT axle_no, wheels_count FROM vehicle_axles WHERE vehicle_id=? ORDER BY axle_no");
$ax->execute([$id]);
$axRows = $ax->fetchAll(PDO::FETCH_ASSOC);

$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!\App\Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('CSRF hiba.');
    }
    $plate = trim((string)($_POST['license_plate'] ?? ''));
    $plate = $plate !== '' ? $plate : null;
    $make  = trim((string)($_POST['make'] ?? ''));
    $model = trim((string)($_POST['model'] ?? ''));
    $fuel  = (string)($_POST['fuel_type'] ?? $v['fuel_type']);
    $typeId = (int)($_POST['vehicle_type_id'] ?? $v['vehicle_type_id']);
    $divisionId = ($_POST['division_id'] ?? '')==='' ? null : (int)$_POST['division_id'];
    $odo = max(0,(int)($_POST['odometer_km'] ?? $v['odometer_km']));
    $oilInt = max(0,(int)($_POST['oil_interval_km'] ?? $v['oil_interval_km']));
    $srvKm = ($_POST['service_interval_km'] ?? '')==='' ? null : max(0,(int)$_POST['service_interval_km']);
    $srvMo = ($_POST['service_interval_months'] ?? '')==='' ? null : max(0,(int)$_POST['service_interval_months']);
    $archived = isset($_POST['archived']) ? 1 : 0;
    $multialarm = isset($_POST['multialarm_enabled']) ? 1 : 0;

    $wheelCounts = $_POST['wheels_count'] ?? [];
    if (!is_array($wheelCounts)) $wheelCounts = [];

    $fuelAllowed = ['petrol','diesel','electric','hybrid'];
    if (!in_array($fuel, $fuelAllowed, true)) $fuel=$v['fuel_type'];
    if ($typeId<=0) $err='A fajta kötelező.';

    if (!$err) {
        try {
            $pdo->beginTransaction();
            $up = $pdo->prepare("UPDATE vehicles SET license_plate=?, make=?, model=?, fuel_type=?, vehicle_type_id=?, division_id=?, odometer_km=?, oil_interval_km=?, service_interval_km=?, service_interval_months=?, archived=?, multialarm_enabled=? WHERE id=?");
            $up->execute([$plate,$make,$model,$fuel,$typeId,$divisionId,$odo,$oilInt,$srvKm,$srvMo,$archived,$multialarm,$id]);

            $axUp = $pdo->prepare("UPDATE vehicle_axles SET wheels_count=? WHERE vehicle_id=? AND axle_no=?");
            foreach ($axRows as $a) {
                $no = (int)$a['axle_no'];
                $wc = (int)($wheelCounts[$no] ?? $a['wheels_count']);
                if ($wc < 1) $wc = (int)$a['wheels_count'];
                $axUp->execute([$wc,$id,$no]);
            }

            $pdo->commit();
            Helpers::flash('success','Mentve.');
            header('Location: /vehicle.php?id='.$id); exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Hiba mentés közben: '.$e->getMessage();
        }
    }
}

$types = $pdo->query("SELECT id, name FROM vehicle_types WHERE is_active=1 ORDER BY sort, name")->fetchAll(PDO::FETCH_ASSOC);
$divisions = $pdo->query("SELECT id, name, is_active FROM vehicle_divisions ORDER BY is_active DESC, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <?php $editLabel = trim((string)($v['license_plate'] ?? '')) !== '' ? (string)$v['license_plate'] : (string)($v['vehicle_identifier'] ?? ''); ?>
  <h1 class="h5 mb-0">Jármű szerkesztése: <?= h($editLabel) ?></h1>
  <a class="btn btn-outline-secondary" href="/vehicle.php?id=<?= (int)$id ?>">Vissza</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="card p-3">
  <form method="post" class="row g-3">
    <?= \App\Csrf::field() ?>
    <div class="col-md-3"><label class="form-label">Rendszám</label><input class="form-control" name="license_plate" value="<?= h($_POST['license_plate'] ?? $v['license_plate']) ?>"><div class="form-text">Ha nincs rendszám, üresen maradhat.</div></div>
    <div class="col-md-3"><label class="form-label">Belső azonosító</label><input class="form-control" value="<?= h($v['vehicle_identifier'] ?? '') ?>" readonly></div>
    <div class="col-md-3"><label class="form-label">Gyártmány</label><input class="form-control" name="make" value="<?= h($_POST['make'] ?? $v['make']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Típus</label><input class="form-control" name="model" value="<?= h($_POST['model'] ?? $v['model']) ?>"></div>
    <div class="col-md-3">
      <label class="form-label">Üzemanyag</label>
      <?php $fuel = $_POST['fuel_type'] ?? $v['fuel_type']; ?>
      <select class="form-select" name="fuel_type">
        <option value="petrol" <?= $fuel==='petrol'?'selected':'' ?>>benzin</option>
        <option value="diesel" <?= $fuel==='diesel'?'selected':'' ?>>diesel</option>
        <option value="electric" <?= $fuel==='electric'?'selected':'' ?>>elektromos</option>
        <option value="hybrid" <?= $fuel==='hybrid'?'selected':'' ?>>hibrid</option>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label">Fajta</label>
      <?php $sel=(int)($_POST['vehicle_type_id'] ?? $v['vehicle_type_id']); ?>
      <select class="form-select" name="vehicle_type_id" required>
        <?php foreach($types as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $sel===(int)$t['id']?'selected':'' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Divízió</label>
      <?php $selDiv = (int)($_POST['division_id'] ?? (int)($v['division_id'] ?? 0)); ?>
      <select class="form-select" name="division_id">
        <option value="">— nincs —</option>
        <?php foreach($divisions as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $selDiv===(int)$d['id']?'selected':'' ?>><?= h($d['name']) ?><?= ((int)$d['is_active']===0)?' (letiltva)':'' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4"><label class="form-label">Km óra állás</label><input class="form-control" type="number" min="0" name="odometer_km" value="<?= h($_POST['odometer_km'] ?? $v['odometer_km']) ?>"></div>
    <div class="col-md-4"><label class="form-label">Olajcsere periódus (km)</label><input class="form-control" type="number" min="0" name="oil_interval_km" value="<?= h($_POST['oil_interval_km'] ?? $v['oil_interval_km']) ?>"></div>

    <div class="col-md-4"><label class="form-label">Szerviz periódus (km)</label><input class="form-control" type="number" min="0" name="service_interval_km" value="<?= h($_POST['service_interval_km'] ?? $v['service_interval_km']) ?>"></div>
    <div class="col-md-4"><label class="form-label">Szerviz periódus (hónap)</label><input class="form-control" type="number" min="0" name="service_interval_months" value="<?= h($_POST['service_interval_months'] ?? $v['service_interval_months']) ?>"></div>
    <div class="col-md-2 d-flex align-items-end">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="archived" value="1" id="arch" <?= ((int)($_POST['archived'] ?? $v['archived'])===1)?'checked':'' ?>>
        <label class="form-check-label" for="arch">Archivált</label>
      </div>
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="multialarm_enabled" value="1" id="multialarm_enabled" <?= ((int)(($_POST['multialarm_enabled'] ?? null) !== null ? $_POST['multialarm_enabled'] : ($v['multialarm_enabled'] ?? 0))===1)?'checked':'' ?>>
        <label class="form-check-label" for="multialarm_enabled">Multi Alarm GPS</label>
      </div>
    </div>

    <div class="col-12">
      <h2 class="h6">Tengely konfiguráció</h2>
      <table class="table table-sm">
        <thead><tr><th>Tengely</th><th>Kerekek száma</th></tr></thead>
        <tbody>
          <?php foreach($axRows as $a): $no=(int)$a['axle_no']; $cur=(int)($_POST['wheels_count'][$no] ?? $a['wheels_count']); ?>
            <tr>
              <td><?= $no ?></td>
              <td style="max-width:200px">
                <select class="form-select form-select-sm" name="wheels_count[<?= $no ?>]">
                  <option value="2" <?= $cur===2?'selected':'' ?>>2</option>
                  <option value="4" <?= $cur===4?'selected':'' ?>>4</option>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-outline-secondary" href="/vehicle.php?id=<?= (int)$id ?>">Mégse</a>
    </div>
  </form>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>

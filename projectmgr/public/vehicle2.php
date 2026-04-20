<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();
$cfg = require dirname(__DIR__).'/config/config.php';

$u = Auth::user();
$isAdmin = isset($u['role_id']) && (int)$u['role_id']===1;

$extraOpen = $isAdmin && (($_GET['extra'] ?? '') === 'open');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(400); exit('Hibás ID'); }

// Aktív fül megőrzése (szűrés / újratöltés után is)
$tab = $_GET['tab'] ?? 'issues';
$allowedTabs = ['issues','service','tires','costs','fuel','log'];
if (!in_array($tab, $allowedTabs, true)) { $tab = 'issues'; }

$st = $pdo->prepare("SELECT v.*, vt.name AS type_name
  FROM vehicles v
  LEFT JOIN vehicle_types vt ON vt.id=v.type_id
  WHERE v.id=?");
$st->execute([$id]);
$v = $st->fetch(PDO::FETCH_ASSOC);
if (!$v) { http_response_code(404); exit('Nincs ilyen jármű.'); }

// vendor list for costs filter
$vendorOptions = [];
try {
  $st = $pdo->query("SELECT DISTINCT vendor FROM vehicle_costs WHERE vendor IS NOT NULL AND vendor<>'' ORDER BY vendor");
  $vendorOptions = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}

// costs filters
$cost_from = trim((string)($_GET['cost_from'] ?? ''));
$cost_to = trim((string)($_GET['cost_to'] ?? ''));
$cost_vendor = trim((string)($_GET['cost_vendor'] ?? ''));

// --------------------- ISSUES ---------------------
$issues = [];
try {
  $st = $pdo->prepare("SELECT i.*,
    DATE_FORMAT(i.created_at, '%Y-%m-%d %H:%i') AS created_at_fmt
    FROM vehicle_issues i
    WHERE i.vehicle_id=?
    ORDER BY i.created_at DESC, i.id DESC");
  $st->execute([$id]);
  $issues = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// --------------------- SERVICE ---------------------
$services = [];
try {
  $st = $pdo->prepare("SELECT s.*,
    DATE_FORMAT(s.service_date, '%Y-%m-%d') AS service_date_fmt,
    DATE_FORMAT(s.created_at, '%Y-%m-%d %H:%i') AS created_at_fmt
    FROM vehicle_services s
    WHERE s.vehicle_id=?
    ORDER BY s.service_date DESC, s.id DESC");
  $st->execute([$id]);
  $services = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// --------------------- TIRES ---------------------
$tires = [];
try {
  $st = $pdo->prepare("SELECT t.*,
    DATE_FORMAT(t.change_date, '%Y-%m-%d') AS change_date_fmt,
    DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i') AS created_at_fmt
    FROM vehicle_tires t
    WHERE t.vehicle_id=?
    ORDER BY t.change_date DESC, t.id DESC");
  $st->execute([$id]);
  $tires = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// --------------------- COSTS ---------------------
$costs = [];
$costSum = 0.0;
try {
  $where = ["c.vehicle_id=?"];
  $params = [$id];

  if ($cost_from !== '') { $where[] = "c.cost_date >= ?"; $params[] = $cost_from; }
  if ($cost_to !== '') { $where[] = "c.cost_date <= ?"; $params[] = $cost_to; }
  if ($cost_vendor !== '') { $where[] = "c.vendor LIKE ?"; $params[] = '%'.$cost_vendor.'%'; }

  $sql = "SELECT c.*,
    DATE_FORMAT(c.cost_date, '%Y-%m-%d') AS cost_date_fmt,
    DATE_FORMAT(c.created_at, '%Y-%m-%d %H:%i') AS created_at_fmt
    FROM vehicle_costs c
    WHERE ".implode(" AND ", $where)."
    ORDER BY c.cost_date DESC, c.id DESC";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $costs = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($costs as $c) { $costSum += (float)($c['amount'] ?? 0); }
} catch (\Throwable $e) {}

// --------------------- FUEL ---------------------
$fuel = [];
try {
  $st = $pdo->prepare("SELECT f.*,
    DATE_FORMAT(f.fuel_date, '%Y-%m-%d') AS fuel_date_fmt,
    DATE_FORMAT(f.created_at, '%Y-%m-%d %H:%i') AS created_at_fmt
    FROM vehicle_fuel_entries f
    WHERE f.vehicle_id=?
    ORDER BY f.fuel_date DESC, f.id DESC");
  $st->execute([$id]);
  $fuel = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// --------------------- LOG ---------------------
$audit = [];
try {
  // ezt a query-t szándékosan nem piszkáltam (a ti logikátok szerint működjön)
  $st = $pdo->prepare("SELECT a.*,
    DATE_FORMAT(a.created_at, '%Y-%m-%d %H:%i') AS created_at_fmt
    FROM audit_log a
    WHERE a.changed_fields LIKE ?
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT 200");
  $st->execute(['%"vehicle_id":'.$id.'%']);
  $audit = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-0">Jármű: <?= h($v['plate'] ?? '') ?></h1>
    <div class="text-muted"><?= h($v['type_name'] ?? '') ?> · <?= h($v['brand'] ?? '') ?> <?= h($v['model'] ?? '') ?></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/vehicles.php">Vissza</a>
    <?php if ($isAdmin): ?>
      <a class="btn btn-primary" href="/vehicle_edit.php?id=<?= (int)$id ?>">Szerkesztés</a>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__.'/vehicle_registration_block.php'; ?>
<?php require __DIR__.'/vehicle_images_block.php'; ?>

<div class="card p-0 mt-3">

  <ul class="nav nav-tabs px-3 pt-2" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='issues'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_issues" type="button" role="tab">Hibák</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='service'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_service" type="button" role="tab">Szerviz</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='tires'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_tires" type="button" role="tab">Gumik</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='costs'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_costs" type="button" role="tab">Költségek</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='fuel'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_fuel" type="button" role="tab">Üzemanyag</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='log'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_log" type="button" role="tab">Napló</button></li>
  </ul>

  <div class="tab-content p-3">

    <!-- ISSUES -->
    <div class="tab-pane fade <?= ($tab==='issues'?'show active':'') ?>" id="tab_issues" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card p-3">
            <h3 class="h6 mb-2">Új hiba</h3>
            <form method="post" action="/vehicle_issue_add.php" class="row g-2">
              <?= \App\Csrf::field() ?>
              <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
              <div class="col-12">
                <label class="form-label">Leírás</label>
                <textarea name="description" class="form-control" rows="3" required></textarea>
              </div>
              <div class="col-12">
                <button class="btn btn-primary">Mentés</button>
              </div>
            </form>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h3 class="h6 mb-0">Hibák</h3>
              <span class="badge text-bg-secondary"><?= count($issues) ?></span>
            </div>

            <?php if (!$issues): ?>
              <div class="text-muted">Nincs rögzített hiba.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead>
                    <tr>
                      <th>Dátum</th>
                      <th>Leírás</th>
                      <th class="text-end">Művelet</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach($issues as $i): ?>
                    <tr>
                      <td class="text-nowrap"><?= h($i['created_at_fmt'] ?? '') ?></td>
                      <td><?= nl2br(h($i['description'] ?? '')) ?></td>
                      <td class="text-end text-nowrap">
                        <?php if ($isAdmin): ?>
                          <form method="post" action="/vehicle_issue_delete.php" class="d-inline">
                            <?= \App\Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                            <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Törlöd?')">Törlés</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- SERVICE -->
    <div class="tab-pane fade <?= ($tab==='service'?'show active':'') ?>" id="tab_service" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card p-3">
            <h3 class="h6 mb-2">Új szerviz</h3>
            <form method="post" action="/vehicle_service_add.php" class="row g-2">
              <?= \App\Csrf::field() ?>
              <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
              <div class="col-12">
                <label class="form-label">Dátum</label>
                <input type="date" name="service_date" class="form-control" required>
              </div>
              <div class="col-12">
                <label class="form-label">Leírás</label>
                <textarea name="description" class="form-control" rows="3" required></textarea>
              </div>
              <div class="col-12">
                <button class="btn btn-primary">Mentés</button>
              </div>
            </form>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h3 class="h6 mb-0">Szervizek</h3>
              <span class="badge text-bg-secondary"><?= count($services) ?></span>
            </div>

            <?php if (!$services): ?>
              <div class="text-muted">Nincs rögzített szerviz.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead>
                    <tr>
                      <th>Dátum</th>
                      <th>Leírás</th>
                      <th class="text-end">Művelet</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach($services as $s): ?>
                    <tr>
                      <td class="text-nowrap"><?= h($s['service_date_fmt'] ?? '') ?></td>
                      <td><?= nl2br(h($s['description'] ?? '')) ?></td>
                      <td class="text-end text-nowrap">
                        <?php if ($isAdmin): ?>
                          <form method="post" action="/vehicle_service_delete.php" class="d-inline">
                            <?= \App\Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Törlöd?')">Törlés</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- TIRES -->
    <div class="tab-pane fade <?= ($tab==='tires'?'show active':'') ?>" id="tab_tires" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card p-3">
            <h3 class="h6 mb-2">Új gumi csere</h3>
            <form method="post" action="/vehicle_tire_add.php" class="row g-2">
              <?= \App\Csrf::field() ?>
              <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
              <div class="col-12">
                <label class="form-label">Dátum</label>
                <input type="date" name="change_date" class="form-control" required>
              </div>
              <div class="col-12">
                <label class="form-label">Leírás</label>
                <textarea name="description" class="form-control" rows="3" required></textarea>
              </div>
              <div class="col-12">
                <button class="btn btn-primary">Mentés</button>
              </div>
            </form>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h3 class="h6 mb-0">Gumik</h3>
              <span class="badge text-bg-secondary"><?= count($tires) ?></span>
            </div>

            <?php if (!$tires): ?>
              <div class="text-muted">Nincs rögzített gumicsere.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead>
                    <tr>
                      <th>Dátum</th>
                      <th>Leírás</th>
                      <th class="text-end">Művelet</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach($tires as $t): ?>
                    <tr>
                      <td class="text-nowrap"><?= h($t['change_date_fmt'] ?? '') ?></td>
                      <td><?= nl2br(h($t['description'] ?? '')) ?></td>
                      <td class="text-end text-nowrap">
                        <?php if ($isAdmin): ?>
                          <form method="post" action="/vehicle_tire_delete.php" class="d-inline">
                            <?= \App\Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Törlöd?')">Törlés</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- COSTS -->
    <div class="tab-pane fade <?= ($tab==='costs'?'show active':'') ?>" id="tab_costs" role="tabpanel">
      <?php if (!$isAdmin): ?>
        <div class="alert alert-secondary">A költségek listáját csak admin láthatja.</div>
      <?php else: ?>
        <div class="card p-3 mb-3">
          <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="tab" value="costs">
            <div class="col-md-3">
              <label class="form-label">Időszak (-tól)</label>
              <input type="date" name="cost_from" class="form-control" value="<?= h($cost_from) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Időszak (-ig)</label>
              <input type="date" name="cost_to" class="form-control" value="<?= h($cost_to) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Szállító (név részlet)</label>
              <input name="cost_vendor" class="form-control" value="<?= h($cost_vendor) ?>" list="vendorList" placeholder="pl. Unix, Bárdi, szerviz...">
              <datalist id="vendorList">
                <?php foreach($vendorOptions as $vo): ?>
                  <option value="<?= h($vo) ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-2 d-grid gap-2">
              <button class="btn btn-primary">Szűrés</button>
              <a class="btn btn-outline-secondary" href="/vehicle.php?id=<?= (int)$id ?>&tab=costs#tab_costs">Törlés</a>
            </div>
          </form>
        </div>

        <div class="card p-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h3 class="h6 mb-0">Költségek</h3>
            <div class="text-muted">Összesen: <strong><?= number_format($costSum, 0, ',', ' ') ?> Ft</strong></div>
          </div>

          <?php if (!$costs): ?>
            <div class="text-muted">Nincs találat.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Dátum</th>
                    <th>Szállító</th>
                    <th>Leírás</th>
                    <th class="text-end">Összeg</th>
                    <th class="text-end">Művelet</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach($costs as $c): ?>
                  <tr>
                    <td class="text-nowrap"><?= h($c['cost_date_fmt'] ?? '') ?></td>
                    <td><?= h($c['vendor'] ?? '') ?></td>
                    <td><?= nl2br(h($c['description'] ?? '')) ?></td>
                    <td class="text-end text-nowrap"><?= number_format((float)($c['amount'] ?? 0), 0, ',', ' ') ?> Ft</td>
                    <td class="text-end text-nowrap">
                      <?php if ($isAdmin): ?>
                        <form method="post" action="/vehicle_cost_delete.php" class="d-inline">
                          <?= \App\Csrf::field() ?>
                          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                          <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
                          <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Törlöd?')">Törlés</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- FUEL -->
    <div class="tab-pane fade <?= ($tab==='fuel'?'show active':'') ?>" id="tab_fuel" role="tabpanel">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h3 class="h6 mb-0">Üzemanyag</h3>
          <span class="badge text-bg-secondary"><?= count($fuel) ?></span>
        </div>

        <?php if (!$fuel): ?>
          <div class="text-muted">Nincs rögzített üzemanyag tétel.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Dátum</th>
                  <th class="text-end">Liter</th>
                  <th class="text-end">Összeg</th>
                  <th class="text-end">Km</th>
                  <th>Megjegyzés</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($fuel as $f): ?>
                <tr>
                  <td class="text-nowrap"><?= h($f['fuel_date_fmt'] ?? '') ?></td>
                  <td class="text-end text-nowrap"><?= h($f['liters'] ?? '') ?></td>
                  <td class="text-end text-nowrap"><?= number_format((float)($f['amount'] ?? 0), 0, ',', ' ') ?> Ft</td>
                  <td class="text-end text-nowrap"><?= h($f['odometer_km'] ?? '') ?></td>
                  <td><?= h($f['note'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- LOG -->
    <div class="tab-pane fade <?= ($tab==='log'?'show active':'') ?>" id="tab_log" role="tabpanel">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h3 class="h6 mb-0">Napló</h3>
          <span class="badge text-bg-secondary"><?= count($audit) ?></span>
        </div>

        <?php if (!$audit): ?>
          <div class="text-muted">Nincs napló bejegyzés.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Dátum</th>
                  <th>Felhasználó</th>
                  <th>Esemény</th>
                  <th>Részletek</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($audit as $a): ?>
                <tr>
                  <td class="text-nowrap"><?= h($a['created_at_fmt'] ?? '') ?></td>
                  <td><?= h($a['actor_name'] ?? $a['actor_email'] ?? '') ?></td>
                  <td><?= h($a['action'] ?? '') ?></td>
                  <td class="small text-muted"><?= h($a['changed_fields'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /tab-content -->
</div><!-- /card -->

<script>
(function() {
  function activateFromHash() {
    var h = window.location.hash || '';
    if (!h) return;
    var btn = document.querySelector('button[data-bs-toggle="tab"][data-bs-target="' + h + '"]');
    if (btn && window.bootstrap && bootstrap.Tab) {
      bootstrap.Tab.getOrCreateInstance(btn).show();
    }
  }

  var tabButtons = document.querySelectorAll('button[data-bs-toggle="tab"][data-bs-target^="#tab_"]');
  tabButtons.forEach(function(btn) {
    btn.addEventListener('shown.bs.tab', function (e) {
      try {
        var target = e.target.getAttribute('data-bs-target'); // pl. #tab_costs
        if (!target) return;
        var t = target.replace('#tab_', '');
        var url = new URL(window.location.href);
        url.searchParams.set('tab', t);
        url.hash = target;
        history.replaceState(null, '', url.toString());
      } catch(err) {}
    });
  });

  setTimeout(activateFromHash, 0);
})();
</script>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>
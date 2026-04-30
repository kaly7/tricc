<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/views/_layout_top.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$u = Auth::user();
$isAdmin = isset($u['role_id']) && (int)$u['role_id'] === 1;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Napok száma szűrhető (alapból 30)
$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
$since = date('Y-m-d', strtotime("-{$days} days"));

// multialarm_enabled járművek, melyekhez nincs adat az elmúlt $days napban
$st = $pdo->prepare("
    SELECT v.id, v.license_plate, v.make, v.model, v.archived,
           vt.name AS type_name,
           MAX(k.km_date) AS last_km_date,
           SUM(k.total_km) AS total_km_period
    FROM vehicles v
    JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
    LEFT JOIN vehicle_daily_km k
           ON k.vehicle_id = v.id AND k.km_date >= ?
    WHERE v.multialarm_enabled = 1
      AND v.archived = 0
    GROUP BY v.id
    HAVING last_km_date IS NULL
    ORDER BY v.license_plate
");
$st->execute([$since]);
$missing = $st->fetchAll(PDO::FETCH_ASSOC);

// Van-e egyáltalán bármikor adat? (utolsó ismert adat dátuma)
$last_any = $pdo->prepare("
    SELECT v.id, MAX(k.km_date) AS last_ever
    FROM vehicles v
    LEFT JOIN vehicle_daily_km k ON k.vehicle_id = v.id
    WHERE v.multialarm_enabled = 1 AND v.archived = 0
    GROUP BY v.id
");
$last_any->execute();
$last_ever_map = [];
foreach ($last_any->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $last_ever_map[$r['id']] = $r['last_ever'];
}

// Összesítő: hány multialarm jármű van, hány aktív (volt adat a periódusban)
$stAll = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE multialarm_enabled=1 AND archived=0");
$total_ma = (int)$stAll->fetchColumn();
$active_count = $total_ma - count($missing);
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1 class="h5 mb-0">GPS – hiányzó adatok</h1>
  <form method="get" class="d-flex align-items-center gap-2">
    <label class="mb-0 small text-muted">Időszak:</label>
    <select name="days" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <?php foreach ([7,14,30,60,90] as $d): ?>
        <option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>>elmúlt <?= $d ?> nap</option>
      <?php endforeach; ?>
    </select>
    <a href="/vehicles.php" class="btn btn-sm btn-outline-secondary">← Járművek</a>
  </form>
</div>

<!-- Összesítő kártyák -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="card text-center py-3">
      <div class="fs-2 fw-bold"><?= $total_ma ?></div>
      <div class="text-muted small">GPS-figyelt jármű</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card text-center py-3 border-success">
      <div class="fs-2 fw-bold text-success"><?= $active_count ?></div>
      <div class="text-muted small">volt adat az elmúlt <?= $days ?> napban</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card text-center py-3 <?= count($missing) ? 'border-danger' : 'border-success' ?>">
      <div class="fs-2 fw-bold <?= count($missing) ? 'text-danger' : 'text-success' ?>"><?= count($missing) ?></div>
      <div class="text-muted small">nincs adat az elmúlt <?= $days ?> napban</div>
    </div>
  </div>
</div>

<?php if (!$missing): ?>
  <div class="alert alert-success">
    Minden GPS-figyelt járműhöz érkezett adat az elmúlt <?= $days ?> napban. ✓
  </div>
<?php else: ?>
  <div class="alert alert-warning mb-3">
    Az alábbi <?= count($missing) ?> járműhöz nem érkezett GPS-adat <strong><?= $since ?></strong> óta.
    Ellenőrizd a Multi Alarm rendszerben, hogy a nyomkövető aktív-e.
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-dark">
          <tr>
            <th>Rendszám</th>
            <th>Gyártmány / típus</th>
            <th>Fajta</th>
            <th>Utolsó ismert GPS-adat</th>
            <th>Hiány (nap)</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($missing as $v):
            $last_ever = $last_ever_map[$v['id']] ?? null;
            $hiany_nap = $last_ever
                ? (int)floor((time() - strtotime($last_ever)) / 86400)
                : null;
          ?>
          <tr>
            <td class="fw-semibold"><?= h($v['license_plate'] ?: '—') ?></td>
            <td><?= h(trim($v['make'].' '.$v['model'])) ?></td>
            <td class="text-muted"><?= h($v['type_name']) ?></td>
            <td>
              <?php if ($last_ever): ?>
                <span class="text-warning fw-semibold"><?= h($last_ever) ?></span>
              <?php else: ?>
                <span class="text-danger">soha</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($hiany_nap !== null): ?>
                <span class="badge bg-<?= $hiany_nap > 14 ? 'danger' : 'warning text-dark' ?>">
                  <?= $hiany_nap ?> napja
                </span>
              <?php else: ?>
                <span class="badge bg-secondary">—</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="/vehicle.php?id=<?= (int)$v['id'] ?>&tab=km"
                 class="btn btn-sm btn-outline-primary">Km adatok</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>

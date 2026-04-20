<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth;
use App\Middleware;
use App\Db;
use App\Csrf;
use App\Helpers;

Auth::start();
Middleware::requireAuth();
Auth::requireRole(1);

$pdo = Db::pdo();

// --- Szűrők ---
$filter_date      = trim($_GET['date'] ?? '');
$filter_worker_id = isset($_GET['worker']) && $_GET['worker'] !== '' ? (int)$_GET['worker'] : null;
$filter_status_id = isset($_GET['status_type']) && $_GET['status_type'] !== '' ? (int)$_GET['status_type'] : null;

$filterParams = [
    'date'        => $filter_date,
    'worker'      => $filter_worker_id !== null ? (string)$filter_worker_id : '',
    'status_type' => $filter_status_id !== null ? (string)$filter_status_id : '',
];
$filterParamsClean = [];
foreach ($filterParams as $k => $v) {
    if ($v !== '' && $v !== null) {
        $filterParamsClean[$k] = $v;
    }
}
$queryString = http_build_query($filterParamsClean);

// Dolgozók
$workers = $pdo->query("
  SELECT id, full_name, position
  FROM workers
  WHERE is_active = 1
  ORDER BY full_name
")->fetchAll();

// Státusz típusok
$statusTypes = $pdo->query("
  SELECT id, name, color
  FROM worker_status_types
  ORDER BY name
")->fetchAll();

// Törlés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }
    $delId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($delId > 0) {
        $st = $pdo->prepare("DELETE FROM worker_day_statuses WHERE id = ?");
        $st->execute([$delId]);
        Helpers::flash('ok', 'Dolgozói státusz törölve.');
    }
    $back = '/ts_worker_days.php';
    if ($queryString) {
        $back .= '?'.$queryString;
    }
    header('Location: '.$back);
    exit;
}

// Lista lekérdezés
$where = [];
$params = [];

// Dátumszűrő: az adott nap beleesik-e az intervallumba (status_date..status_date_to)
if ($filter_date !== '') {
    $where[] = 'wds.status_date <= ? AND COALESCE(wds.status_date_to, wds.status_date) >= ?';
    $params[] = $filter_date;
    $params[] = $filter_date;
}

if ($filter_worker_id !== null) {
    $where[] = 'wds.worker_id = ?';
    $params[] = $filter_worker_id;
}

if ($filter_status_id !== null) {
    $where[] = 'wds.status_type_id = ?';
    $params[] = $filter_status_id;
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "
  SELECT
    wds.id,
    wds.status_date,
    wds.status_date_to,
    wds.worker_id,
    wds.status_type_id,
    w.full_name,
    w.position,
    st.name  AS status_name,
    st.color AS status_color
  FROM worker_day_statuses wds
  JOIN workers w            ON w.id = wds.worker_id
  JOIN worker_status_types st ON st.id = wds.status_type_id
  $whereSql
  ORDER BY wds.status_date ASC, w.full_name ASC, wds.id ASC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Dolgozói napi státuszok</h1>
  <div class="d-flex gap-2">
    <a href="/ts_calendar.php<?= $queryString ? ('?'.Helpers::e($queryString)) : '' ?>"
       class="btn btn-sm btn-outline-secondary">
      Naptár nézet
    </a>
    <a href="/ts_worker_day_edit.php"
       class="btn btn-sm btn-success">
      Új dolgozói státusz
    </a>
  </div>
</div>

<!-- Szűrő sáv -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label for="f_date" class="form-label">Dátum</label>
        <input type="date" name="date" id="f_date" class="form-control"
               value="<?= Helpers::e($filter_date) ?>">
        <div class="form-text">Az intervallumba eső státuszok jelennek meg.</div>
      </div>

      <div class="col-md-4">
        <label for="f_worker" class="form-label">Dolgozó</label>
        <select name="worker" id="f_worker" class="form-select">
          <option value="">— Mind —</option>
          <?php foreach ($workers as $w): ?>
            <option value="<?= (int)$w['id'] ?>"
              <?= $filter_worker_id === (int)$w['id'] ? 'selected' : '' ?>>
              <?= Helpers::e($w['full_name']) ?>
              <?php if ($w['position']): ?>
                (<?= Helpers::e($w['position']) ?>)
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label for="f_status_type" class="form-label">Státusz típus</label>
        <select name="status_type" id="f_status_type" class="form-select">
          <option value="">— Mind —</option>
          <?php foreach ($statusTypes as $stt): ?>
            <option value="<?= (int)$stt['id'] ?>"
              <?= $filter_status_id === (int)$stt['id'] ? 'selected' : '' ?>>
              <?= Helpers::e($stt['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-12 d-flex gap-2 mt-2">
        <button type="submit" class="btn btn-primary btn-sm">Szűrés</button>
        <a href="/ts_worker_days.php" class="btn btn-outline-secondary btn-sm">Szűrő törlése</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0 align-middle">
        <thead class="table-light">
        <tr>
          <th style="width: 18%;">Dátum (-tól / -ig)</th>
          <th style="width: 30%;">Dolgozó</th>
          <th style="width: 30%;">Státusz</th>
          <th style="width: 22%;">Műveletek</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="4" class="text-center text-muted py-3">
              Nincs megjeleníthető dolgozói státusz.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $dateFrom = $r['status_date'];
              $dateTo   = $r['status_date_to'] ?: $r['status_date'];

              $dateRange = Helpers::e($dateFrom);
              if ($dateTo !== $dateFrom) {
                  $dateRange .= ' – '.Helpers::e($dateTo);
              }

              $workerLabel = $r['full_name'];
              if ($r['position']) {
                  $workerLabel .= ' ('.$r['position'].')';
              }
            ?>
            <tr>
              <td><?= $dateRange ?></td>
              <td><?= Helpers::e($workerLabel) ?></td>
              <td>
                <?php if (!empty($r['status_name'])): ?>
                  <span class="badge rounded-pill"
                        style="background-color: <?= Helpers::e((string)$r['status_color']) ?>;">
                    <?= Helpers::e($r['status_name']) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="d-flex flex-wrap gap-1">
                  <a class="btn btn-sm btn-outline-primary"
                     href="/ts_worker_day_edit.php?id=<?= (int)$r['id'] ?>">
                    Szerk.
                  </a>
                  <form method="post"
                        action="/ts_worker_days.php<?= $queryString ? ('?'.Helpers::e($queryString)) : '' ?>"
                        class="d-inline"
                        onsubmit="return confirm('Biztosan törlöd ezt a státuszt?');">
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                      Törlés
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php';
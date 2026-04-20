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
$filter_date       = trim($_GET['date'] ?? '');
$filter_type_id    = isset($_GET['type']) && $_GET['type'] !== '' ? (int)$_GET['type'] : null;
$filter_location   = trim($_GET['location'] ?? '');
$filter_project_id = isset($_GET['project']) && $_GET['project'] !== '' ? (int)$_GET['project'] : null;
$filter_status     = trim($_GET['status'] ?? '');

$filterParams = [
    'date'     => $filter_date,
    'type'     => $filter_type_id !== null ? (string)$filter_type_id : '',
    'location' => $filter_location,
    'project'  => $filter_project_id !== null ? (string)$filter_project_id : '',
    'status'   => $filter_status,
];
$filterParamsClean = [];
foreach ($filterParams as $k => $v) {
    if ($v !== '' && $v !== null) {
        $filterParamsClean[$k] = $v;
    }
}
$queryString = http_build_query($filterParamsClean);

// Munkatípusok
$workTypes = $pdo->query("
  SELECT id, name
  FROM work_types
  WHERE is_active = 1
  ORDER BY name
")->fetchAll();

// Projektek
$projects = $pdo->query("
  SELECT id, number, name
  FROM projects
  ORDER BY number, name
")->fetchAll();

$workTypesById = [];
foreach ($workTypes as $wt) {
    $workTypesById[$wt['id']] = $wt;
}
$projectsById = [];
foreach ($projects as $p) {
    $projectsById[$p['id']] = $p;
}

$statusLabels = [
    'planned'     => 'Tervezett',
    'in_progress' => 'Folyamatban',
    'done'        => 'Kész',
    'cancelled'   => 'Törölve',
];

// Törlés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }
    $delId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($delId > 0) {
        // kapcsolt dolgozókat is töröljük
        $st = $pdo->prepare("DELETE FROM work_event_workers WHERE work_event_id = ?");
        $st->execute([$delId]);
        $st = $pdo->prepare("DELETE FROM work_events WHERE id = ?");
        $st->execute([$delId]);
        Helpers::flash('ok', 'Munkavégzési esemény törölve.');
    }
    $back = '/ts_events.php';
    if ($queryString) {
        $back .= '?'.$queryString;
    }
    header('Location: '.$back);
    exit;
}

// Lista lekérdezés

$where = [];
$params = [];

// Dátumszűrő: ha az adott nap beleesik az intervallumba (work_date..date_to)
if ($filter_date !== '') {
    $where[] = 'e.work_date <= ? AND COALESCE(e.date_to, e.work_date) >= ?';
    $params[] = $filter_date;
    $params[] = $filter_date;
}

if ($filter_type_id !== null) {
    $where[] = 'e.work_type_id = ?';
    $params[] = $filter_type_id;
}

if ($filter_location !== '') {
    $where[] = 'e.location LIKE ?';
    $params[] = '%'.$filter_location.'%';
}

if ($filter_project_id !== null) {
    $where[] = 'e.project_id = ?';
    $params[] = $filter_project_id;
}

if ($filter_status !== '') {
    $where[] = 'e.status = ?';
    $params[] = $filter_status;
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "
  SELECT
    e.*,
    wt.name  AS work_type_name,
    p.number AS project_number,
    p.name   AS project_name
  FROM work_events e
  LEFT JOIN work_types wt ON e.work_type_id = wt.id
  LEFT JOIN projects p    ON e.project_id = p.id
  $whereSql
  ORDER BY e.work_date ASC, e.time_from ASC, e.id ASC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Munkavégzési események</h1>
  <div class="d-flex gap-2">
    <a href="/ts_calendar.php<?= $queryString ? ('?'.Helpers::e($queryString)) : '' ?>"
       class="btn btn-sm btn-outline-secondary">
      Naptár nézet
    </a>
    <a href="/ts_event_edit.php?return=list"
       class="btn btn-sm btn-success">
      Új munkavégzés
    </a>
  </div>
</div>

<!-- Szűrő sáv -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label for="f_date" class="form-label">Dátum</label>
        <input type="date" name="date" id="f_date" class="form-control"
               value="<?= Helpers::e($filter_date) ?>">
        <div class="form-text">Az intervallumba eső tételek jelennek meg.</div>
      </div>

      <div class="col-md-2">
        <label for="f_type" class="form-label">Típus</label>
        <select name="type" id="f_type" class="form-select">
          <option value="">— Mind —</option>
          <?php foreach ($workTypes as $t): ?>
            <option value="<?= (int)$t['id'] ?>"
              <?= $filter_type_id === (int)$t['id'] ? 'selected' : '' ?>>
              <?= Helpers::e($t['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label for="f_location" class="form-label">Helyszín</label>
        <input type="text" name="location" id="f_location" class="form-control"
               value="<?= Helpers::e($filter_location) ?>">
      </div>

      <div class="col-md-3">
        <label for="f_project" class="form-label">Projekt</label>
        <select name="project" id="f_project" class="form-select">
          <option value="">— Mind —</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>"
              <?= $filter_project_id === (int)$p['id'] ? 'selected' : '' ?>>
              <?= Helpers::e($p['number']) ?> – <?= Helpers::e($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label for="f_status" class="form-label">Státusz</label>
        <select name="status" id="f_status" class="form-select">
          <option value="">— Mind —</option>
          <?php foreach ($statusLabels as $k => $lbl): ?>
            <option value="<?= Helpers::e($k) ?>"
              <?= $filter_status === $k ? 'selected' : '' ?>>
              <?= Helpers::e($lbl) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-12 d-flex gap-2 mt-2">
        <button type="submit" class="btn btn-primary btn-sm">Szűrés</button>
        <a href="/ts_events.php" class="btn btn-outline-secondary btn-sm">Szűrő törlése</a>
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
          <th style="width: 12%;">Dátum (-tól / -ig)</th>
          <th style="width: 10%;">Idő (-tól / -ig)</th>
          <th style="width: 14%;">Típus</th>
          <th>Cím</th>
          <th style="width: 18%;">Projekt</th>
          <th style="width: 14%;">Helyszín</th>
          <th style="width: 10%;">Státusz</th>
          <th style="width: 8%;">Bejelentés</th>
          <th style="width: 16%;">Műveletek</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-3">
              Nincs megjeleníthető munkavégzés.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $dateFrom = $r['work_date'];
              $dateTo   = $r['date_to'] ?: $r['work_date'];

              $dateRange = Helpers::e($dateFrom);
              if ($dateTo !== $dateFrom) {
                  $dateRange .= ' – '.Helpers::e($dateTo);
              }

$timeFrom = $r['time_from'];
$timeTo   = $r['time_to'];

// csak HH:MM kell (TIME mezőből levágjuk a másodperceket)
$timeFromStr = $timeFrom ? substr($timeFrom, 0, 5) : '';
$timeToStr   = $timeTo   ? substr($timeTo,   0, 5) : '';

if ($timeFromStr || $timeToStr) {
    $timeRange = Helpers::e($timeFromStr ?: '');
    if ($timeToStr) {
        $timeRange .= ' – '.Helpers::e($timeToStr);
    }
} else {
    $timeRange = '<span class="text-muted small">Egész nap</span>';
}
              $statusKey = $r['status'] ?? '';
              $statusLabel = $statusLabels[$statusKey] ?? $statusKey;
            ?>
            <tr>
              <td><?= $dateRange ?></td>
              <td><?= $timeRange ?></td>
              <td><?= Helpers::e($r['work_type_name'] ?? '') ?></td>
              <td><?= Helpers::e($r['title'] ?? '') ?></td>
              <td>
                <?php if ($r['project_number'] || $r['project_name']): ?>
                  <?= Helpers::e(trim(($r['project_number'] ?? '').' '.($r['project_name'] ?? ''))) ?>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($r['location'])): ?>
                  <?= Helpers::e($r['location']) ?>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
              <td><?= Helpers::e($statusLabel) ?></td>
              <td>
                <?php if (!empty($r['requires_notification'])): ?>
                  <span class="badge bg-warning text-dark">Igen</span>
                <?php else: ?>
                  <span class="text-muted small">Nem</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="d-flex flex-wrap gap-1">
                  <a class="btn btn-sm btn-outline-primary"
                     href="/ts_event_edit.php?id=<?= (int)$r['id'] ?>&return=list">
                    Szerk.
                  </a>
                  <a class="btn btn-sm btn-outline-secondary"
                     href="/ts_event_edit.php?copy_id=<?= (int)$r['id'] ?>&return=list">
                    Másolat
                  </a>
                  <form method="post"
                        action="/ts_events.php<?= $queryString ? ('?'.Helpers::e($queryString)) : '' ?>"
                        class="d-inline"
                        onsubmit="return confirm('Biztosan törlöd ezt az eseményt?');">
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
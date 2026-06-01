<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_login();

$db = agv_db();

// ── Szűrők ────────────────────────────────────────────────────────────────────
$f_agv      = (int)($_GET['agv']      ?? 0);
$f_severity = trim($_GET['severity']  ?? '');
$f_type     = trim($_GET['type']      ?? '');
$f_from     = trim($_GET['from']      ?? '');
$f_to       = trim($_GET['to']        ?? '');
$page_num   = max(1, (int)($_GET['p'] ?? 1));
$per_page   = 50;
$offset     = ($page_num - 1) * $per_page;

// ── Lekérdezés ────────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($f_agv > 0) {
    $where[]  = 'e.agv_id = ?';
    $params[] = $f_agv;
}
if ($f_severity !== '') {
    $where[]  = 'e.severity = ?';
    $params[] = $f_severity;
}
if ($f_type !== '') {
    $where[]  = 'e.event_type = ?';
    $params[] = $f_type;
}
if ($f_from !== '') {
    $where[]  = 'e.created_at >= ?';
    $params[] = $f_from . ' 00:00:00';
}
if ($f_to !== '') {
    $where[]  = 'e.created_at <= ?';
    $params[] = $f_to . ' 23:59:59';
}

$sql_where = implode(' AND ', $where);

$st = $db->prepare("SELECT COUNT(*) FROM agv_events e WHERE $sql_where");
$st->bind_param(str_repeat('s', count($params)), ...$params);
$st->execute();
$total = (int)$st->get_result()->fetch_row()[0];
$pages = max(1, (int)ceil($total / $per_page));

$st = $db->prepare("
    SELECT e.*, a.name AS agv_name, a.serial_no
    FROM agv_events e
    JOIN agv a ON a.id = e.agv_id
    WHERE $sql_where
    ORDER BY e.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$st->bind_param(str_repeat('s', count($params)), ...$params);
$st->execute();
$events = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$agvs       = $db->query("SELECT id, name, serial_no FROM agv ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$event_types = $db->query("SELECT DISTINCT event_type FROM agv_events ORDER BY event_type")->fetch_all(MYSQLI_ASSOC);

// ── Segédek ───────────────────────────────────────────────────────────────────
function severity_badge(string $s): string {
    return match($s) {
        'error'   => '<span class="badge bg-danger">Kritikus</span>',
        'warning' => '<span class="badge bg-warning text-dark">Figyelmeztetés</span>',
        default   => '<span class="badge bg-info text-dark">Info</span>',
    };
}

function event_badge(string $t): string {
    return match($t) {
        'online'           => '<span class="badge bg-success">Online</span>',
        'offline'          => '<span class="badge bg-danger">Offline</span>',
        'battery_low'      => '<span class="badge bg-warning text-dark">Akku alacsony</span>',
        'battery_critical' => '<span class="badge bg-danger">Akku kritikus</span>',
        'battery_ok'       => '<span class="badge bg-success">Akku OK</span>',
        'mode_change'      => '<span class="badge bg-primary">Üzemmód</span>',
        'driving_start'    => '<span class="badge bg-success">Mozgás</span>',
        'driving_stop'     => '<span class="badge bg-secondary">Megállt</span>',
        'paused'           => '<span class="badge bg-warning text-dark">Szünet</span>',
        'resumed'          => '<span class="badge bg-success">Folytat</span>',
        'pos_lost'         => '<span class="badge bg-danger">Poz. elveszett</span>',
        'pos_init'         => '<span class="badge bg-primary">Poz. init.</span>',
        default            => '<span class="badge bg-secondary">' . e($t) . '</span>',
    };
}

$page  = 'events';
$title = 'Eseménynapló';
require __DIR__ . '/_header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 class="fw-bold mb-0">Eseménynapló</h5>
  <span class="text-muted small"><?= $total ?> esemény</span>
</div>

<!-- Szűrők -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">AGV</label>
        <select name="agv" class="form-select form-select-sm">
          <option value="0">– mind –</option>
          <?php foreach ($agvs as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $f_agv === (int)$a['id'] ? 'selected' : '' ?>>
              <?= e($a['name'] ?: $a['serial_no']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Súlyosság</label>
        <select name="severity" class="form-select form-select-sm">
          <option value="">– mind –</option>
          <option value="info"    <?= $f_severity === 'info'    ? 'selected' : '' ?>>Info</option>
          <option value="warning" <?= $f_severity === 'warning' ? 'selected' : '' ?>>Figyelmeztetés</option>
          <option value="error"   <?= $f_severity === 'error'   ? 'selected' : '' ?>>Kritikus</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Esemény típus</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">– mind –</option>
          <?php foreach ($event_types as $et): ?>
            <option value="<?= e($et['event_type']) ?>" <?= $f_type === $et['event_type'] ? 'selected' : '' ?>>
              <?= e($et['event_type']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">-tól</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($f_from) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">-ig</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($f_to) ?>">
      </div>
      <div class="col-6 col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary w-100">Szűrés</button>
        <a href="events.php" class="btn btn-sm btn-outline-secondary">✕</a>
      </div>
    </form>
  </div>
</div>

<!-- Táblázat -->
<div class="card shadow-sm mb-3">
  <div class="card-body p-0">
    <?php if (!$events): ?>
      <div class="text-muted text-center py-5 small">Nincs esemény a szűrési feltételeknek megfelelően.</div>
    <?php else: ?>
    <table class="table table-hover align-middle mb-0 table-sm">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:155px">Időpont</th>
          <th style="width:130px">AGV</th>
          <th style="width:160px">Esemény</th>
          <th style="width:100px">Súlyosság</th>
          <th>Részletek</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($events as $ev): ?>
        <tr class="<?= $ev['severity'] === 'error' ? 'table-danger' : ($ev['severity'] === 'warning' ? 'table-warning' : '') ?>">
          <td class="ps-3 font-monospace small text-muted"><?= e($ev['created_at']) ?></td>
          <td>
            <strong class="small"><?= e($ev['agv_name'] ?: $ev['serial_no']) ?></strong>
          </td>
          <td><?= event_badge($ev['event_type']) ?></td>
          <td><?= severity_badge($ev['severity']) ?></td>
          <td class="small"><?= e($ev['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Lapozó -->
<?php if ($pages > 1): ?>
<nav>
  <ul class="pagination pagination-sm justify-content-center">
    <?php
    $qs = http_build_query(array_filter(['agv'=>$f_agv,'severity'=>$f_severity,'type'=>$f_type,'from'=>$f_from,'to'=>$f_to]));
    for ($i = 1; $i <= $pages; $i++):
    ?>
      <li class="page-item <?= $i === $page_num ? 'active' : '' ?>">
        <a class="page-link" href="?<?= $qs ?>&p=<?= $i ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

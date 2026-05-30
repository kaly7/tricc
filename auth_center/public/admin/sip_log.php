<?php
declare(strict_types=1);
require_once __DIR__ . '/_admin_bootstrap.php';
require_once __DIR__ . '/_sip_helper.php';

$title    = 'SIP Admin – Napló';
$loggedIn = true;

// Filters
$f_from    = trim($_GET['from']    ?? '');
$f_to      = trim($_GET['to']      ?? '');
$f_app     = trim($_GET['app']     ?? '');
$f_caller  = trim($_GET['caller']  ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 20;

// CSV export
if (isset($_GET['export'])) {
    $events = sip_parse_apns_log(9999);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sip_log_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM for Excel
    echo "Időpont;Végpont;Hívó szám;Hívó név;APNs kód;OK\n";
    foreach ($events as $ev) {
        echo implode(';', [
            $ev['ts'] ?? '', $ev['app_user'] ?? '', $ev['caller_id'] ?? '',
            $ev['caller_name'] ?? '', $ev['status_code'] ?? '', $ev['ok'] ? 'igen' : 'nem'
        ]) . "\n";
    }
    exit;
}

// Load + filter APNs events
$all_events = sip_parse_apns_log(2000);
$filtered   = array_filter($all_events, function($ev) use ($f_from, $f_to, $f_app, $f_caller) {
    if ($f_from  && ($ev['ts'] ?? '') < $f_from . ' 00:00:00') return false;
    if ($f_to    && ($ev['ts'] ?? '') > $f_to   . ' 23:59:59') return false;
    if ($f_app   && ($ev['app_user'] ?? '') !== $f_app)        return false;
    if ($f_caller && !str_contains($ev['caller_id'] ?? '', $f_caller)) return false;
    return true;
});
$filtered   = array_values($filtered);
$total      = count($filtered);
$pages      = max(1, (int)ceil($total / $per_page));
$page_events = array_slice($filtered, ($page - 1) * $per_page, $per_page);

// Unique app usernames for filter dropdown
$app_users = array_unique(array_filter(array_column($all_events, 'app_user')));
sort($app_users);

// Asterisk log
$ast_lines = sip_parse_asterisk_log(200);

require __DIR__ . '/../../app/views/layout/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&display=swap');
.sip-mono { font-family: 'JetBrains Mono', monospace; font-size: .82rem; }
.sip-nav .nav-link        { color: #6c757d; border-radius: 6px; padding: .35rem .9rem; font-size: .875rem; font-weight: 500; }
.sip-nav .nav-link:hover  { background: #f0f4ff; color: #0d6efd; }
.sip-nav .nav-link.active { background: #0d6efd; color: #fff; }
.sip-header  { border-left: 4px solid #0d6efd; padding-left: .75rem; }
.status-bar  { height:3px; background:linear-gradient(90deg,#0d6efd,#6610f2); border-radius:2px; margin-bottom:1.25rem; }
.ast-log     { background:#0f1117; color:#c9d1d9; font-family:'JetBrains Mono',monospace; font-size:.75rem;
               line-height:1.6; border-radius:6px; padding:1rem; max-height:380px; overflow-y:auto; }
.ast-log .l-error   { color:#ff7b72; }
.ast-log .l-warning { color:#e3b341; }
.ast-log .l-notice  { color:#79c0ff; }
.ast-log .l-other   { color:#8b949e; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <div class="status-bar" style="width:120px"></div>
    <h1 class="h4 m-0 sip-header">SIP Admin</h1>
  </div>
</div>

<ul class="nav sip-nav gap-1 mb-4">
  <li class="nav-item"><a class="nav-link" href="sip.php">Dashboard</a></li>
  <li class="nav-item"><a class="nav-link active" href="sip_log.php">Napló</a></li>
  <li class="nav-item"><a class="nav-link" href="sip_numbers.php">Számok</a></li>
</ul>

<!-- Filter bar -->
<form method="get" class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Dátumtól</label>
        <input type="date" class="form-control form-control-sm" name="from" value="<?= h($f_from) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Dátumig</label>
        <input type="date" class="form-control form-control-sm" name="to" value="<?= h($f_to) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Végpont</label>
        <select class="form-select form-select-sm" name="app">
          <option value="">– mind –</option>
          <?php foreach ($app_users as $au): ?>
            <option value="<?= h($au) ?>" <?= $f_app === $au ? 'selected' : '' ?>><?= h($au) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1">Hívószám</label>
        <input type="text" class="form-control form-control-sm sip-mono" name="caller" placeholder="pl. 0670..." value="<?= h($f_caller) ?>">
      </div>
      <div class="col-12 col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary w-100">Szűrés</button>
        <a href="sip_log.php" class="btn btn-sm btn-outline-secondary">Töröl</a>
        <a href="?export=1&<?= http_build_query(['from'=>$f_from,'to'=>$f_to,'app'=>$f_app,'caller'=>$f_caller]) ?>"
           class="btn btn-sm btn-outline-secondary" title="CSV export">&#11121;</a>
      </div>
    </div>
  </div>
</form>

<!-- Call log table -->
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 d-flex align-items-center">
    <span class="fw-semibold">Hívásnapló</span>
    <span class="badge bg-secondary ms-2"><?= $total ?> esemény</span>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Időpont</th>
          <th>Végpont</th>
          <th>Hívó szám</th>
          <th>Hívó név</th>
          <th>APNs eredmény</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$page_events): ?>
        <tr><td colspan="5" class="text-center text-muted py-4 small">Nincs találat</td></tr>
      <?php else: foreach ($page_events as $ev): ?>
        <tr>
          <td class="ps-3 sip-mono text-muted small"><?= h($ev['ts'] ?? '–') ?></td>
          <td class="sip-mono"><?= h($ev['app_user'] ?? '–') ?></td>
          <td class="sip-mono"><?= h($ev['caller_id'] ?? '–') ?></td>
          <td class="small text-muted"><?= h($ev['caller_name'] ?? '–') ?></td>
          <td>
            <?php if ($ev['ok'] === true): ?>
              <span class="badge bg-success">HTTP 200</span>
            <?php elseif ($ev['ok'] === false): ?>
              <span class="badge bg-danger">HTTP <?= (int)$ev['status_code'] ?>
                <?php if (!empty($ev['apns_body'])): ?>
                  <span class="fw-normal"><?= h($ev['apns_body']) ?></span>
                <?php endif; ?>
              </span>
            <?php else: ?>
              <span class="badge bg-secondary">–</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="card-footer py-2">
    <nav>
      <ul class="pagination pagination-sm mb-0 justify-content-center">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(['from'=>$f_from,'to'=>$f_to,'app'=>$f_app,'caller'=>$f_caller,'page'=>$p]) ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<!-- Asterisk messages -->
<div class="card shadow-sm">
  <div class="card-header py-2 d-flex align-items-center">
    <span class="fw-semibold">Asterisk üzenetek</span>
    <span class="badge bg-secondary ms-2"><?= count($ast_lines) ?> sor</span>
    <small class="text-muted ms-auto"><?= h(SIP_AST_LOG) ?></small>
  </div>
  <div class="card-body p-2">
    <div class="ast-log">
      <?php if (!$ast_lines): ?>
        <span class="l-other">Nincs napló fájl vagy nincs olvasási jog.</span>
      <?php else: foreach ($ast_lines as $l): ?>
        <div class="l-<?= h($l['level']) ?>"><?= htmlspecialchars($l['line'], ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../app/views/layout/footer.php'; ?>

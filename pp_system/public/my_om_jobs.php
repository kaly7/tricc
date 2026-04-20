<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';

$db = db();
$u = current_user();
$status = trim((string)($_GET['status'] ?? ''));

$params = [];
if (is_admin()) {
    $where = '1';
    if ($status !== '') {
        $where .= ' AND s.name = ?';
        $params[] = $status;
    }
    $sql = "SELECT j.id, j.title, j.priority, j.planned_date, j.created_at,
                   s.name AS status_name, s.color_hex AS status_color,
                   r.eventus, r.address, r.operation, r.due_at, c.name AS city_name
            FROM om_jobs j
            JOIN om_job_statuses s ON s.id=j.status_id
            JOIN records r ON r.id=j.record_id
            JOIN cities c ON c.id=r.city_id
            WHERE $where
            ORDER BY COALESCE(j.planned_date, r.due_at) ASC, j.id DESC";
} else {
    $where = 'jw.user_id = ?';
    $params[] = $u['id'];
    if ($status !== '') {
        $where .= ' AND s.name = ?';
        $params[] = $status;
    }
    $sql = "SELECT j.id, j.title, j.priority, j.planned_date, j.created_at,
                   s.name AS status_name, s.color_hex AS status_color,
                   r.eventus, r.address, r.operation, r.due_at, c.name AS city_name
            FROM om_job_workers jw
            JOIN om_jobs j ON j.id=jw.job_id
            JOIN om_job_statuses s ON s.id=j.status_id
            JOIN records r ON r.id=j.record_id
            JOIN cities c ON c.id=r.city_id
            WHERE $where
            ORDER BY COALESCE(j.planned_date, r.due_at) ASC, j.id DESC";
}
$st = $db->prepare($sql);
$st->execute($params);
$jobs = $st->fetchAll(PDO::FETCH_ASSOC);
$statuses = $db->query('SELECT name, color_hex FROM om_job_statuses ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>O&M Munkák</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f8f9fa; }
.job-card { border:1px solid rgba(0,0,0,.08); border-radius:.75rem; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.04); }
.job-card .title { font-weight:600; }
.meta { color:#6c757d; font-size:.92rem; }
.action-btn { min-height:44px; }
.sticky-topbar { position: sticky; top: 0; z-index: 1030; background:#f8f9fa; }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <div class="d-flex align-items-center gap-2">
      <?php if (is_admin()): ?>
        <a class="btn btn-sm btn-outline-light" href="records.php">Tételek</a>
      <?php endif; ?>
      <span class="navbar-text text-white-50 small"><?=h($u['name'])?> (<?=h($u['role'])?>)</span>
      <a class="btn btn-sm btn-outline-light" href="change_password.php">Jelszó</a>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav>
<div class="container py-2 pb-4">
  <div class="sticky-topbar pb-2 mb-2">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h1 class="h4 m-0">O&amp;M Munkák</h1>
      <span class="text-muted small"><?=count($jobs)?> db</span>
    </div>
    <form class="row g-2" method="get">
      <div class="col-8 col-md-4">
        <select name="status" class="form-select form-select-sm">
          <option value="">Összes státusz</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?=h($s['name'])?>" <?=$status===$s['name']?'selected':''?>><?=h($s['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-4 col-md-2 d-grid">
        <button class="btn btn-sm btn-primary">Szűrés</button>
      </div>
    </form>
  </div>

  <?php if (!$jobs): ?>
    <div class="alert alert-secondary">Nincs megjeleníthető O&amp;M munka.</div>
  <?php else: ?>
    <div class="d-grid gap-3">
      <?php foreach ($jobs as $job): ?>
        <div class="job-card p-3">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <div>
              <div class="title"><?=h($job['title'])?></div>
              <div class="meta">Eventus: <?=h($job['eventus'])?></div>
            </div>
            <span class="badge" style="background: <?=h($job['status_color'])?>; color: <?=h(getContrastYIQ($job['status_color']))?>;">
              <?=h($job['status_name'])?>
            </span>
          </div>
          <div class="meta mb-1"><strong>Város:</strong> <?=h($job['city_name'])?></div>
          <div class="meta mb-1"><strong>Cím:</strong> <?=h($job['address'])?></div>
          <div class="meta mb-2"><strong>Művelet:</strong> <?=h($job['operation'])?></div>
          <div class="row g-2 mb-3">
            <div class="col-6"><div class="meta"><strong>Tervezett:</strong> <?=h($job['planned_date'] ?: '-')?></div></div>
            <div class="col-6"><div class="meta"><strong>Határidő:</strong> <?=h($job['due_at'])?></div></div>
          </div>
          <div class="d-grid">
            <a class="btn btn-outline-primary action-btn" href="my_om_job.php?id=<?=$job['id']?>">Megnyitás</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body></html>

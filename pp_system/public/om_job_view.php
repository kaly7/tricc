<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';

$db = db();
$u = current_user();
$jobId = (int)($_GET['id'] ?? 0);
if ($jobId <= 0) { http_response_code(400); echo 'Hiányzó azonosító'; exit; }

$st = $db->prepare("SELECT j.*, s.name AS status_name, s.color_hex AS status_color, r.eventus, r.address, r.operation, r.long_desc, r.issued_at, r.due_at, c.name AS city_name, creator.name AS assigned_by_name
                    FROM om_jobs j
                    JOIN om_job_statuses s ON s.id=j.status_id
                    JOIN records r ON r.id=j.record_id
                    JOIN cities c ON c.id=r.city_id
                    JOIN users creator ON creator.id=j.assigned_by
                    WHERE j.id=? LIMIT 1");
$st->execute([$jobId]);
$job = $st->fetch(PDO::FETCH_ASSOC);
if (!$job) { http_response_code(404); echo 'A munkalap nem található'; exit; }

if (!is_admin()) {
    $st = $db->prepare('SELECT 1 FROM om_job_workers WHERE job_id=? AND user_id=? LIMIT 1');
    $st->execute([$jobId, $u['id']]);
    if (!$st->fetchColumn()) { http_response_code(403); echo 'Forbidden'; exit; }
}

$workers = $db->prepare('SELECT u.id, u.name, u.email FROM om_job_workers jw JOIN users u ON u.id=jw.user_id WHERE jw.job_id=? ORDER BY u.name');
$workers->execute([$jobId]);
$workers = $workers->fetchAll(PDO::FETCH_ASSOC);

$logs = $db->prepare('SELECT l.*, u.name AS user_name FROM om_job_logs l JOIN users u ON u.id=l.user_id WHERE l.job_id=? ORDER BY l.created_at DESC, l.id DESC');
$logs->execute([$jobId]);
$logs = $logs->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>O&amp;M munka</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f8f9fa; }
.label { font-weight:600; color:#555; }
.desc-box { white-space: pre-wrap; }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-light" href="my_om_jobs.php">O&amp;M Munkák</a>
      <?php if (is_admin()): ?>
        <a class="btn btn-sm btn-outline-light" href="admin_users.php">Felhasználók</a>
        <a class="btn btn-sm btn-outline-light" href="admin_dicts.php">Törzsek</a>
        <a class="btn btn-sm btn-outline-light" href="admin_emails.php">E-mail sablonok</a>
      <?php endif; ?>
      <span class="navbar-text text-white-50 small"><?=h($u['name'])?> (<?=h($u['role'])?>)</span>
      <a class="btn btn-sm btn-outline-light" href="change_password.php">Jelszó</a>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav>

<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">O&amp;M munka</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="records.php">Vissza</a>
      <a class="btn btn-outline-primary" href="records_edit.php?id=<?=$job['record_id']?>">Kapcsolódó tétel</a>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-8">
          <div class="label">Megnevezés</div>
          <div class="fs-5"><?=h($job['title'])?></div>
        </div>
        <div class="col-md-2">
          <div class="label">Státusz</div>
          <span class="badge" style="background: <?=h($job['status_color'])?>; color: <?=h(getContrastYIQ($job['status_color']))?>;">
            <?=h($job['status_name'])?>
          </span>
        </div>
        <div class="col-md-2">
          <div class="label">Prioritás</div>
          <div><?=h($job['priority'])?></div>
        </div>
        <div class="col-md-3"><div class="label">Eventus</div><div><?=h($job['eventus'])?></div></div>
        <div class="col-md-3"><div class="label">Város</div><div><?=h($job['city_name'])?></div></div>
        <div class="col-md-3"><div class="label">Tervezett dátum</div><div><?=h($job['planned_date'])?></div></div>
        <div class="col-md-3"><div class="label">Kiosztotta</div><div><?=h($job['assigned_by_name'])?></div></div>
        <div class="col-md-6"><div class="label">Cím</div><div><?=h($job['address'])?></div></div>
        <div class="col-md-6"><div class="label">Művelet</div><div><?=h($job['operation'])?></div></div>
        <div class="col-12"><div class="label">Munkalap leírása</div><div class="desc-box border rounded p-3 bg-white"><?=h((string)$job['description'])?></div></div>
        <div class="col-12"><div class="label">Kapcsolódó O&amp;M leírás</div><div class="desc-box border rounded p-3 bg-white"><?=h((string)$job['long_desc'])?></div></div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header">Hozzárendelt dolgozók</div>
        <div class="card-body">
          <?php if ($workers): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($workers as $w): ?>
                <li class="list-group-item px-0">
                  <strong><?=h($w['name'])?></strong><br>
                  <small class="text-muted"><?=h($w['email'])?></small>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-muted">Nincs hozzárendelt dolgozó.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header">Eseménynapló</div>
        <div class="card-body">
          <?php if ($logs): ?>
            <div class="list-group list-group-flush">
              <?php foreach ($logs as $log): ?>
                <div class="list-group-item px-0">
                  <div class="d-flex justify-content-between gap-3">
                    <strong><?=h($log['user_name'])?></strong>
                    <small class="text-muted"><?=h($log['created_at'])?></small>
                  </div>
                  <div><span class="badge text-bg-secondary"><?=h($log['log_type'])?></span></div>
                  <div class="mt-2 desc-box"><?=h((string)$log['message'])?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted">Még nincs naplóbejegyzés.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
</body></html>

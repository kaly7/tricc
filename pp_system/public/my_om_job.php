<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';

$db = db();
$u = current_user();
$jobId = (int)($_GET['id'] ?? 0);
if ($jobId <= 0) { http_response_code(400); echo 'Hiányzó azonosító'; exit; }

$st = $db->prepare("SELECT j.*, s.name AS status_name, s.color_hex AS status_color, s.is_closed,
                           r.eventus, r.address, r.operation, r.long_desc, r.issued_at, r.due_at, c.name AS city_name,
                           creator.name AS assigned_by_name
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

$workers = $db->prepare('SELECT u.id, u.name FROM om_job_workers jw JOIN users u ON u.id=jw.user_id WHERE jw.job_id=? ORDER BY u.name');
$workers->execute([$jobId]);
$workers = $workers->fetchAll(PDO::FETCH_ASSOC);

$logs = $db->prepare('SELECT l.*, u.name AS user_name FROM om_job_logs l JOIN users u ON u.id=l.user_id WHERE l.job_id=? ORDER BY l.created_at DESC, l.id DESC');
$logs->execute([$jobId]);
$logs = $logs->fetchAll(PDO::FETCH_ASSOC);

$worktimes = $db->prepare('SELECT wt.*, u.name AS user_name FROM om_job_worktimes wt JOIN users u ON u.id=wt.user_id WHERE wt.job_id=? ORDER BY wt.work_date DESC, wt.id DESC');
$worktimes->execute([$jobId]);
$worktimes = $worktimes->fetchAll(PDO::FETCH_ASSOC);

$statuses = $db->query('SELECT id, name, color_hex FROM om_job_statuses ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Saját O&M munka</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f8f9fa; }
.section-card { border:1px solid rgba(0,0,0,.08); border-radius:.75rem; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.04); }
.desc-box { white-space: pre-wrap; }
.big-btn { min-height:46px; }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="my_om_jobs.php">O&amp;M Munkák</a>
    <div class="d-flex align-items-center gap-2">
      <span class="navbar-text text-white-50 small"><?=h($u['name'])?></span>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav>
<div class="container py-2 pb-4">
  <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success py-2"><?=h($_GET['msg'])?></div>
  <?php elseif (!empty($_GET['err'])): ?>
    <div class="alert alert-danger py-2"><?=h($_GET['err'])?></div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h5 m-0"><?=h($job['title'])?></h1>
    <span class="badge" style="background: <?=h($job['status_color'])?>; color: <?=h(getContrastYIQ($job['status_color']))?>;">
      <?=h($job['status_name'])?>
    </span>
  </div>

  <div class="section-card p-3 mb-3">
    <div class="row g-2 small">
      <div class="col-6"><strong>Eventus:</strong> <?=h($job['eventus'])?></div>
      <div class="col-6"><strong>Város:</strong> <?=h($job['city_name'])?></div>
      <div class="col-12"><strong>Cím:</strong> <?=h($job['address'])?></div>
      <div class="col-12"><strong>Művelet:</strong> <?=h($job['operation'])?></div>
      <div class="col-6"><strong>Tervezett:</strong> <?=h($job['planned_date'] ?: '-')?></div>
      <div class="col-6"><strong>Határidő:</strong> <?=h($job['due_at'])?></div>
      <div class="col-12"><strong>Dolgozók:</strong> <?php foreach($workers as $i => $w){ if($i) echo ', '; echo h($w['name']); } ?></div>
    </div>
  </div>

  <div class="section-card p-3 mb-3">
    <div class="fw-semibold mb-2">Leírás</div>
    <div class="desc-box small"><?=h((string)$job['description'])?></div>
  </div>

  <div class="section-card p-3 mb-3">
    <div class="fw-semibold mb-2">Kapcsolódó O&amp;M leírás</div>
    <div class="desc-box small"><?=h((string)$job['long_desc'])?></div>
  </div>

  <div class="section-card p-3 mb-3">
    <div class="fw-semibold mb-3">Gyors műveletek</div>
    <form method="post" action="actions/om_job_add_comment.php" class="mb-3">
      <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="job_id" value="<?=$job['id']?>">
      <label class="form-label">Megjegyzés / jelentés</label>
      <textarea name="message" class="form-control mb-2" rows="4" required placeholder="Mi történt? Mit csináltál? Van-e akadály?"></textarea>
      <button class="btn btn-primary w-100 big-btn">Megjegyzés mentése</button>
    </form>

    <form method="post" action="actions/om_job_add_worktime.php" class="row g-2 mb-3">
      <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="job_id" value="<?=$job['id']?>">
      <div class="col-12"><label class="form-label">Munkaidő rögzítés</label></div>
      <div class="col-12 col-md-4"><input type="date" name="work_date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
      <div class="col-6 col-md-3"><input type="time" name="time_from" class="form-control" required></div>
      <div class="col-6 col-md-3"><input type="time" name="time_to" class="form-control" required></div>
      <div class="col-12 col-md-2 d-grid"><button class="btn btn-outline-primary big-btn">Mentés</button></div>
      <div class="col-12"><input type="text" name="note" class="form-control" maxlength="255" placeholder="Megjegyzés (opcionális)"></div>
    </form>

    <form method="post" action="actions/om_job_change_status.php" class="row g-2">
      <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="job_id" value="<?=$job['id']?>">
      <div class="col-8"><select name="status_id" class="form-select" required>
        <?php foreach ($statuses as $s): ?>
          <option value="<?=$s['id']?>" <?=$s['id']==$job['status_id']?'selected':''?>><?=h($s['name'])?></option>
        <?php endforeach; ?>
      </select></div>
      <div class="col-4 d-grid"><button class="btn btn-outline-success big-btn">Státusz</button></div>
    </form>
  </div>

  <div class="section-card p-3 mb-3">
    <div class="fw-semibold mb-2">Munkaidő bejegyzések</div>
    <?php if (!$worktimes): ?>
      <div class="text-muted small">Még nincs rögzített munkaidő.</div>
    <?php else: ?>
      <div class="list-group list-group-flush">
      <?php foreach ($worktimes as $wt): ?>
        <div class="list-group-item px-0">
          <div class="small"><strong><?=h($wt['user_name'])?></strong> • <?=h($wt['work_date'])?></div>
          <div class="small text-muted"><?=h($wt['time_from'])?> - <?=h($wt['time_to'])?><?php if ($wt['minutes']): ?> • <?=h($wt['minutes'])?> perc<?php endif; ?></div>
          <?php if ($wt['note']): ?><div class="small"><?=h($wt['note'])?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="section-card p-3 mb-4">
    <div class="fw-semibold mb-2">Eseménynapló</div>
    <?php if (!$logs): ?>
      <div class="text-muted small">Még nincs naplóbejegyzés.</div>
    <?php else: ?>
      <div class="list-group list-group-flush">
      <?php foreach ($logs as $log): ?>
        <div class="list-group-item px-0">
          <div class="d-flex justify-content-between gap-3 small">
            <strong><?=h($log['user_name'])?></strong>
            <span class="text-muted"><?=h($log['created_at'])?></span>
          </div>
          <div class="mt-1"><span class="badge text-bg-secondary"><?=h($log['log_type'])?></span></div>
          <div class="mt-2 desc-box small"><?=h((string)$log['message'])?></div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>



<h5>Fotók</h5>

<form method="post"
      action="actions/om_job_upload_photo.php"
      enctype="multipart/form-data">

  <input type="hidden" name="job_id" value="<?= $job_id ?>">

  <input type="file"
         name="photos[]"
         accept="image/*"
         multiple
         class="form-control mb-2">

  <button class="btn btn-primary w-100">
    📸 Feltöltés
  </button>

</form>

<div class="mt-3">

<?php
$st = db()->prepare("
  SELECT * FROM om_job_photos
  WHERE job_id=?
  ORDER BY uploaded_at DESC
");
$st->execute([$job_id]);

foreach($st as $p):
?>

  <div style="margin-bottom:10px;">
    <img src="/<?= $p['file_path'] ?>"
         style="max-width:100%; border-radius:8px;">
  </div>

<?php endforeach; ?>

</div>

</body></html>

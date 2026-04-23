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

if (is_worker()) { header('Location: my_om_job.php?id=' . $jobId); exit; }

$workers = $db->prepare('SELECT u.id, u.name, u.email FROM om_job_workers jw JOIN users u ON u.id=jw.user_id WHERE jw.job_id=? ORDER BY u.name');
$workers->execute([$jobId]);
$workers = $workers->fetchAll(PDO::FETCH_ASSOC);
$workerIds = array_column($workers, 'id');

$availableWorkers = $db->query("SELECT u.id, u.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.is_active=1 AND r.name IN ('worker','user','admin') ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
$availableWorkers = array_filter($availableWorkers, fn($u) => !in_array($u['id'], $workerIds));

$logs = $db->prepare('SELECT l.*, u.name AS user_name FROM om_job_logs l JOIN users u ON u.id=l.user_id WHERE l.job_id=? ORDER BY l.created_at ASC, l.id ASC');
$logs->execute([$jobId]);
$logs = $logs->fetchAll(PDO::FETCH_ASSOC);

$jobMaterials = $db->prepare('SELECT m.*, u.name AS added_by_name FROM om_job_materials m LEFT JOIN users u ON u.id=m.user_id WHERE m.job_id=? ORDER BY m.created_at ASC');
$jobMaterials->execute([$jobId]);
$jobMaterials = $jobMaterials->fetchAll(PDO::FETCH_ASSOC);

$worktimes = $db->prepare('SELECT wt.*, u.name AS user_name FROM om_job_worktimes wt JOIN users u ON u.id=wt.user_id WHERE wt.job_id=? ORDER BY wt.work_date DESC, wt.id DESC');
$worktimes->execute([$jobId]);
$worktimes = $worktimes->fetchAll(PDO::FETCH_ASSOC);

$photos = $db->prepare('SELECT * FROM om_job_photos WHERE job_id=? ORDER BY uploaded_at ASC');
$photos->execute([$jobId]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);

$totalMinutes = array_sum(array_column($worktimes, 'minutes'));
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>O&amp;M munka – <?=h($job['title'])?></title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f8f9fa; }
.label { font-weight:600; color:#555; font-size:.8rem; text-transform:uppercase; letter-spacing:.04em; }
.desc-box { white-space: pre-wrap; }
.thumb-grid { display:flex; flex-wrap:wrap; gap:8px; }
.thumb-wrap { position:relative; display:inline-block; }
.thumb-wrap img { width:110px; height:90px; object-fit:cover; border-radius:6px; cursor:pointer; border:2px solid transparent; transition:border-color .15s; display:block; }
.thumb-wrap img:hover { border-color:#0d6efd; }
.thumb-del { position:absolute; top:3px; right:3px; width:20px; height:20px; border-radius:50%; background:rgba(200,30,30,.85); color:#fff; border:none; font-size:14px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity .15s; padding:0; }
.thumb-wrap:hover .thumb-del { opacity:1; }
#lightbox { display:none; position:fixed; inset:0; background:rgba(0,0,0,.88); z-index:9999; align-items:center; justify-content:center; flex-direction:column; }
#lightbox.open { display:flex; }
#lightbox img { max-width:96vw; max-height:90vh; border-radius:8px; box-shadow:0 4px 32px rgba(0,0,0,.6); }
#lightbox .lb-close { position:absolute; top:16px; right:24px; color:#fff; font-size:2rem; cursor:pointer; line-height:1; }
#lightbox .lb-nav { position:absolute; top:50%; transform:translateY(-50%); color:#fff; font-size:2.5rem; cursor:pointer; padding:0 16px; user-select:none; }
#lightbox .lb-prev { left:0; }
#lightbox .lb-next { right:0; }
#lightbox .lb-caption { color:#ddd; font-size:.85rem; margin-top:8px; }
.log-type-system     { background:#6c757d; }
.log-type-comment    { background:#0d6efd; }
.log-type-status_change { background:#198754; }
.log-type-work_report { background:#fd7e14; }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a class="btn btn-sm btn-outline-light" href="om_jobs.php">O&amp;M Munkák</a>
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

<div class="container py-3 pb-5">

  <!-- Fejléc -->
  <div class="d-flex justify-content-between align-items-start mb-3 gap-2 flex-wrap">
    <div>
      <h1 class="h4 m-0"><?=h($job['title'])?></h1>
      <div class="text-muted small mt-1">Kiosztotta: <?=h($job['assigned_by_name'])?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <span class="badge fs-6" style="background:<?=h($job['status_color'])?>; color:<?=h(getContrastYIQ($job['status_color']))?>;">
        <?=h($job['status_name'])?>
      </span>
      <a class="btn btn-outline-secondary btn-sm" href="om_jobs.php">Vissza</a>
      <a class="btn btn-outline-primary btn-sm" href="records_edit.php?id=<?=$job['record_id']?>">Kapcsolódó tétel</a>
    </div>
  </div>

  <!-- Alapadatok -->
  <div class="card mb-3">
    <div class="card-header fw-semibold">Alapadatok</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-6 col-md-3"><div class="label">Eventus</div><div><?=h($job['eventus'])?></div></div>
        <div class="col-6 col-md-3"><div class="label">Város</div><div><?=h($job['city_name'])?></div></div>
        <div class="col-6 col-md-3"><div class="label">Prioritás</div><div><?=h($job['priority'])?></div></div>
        <div class="col-6 col-md-3"><div class="label">Tervezett dátum</div><div><?=h($job['planned_date'] ?: '–')?></div></div>
        <div class="col-md-6"><div class="label">Cím</div><div><?=h($job['address'])?></div></div>
        <div class="col-md-6"><div class="label">Művelet</div><div><?=h($job['operation'])?></div></div>
        <?php $desc = trim((string)$job['description']) ?: trim((string)$job['long_desc']); ?>
        <?php if ($desc): ?>
        <div class="col-12"><div class="label">Leírás</div><div class="desc-box border rounded p-3 bg-light mt-1"><?=h($desc)?></div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row g-3">

    <!-- Dolgozók -->
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header fw-semibold">Hozzárendelt dolgozók</div>
        <div class="card-body d-flex flex-column gap-3">

          <?php if ($workers): ?>
            <ul class="list-group list-group-flush mb-0">
              <?php foreach ($workers as $w): ?>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                  <div>
                    <strong><?=h($w['name'])?></strong><br>
                    <small class="text-muted"><?=h($w['email'])?></small>
                  </div>
                  <?php if (is_admin()): ?>
                  <form method="post" action="actions/om_job_remove_worker.php"
                        onsubmit="return confirm('Eltávolítod: <?=h(addslashes($w['name']))?> ?')">
                    <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
                    <input type="hidden" name="job_id" value="<?=$jobId?>">
                    <input type="hidden" name="user_id" value="<?=$w['id']?>">
                    <button class="btn btn-sm btn-outline-danger" title="Eltávolítás">&times;</button>
                  </form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-muted small">Nincs hozzárendelt dolgozó.</div>
          <?php endif; ?>

          <?php if (is_admin() && $availableWorkers): ?>
          <form method="post" action="actions/om_job_add_worker.php" class="d-flex gap-2">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="job_id" value="<?=$jobId?>">
            <select name="user_id" class="form-select form-select-sm">
              <?php foreach ($availableWorkers as $aw): ?>
                <option value="<?=$aw['id']?>"><?=h($aw['name'])?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary text-nowrap">+ Hozzáad</button>
          </form>
          <?php endif; ?>

        </div>
      </div>
    </div>

    <!-- Munkaidők -->
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span>Munkaidő bejegyzések</span>
          <?php if ($totalMinutes > 0): ?>
            <span class="badge text-bg-secondary"><?= floor($totalMinutes/60) ?>h <?= $totalMinutes%60 ?>m összesen</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($worktimes): ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>Dolgozó</th><th>Dátum</th><th>Időszak</th><th>Perc</th><th>Megjegyzés</th></tr></thead>
                <tbody>
                <?php foreach ($worktimes as $wt): ?>
                  <tr>
                    <td><?=h($wt['user_name'])?></td>
                    <td><?=h($wt['work_date'])?></td>
                    <td><?=h($wt['time_from'])?> – <?=h($wt['time_to'])?></td>
                    <td><?=h($wt['minutes'] ?? '–')?></td>
                    <td class="text-muted small"><?=h($wt['note'] ?? '')?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-muted small">Még nincs rögzített munkaidő.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Megjegyzések -->
    <?php $comments = array_values(array_filter($logs, fn($l) => $l['log_type'] === 'comment')); ?>
    <div class="col-12">
      <div class="card">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span>Megjegyzések</span>
          <?php if ($comments): ?>
            <span class="badge text-bg-secondary"><?= count($comments) ?> db</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($comments): ?>
            <div class="list-group list-group-flush">
              <?php foreach ($comments as $c): ?>
                <div class="list-group-item px-0 py-2">
                  <div class="d-flex justify-content-between gap-3 align-items-center">
                    <strong class="small"><?=h($c['user_name'])?></strong>
                    <small class="text-muted text-nowrap"><?=h($c['created_at'])?></small>
                  </div>
                  <div class="mt-1 desc-box small"><?=h((string)$c['message'])?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted small">Még nincs megjegyzés.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Fotók -->
    <div class="col-12">
      <div class="card">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span>Fotók</span>
          <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-secondary"><?= count($photos) ?> db</span>
            <?php
              $gpsPhotos = array_filter($photos, fn($p) => !empty($p['gps_lat']));
            ?>
            <?php if ($gpsPhotos): ?>
              <a href="map_job.php?job_id=<?=$jobId?>" target="_blank"
                 class="btn btn-sm btn-outline-success py-0 px-2"
                 title="Fotók megjelenítése térképen">📍 Térkép</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="card-body">
          <?php if ($photos): ?>
            <div class="thumb-grid">
              <?php foreach ($photos as $i => $p): ?>
                <div class="thumb-wrap" data-photo-id="<?=(int)$p['id']?>">
                  <img src="/<?=h($p['file_path'])?>"
                       alt="<?=h($p['original_name'])?>"
                       data-idx="<?=$i?>"
                       title="<?=h($p['original_name'])?> – <?=h($p['uploaded_at'])?>">
                  <button class="thumb-del" title="Fotó törlése" onclick="deletePhoto(<?=(int)$p['id']?>, this)">×</button>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted small">Még nincs feltöltött fotó.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Anyagok -->
    <div class="col-12">
      <div class="card">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span>Felhasznált anyagok</span>
          <?php if ($jobMaterials): ?>
            <span class="badge text-bg-secondary"><?=count($jobMaterials)?> tétel</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($jobMaterials): ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                  <tr><th>Anyag</th><th>SKU</th><th>Mennyiség</th><th>Megjegyzés</th><th>Rögzítette</th><th>Dátum</th></tr>
                </thead>
                <tbody>
                <?php foreach ($jobMaterials as $m): ?>
                  <tr>
                    <td><?=h($m['material_name'])?></td>
                    <td class="text-muted small"><?=h($m['material_sku'])?></td>
                    <td><?=h($m['quantity']+0)?> <?=h($m['unit'])?></td>
                    <td class="text-muted small"><?=h($m['note'] ?? '')?></td>
                    <td class="text-muted small"><?=h($m['added_by_name'])?></td>
                    <td class="text-muted small"><?=h($m['created_at'])?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-muted small">Még nincs rögzített anyag.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Eseménynapló -->
    <div class="col-12">
      <div class="card">
        <div class="card-header fw-semibold">Eseménynapló</div>
        <div class="card-body">
          <?php if ($logs): ?>
            <div class="list-group list-group-flush">
              <?php foreach ($logs as $log): ?>
                <div class="list-group-item px-0 py-2">
                  <div class="d-flex justify-content-between gap-3 align-items-center">
                    <div>
                      <strong><?=h($log['user_name'])?></strong>
                      <span class="badge ms-1 log-type-<?=h($log['log_type'])?>"><?=h($log['log_type'])?></span>
                    </div>
                    <small class="text-muted text-nowrap"><?=h($log['created_at'])?></small>
                  </div>
                  <?php if ($log['message']): ?>
                    <div class="mt-1 desc-box small"><?=h((string)$log['message'])?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted small">Még nincs naplóbejegyzés.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div><!-- /row -->
</div><!-- /container -->

<!-- Lightbox -->
<div id="lightbox">
  <span class="lb-close" id="lbClose">&times;</span>
  <span class="lb-nav lb-prev" id="lbPrev">&#8249;</span>
  <img id="lbImg" src="" alt="">
  <div class="lb-caption" id="lbCaption"></div>
  <span class="lb-nav lb-next" id="lbNext">&#8250;</span>
</div>

<script>
(function(){
  const photos = <?= json_encode(array_map(fn($p) => [
    'src'     => '/'.$p['file_path'],
    'caption' => $p['original_name'].' – '.$p['uploaded_at']
  ], $photos)) ?>;

  if (!photos.length) return;

  const lb      = document.getElementById('lightbox');
  const lbImg   = document.getElementById('lbImg');
  const lbCap   = document.getElementById('lbCaption');
  let current   = 0;

  function open(idx){
    current = (idx + photos.length) % photos.length;
    lbImg.src = photos[current].src;
    lbCap.textContent = photos[current].caption;
    lb.classList.add('open');
  }
  function close(){ lb.classList.remove('open'); }

  document.querySelectorAll('.thumb-grid img').forEach(img=>{
    img.addEventListener('click', ()=>open(+img.dataset.idx));
  });
  document.getElementById('lbClose').addEventListener('click', close);
  document.getElementById('lbPrev').addEventListener('click', ()=>open(current-1));
  document.getElementById('lbNext').addEventListener('click', ()=>open(current+1));
  lb.addEventListener('click', e=>{ if(e.target===lb) close(); });
  document.addEventListener('keydown', e=>{
    if(!lb.classList.contains('open')) return;
    if(e.key==='Escape') close();
    if(e.key==='ArrowLeft') open(current-1);
    if(e.key==='ArrowRight') open(current+1);
  });
})();

const CURRENT_USER_NAME = <?= json_encode($u['name']) ?>;

function deletePhoto(photoId, btn) {
  if (!confirm('Biztosan törlöd ezt a fotót? A művelet nem vonható vissza.')) return;
  btn.disabled = true;
  const wrap = btn.closest('.thumb-wrap');
  const imgTitle = wrap.querySelector('img')?.title || '';
  const fd = new FormData();
  fd.append('photo_id', photoId);
  fetch('actions/om_job_delete_photo.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        wrap.remove();
        // fotók badge frissítése
        const badge = document.querySelector('.card-header .badge.text-bg-secondary');
        if (badge) {
          const n = parseInt(badge.textContent) - 1;
          badge.textContent = n + ' db';
        }
        // napló szekció frissítése
        appendLogEntry('photo_delete', 'Fotó törölve: ' + imgTitle);
      } else {
        alert('Hiba: ' + (data.error || 'ismeretlen hiba'));
        btn.disabled = false;
      }
    })
    .catch(() => { alert('Hálózati hiba.'); btn.disabled = false; });
}

function appendLogEntry(logType, message) {
  const logList = document.querySelector('.list-group.list-group-flush');
  const noLog   = document.querySelector('.card-body .text-muted.small');
  const now     = new Date().toLocaleString('hu-HU', { hour12: false }).replace(',', '');

  const item = document.createElement('div');
  item.className = 'list-group-item px-0 py-2';
  item.innerHTML = `
    <div class="d-flex justify-content-between gap-3 align-items-center">
      <div>
        <strong>${CURRENT_USER_NAME}</strong>
        <span class="badge ms-1 log-type-${logType}">${logType}</span>
      </div>
      <small class="text-muted text-nowrap">${now}</small>
    </div>
    <div class="mt-1 desc-box small">${message}</div>
  `;

  if (logList) {
    logList.appendChild(item);
  } else {
    // ha még nem volt napló, létrehozzuk a listát
    const cardBody = document.querySelectorAll('.card')[document.querySelectorAll('.card').length - 1].querySelector('.card-body');
    if (noLog) noLog.remove();
    const newList = document.createElement('div');
    newList.className = 'list-group list-group-flush';
    newList.appendChild(item);
    cardBody.appendChild(newList);
  }
}
</script>
</body></html>

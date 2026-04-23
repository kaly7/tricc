<?php
require_once __DIR__.'/../src/auth.php';
if (!current_user()) { start_session(); header('Location: worker_login.php'); exit; }
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

$comments = $db->prepare("SELECT l.*, u.name AS user_name FROM om_job_logs l JOIN users u ON u.id=l.user_id WHERE l.job_id=? AND l.log_type='comment' ORDER BY l.created_at ASC, l.id ASC");
$comments->execute([$jobId]);
$comments = $comments->fetchAll(PDO::FETCH_ASSOC);

$materials = $db->prepare('SELECT m.*, u.name AS added_by_name FROM om_job_materials m LEFT JOIN users u ON u.id=m.user_id WHERE m.job_id=? ORDER BY m.created_at ASC');
$materials->execute([$jobId]);
$materials = $materials->fetchAll(PDO::FETCH_ASSOC);

$whPdo = new PDO('mysql:host=127.0.0.1;dbname=warehousemgr;charset=utf8mb4', 'ppdb', 'abrakadabra', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$whItems = $whPdo->query('SELECT id, sku, name, unit, category_name FROM material_items WHERE is_active=1 AND is_archived=0 ORDER BY category_name, name')->fetchAll();
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
.thumb-grid { display:flex; flex-wrap:wrap; gap:8px; }
.thumb-grid img { width:90px; height:75px; object-fit:cover; border-radius:6px; cursor:pointer; border:2px solid transparent; transition:border-color .15s; }
.thumb-grid img:hover { border-color:#0d6efd; }
#lightbox { display:none; position:fixed; inset:0; background:rgba(0,0,0,.88); z-index:9999; align-items:center; justify-content:center; flex-direction:column; }
#lightbox.open { display:flex; }
#lightbox img { max-width:96vw; max-height:90vh; border-radius:8px; }
#lightbox .lb-close { position:absolute; top:16px; right:24px; color:#fff; font-size:2rem; cursor:pointer; line-height:1; }
#lightbox .lb-nav { position:absolute; top:50%; transform:translateY(-50%); color:#fff; font-size:2.5rem; cursor:pointer; padding:0 16px; user-select:none; }
#lightbox .lb-prev { left:0; } #lightbox .lb-next { right:0; }
#lightbox .lb-caption { color:#ddd; font-size:.85rem; margin-top:8px; }
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

  <?php $desc = trim((string)$job['description']) ?: trim((string)$job['long_desc']); ?>
  <?php if ($desc): ?>
  <div class="section-card p-3 mb-3">
    <div class="fw-semibold mb-2">Leírás</div>
    <div class="desc-box small"><?=h($desc)?></div>
  </div>
  <?php endif; ?>

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

  <div class="section-card p-3 mb-3">
    <div class="fw-semibold mb-2">Megjegyzések</div>
    <?php if ($comments): ?>
      <div class="list-group list-group-flush mb-3">
      <?php foreach ($comments as $c): ?>
        <div class="list-group-item px-0 py-2">
          <div class="d-flex justify-content-between gap-2 small">
            <strong><?=h($c['user_name'])?></strong>
            <span class="text-muted"><?=h($c['created_at'])?></span>
          </div>
          <div class="desc-box small mt-1"><?=h((string)$c['message'])?></div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="text-muted small mb-3">Még nincs megjegyzés.</div>
    <?php endif; ?>
  </div>

  <div class="section-card p-3 mb-3">
    <div class="fw-semibold mb-2">Felhasznált anyagok</div>

    <?php if ($materials): ?>
      <ul class="list-group list-group-flush mb-3">
      <?php foreach ($materials as $m): ?>
        <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-start">
          <div class="small">
            <div><strong><?=h($m['material_name'])?></strong></div>
            <div class="text-muted"><?=h($m['material_sku'])?> • <?=h($m['quantity'])+0?> <?=h($m['unit'])?></div>
            <?php if ($m['note']): ?><div class="text-muted"><?=h($m['note'])?></div><?php endif; ?>
            <div class="text-muted" style="font-size:.75rem;"><?=h($m['added_by_name'])?> – <?=h($m['created_at'])?></div>
          </div>
          <form method="post" action="actions/om_job_remove_material.php">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="id" value="<?=$m['id']?>">
            <input type="hidden" name="job_id" value="<?=$jobId?>">
            <button class="btn btn-sm btn-outline-danger">&times;</button>
          </form>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php
    $whCategories = array_values(array_unique(array_filter(array_column($whItems, 'category_name'))));
    sort($whCategories);
    ?>
    <div class="mb-2">
      <button class="btn btn-outline-secondary btn-sm w-100" type="button"
              id="matToggleBtn" onclick="
                var p=document.getElementById('matAddCollapse');
                var open=p.style.display==='block';
                p.style.display=open?'none':'block';
                this.textContent=open?'+ Anyag hozzáadása':'▲ Bezárás';
              ">
        + Anyag hozzáadása
      </button>
    </div>
    <div style="display:none;" id="matAddCollapse">
      <form method="post" action="actions/om_job_add_material.php" class="row g-2 pt-1">
        <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="job_id" value="<?=$jobId?>">

        <div class="col-12">
          <select id="matCategory" class="form-select form-select-sm">
            <option value="">— Összes kategória —</option>
            <?php foreach ($whCategories as $cat): ?>
              <option value="<?=h(strtolower($cat))?>"><?=h($cat)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <input type="text" id="matSearch" class="form-control form-control-sm" placeholder="Keresés névben, SKU-ban...">
        </div>
        <div class="col-12">
          <select name="material_id" id="matSelect" class="form-select form-select-sm" size="20" required>
            <?php foreach ($whItems as $item): ?>
              <option value="<?=$item['id']?>"
                      data-cat="<?=h(strtolower($item['category_name']??''))?>"
                      data-search="<?=h(strtolower($item['name'].' '.$item['sku']))?>">
                <?=h($item['name'])?> (<?=h($item['sku'])?>) [<?=h($item['unit'])?>]
              </option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted small mt-1" id="matCount"></div>
        </div>
        <div class="col-6">
          <input type="number" name="qty" class="form-control form-control-sm" placeholder="Mennyiség" value="1" min="0.001" step="any" required>
        </div>
        <div class="col-6">
          <input type="text" name="note" class="form-control form-control-sm" placeholder="Megjegyzés (opt.)">
        </div>
        <div class="col-12">
          <button class="btn btn-primary w-100 big-btn">Hozzáadás</button>
        </div>
      </form>
    </div>
  </div>

  <div class="section-card p-3 mb-3">
    <div class="fw-semibold mb-2">Fotók</div>
    <div id="photoUploadBox">
      <input id="photoInput" type="file" accept="image/*" capture="environment" class="form-control mb-2">
      <div id="photoPreviewWrap" style="display:none; margin-bottom:8px;">
        <canvas id="photoCanvas" style="width:100%; border-radius:8px; border:1px solid #ddd;"></canvas>
      </div>
      <div id="photoStatus" class="text-muted small mb-2"></div>
      <button id="uploadBtn" class="btn btn-primary w-100" disabled>📸 Bélyegzés és feltöltés</button>
    </div>
    <?php
    $st = db()->prepare("SELECT * FROM om_job_photos WHERE job_id=? ORDER BY uploaded_at ASC");
    $st->execute([$jobId]);
    $omPhotos = $st->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="mt-3 thumb-grid" id="photoList">
      <?php foreach($omPhotos as $i => $p): ?>
        <img src="/<?=h($p['file_path'])?>" data-idx="<?=$i?>" title="<?=h($p['uploaded_at'])?>">
      <?php endforeach; ?>
    </div>
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

<div id="lightbox">
  <span class="lb-close" id="lbClose">&times;</span>
  <span class="lb-nav lb-prev" id="lbPrev">&#8249;</span>
  <img id="lbImg" src="" alt="">
  <div class="lb-caption" id="lbCaption"></div>
  <span class="lb-nav lb-next" id="lbNext">&#8250;</span>
</div>

<footer class="text-center text-muted small py-3 mt-2" style="border-top:1px solid #dee2e6;">
  &copy; Perfect-Phone / O&amp;M 2026
</footer>

<script>
(function(){
  const JOB_ID = <?= $jobId ?>;
  const input     = document.getElementById('photoInput');
  const canvas    = document.getElementById('photoCanvas');
  const ctx       = canvas.getContext('2d');
  const wrap      = document.getElementById('photoPreviewWrap');
  const statusBox = document.getElementById('photoStatus');
  const uploadBtn = document.getElementById('uploadBtn');

  let currentFile = null;

  function pad(n){ return String(n).padStart(2,'0'); }
  function fmtDate(d){ return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`; }
  function fmtTime(d){ return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`; }

  function setStatus(txt, color){ statusBox.textContent=txt; statusBox.style.color=color||''; }

  function loadImg(file){
    return new Promise((res,rej)=>{
      const r=new FileReader();
      r.onload=()=>{ const i=new Image(); i.onload=()=>res(i); i.onerror=rej; i.src=r.result; };
      r.onerror=rej; r.readAsDataURL(file);
    });
  }

  function tryGPS(){
    return new Promise(res=>{
      if(!navigator.geolocation){ res(null); return; }
      navigator.geolocation.getCurrentPosition(
        pos=>res(pos),
        ()=>res(null),
        {enableHighAccuracy:true, timeout:10000, maximumAge:0}
      );
    });
  }

  async function tryAddress(lat,lon){
    try {
      const r = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lon}&format=json&accept-language=hu`, {headers:{'Accept':'application/json'}});
      const j = await r.json();
      return j.display_name || null;
    } catch(e){ return null; }
  }

  function applyStamp(img, lines){
    canvas.width  = img.width;
    canvas.height = img.height;
    ctx.drawImage(img,0,0);
    const p  = Math.max(16, Math.round(img.width*0.02));
    const fs = Math.max(16, Math.round(img.width*0.026));
    const lh = Math.round(fs*1.45);
    const bh = p*2 + lines.length*lh;
    ctx.fillStyle='rgba(0,0,0,0.60)';
    ctx.fillRect(0, img.height-bh, img.width, bh);
    ctx.fillStyle='#fff'; ctx.font=`bold ${fs}px Arial`; ctx.textBaseline='top';
    lines.forEach((l,i)=>ctx.fillText(l, p, img.height-bh+p+i*lh));
  }

  let stampedLines = null;
  let stampLat = null, stampLng = null;

  async function doStamp(){
    if(!currentFile) return;
    uploadBtn.disabled=true;
    stampLat = null; stampLng = null;
    const now = new Date();
    const lines = [`${fmtDate(now)}  ${fmtTime(now)}`];

    setStatus('GPS helyzet lekérése...');
    const pos = await tryGPS();
    if(pos){
      const {latitude:lat, longitude:lon, accuracy:acc} = pos.coords;
      stampLat = lat; stampLng = lon;
      lines.push(`GPS: ${lat.toFixed(6)}, ${lon.toFixed(6)}  (~${Math.round(acc)} m)`);
      setStatus('Cím lekérése...');
      const addr = await tryAddress(lat,lon);
      if(addr) lines.push(addr.length>60 ? addr.substring(0,58)+'…' : addr);
    } else {
      lines.push('GPS: nem elérhető');
    }

    const img = await loadImg(currentFile);
    applyStamp(img, lines);
    stampedLines = lines;
    wrap.style.display='block';
    setStatus('Bélyegzés kész. Ellenőrizd, majd töltsd fel.');
    uploadBtn.disabled=false;
  }

  input.addEventListener('change', async e=>{
    currentFile = e.target.files[0]||null;
    stampedLines = null;
    wrap.style.display='none'; uploadBtn.disabled=true; setStatus('');
    if(!currentFile) return;
    await doStamp();
  });

  uploadBtn.addEventListener('click', async ()=>{
    if(!currentFile){ setStatus('Nincs kiválasztott kép.','#c00'); return; }
    uploadBtn.disabled=true;
    const now = new Date();
    setStatus('Feltöltés...');
    canvas.toBlob(async blob=>{
      const fd = new FormData();
      fd.append('job_id', JOB_ID);
      const name = 'foto_'+fmtDate(now).replace(/-/g,'')+'_'+fmtTime(now).replace(/:/g,'')+'.jpg';
      fd.append('photos[]', blob, name);
      if(stampLat !== null) fd.append('gps_lat', stampLat);
      if(stampLng !== null) fd.append('gps_lng', stampLng);
      try {
        const r = await fetch('actions/om_job_upload_photo.php', {method:'POST', body:fd});
        if(r.ok){
          setStatus('Feltöltés sikeres!', 'green');
          setTimeout(()=>location.reload(), 800);
        } else {
          setStatus('Szerver hiba: '+r.status, '#c00');
          uploadBtn.disabled=false;
        }
      } catch(e){
        setStatus('Hálózati hiba: '+e.message, '#c00');
        uploadBtn.disabled=false;
      }
    },'image/jpeg',0.92);
  });
})();

// Anyag kereső
(function(){
  const catSel  = document.getElementById('matCategory');
  const search  = document.getElementById('matSearch');
  const select  = document.getElementById('matSelect');
  const counter = document.getElementById('matCount');
  if (!select) return;

  const allItems = <?= json_encode(array_map(fn($it) => [
    'id'   => $it['id'],
    'cat'  => strtolower($it['category_name'] ?? ''),
    'text' => $it['name'].' ('.$it['sku'].') ['.$it['unit'].']',
    'search' => strtolower($it['name'].' '.$it['sku']),
  ], $whItems)) ?>;

  function filter(){
    const cat = catSel ? catSel.value : '';
    const q   = search ? search.value.toLowerCase().trim() : '';

    const filtered = allItems.filter(it =>
      (!cat || it.cat === cat) &&
      (!q   || it.search.includes(q))
    );

    select.innerHTML = '';
    filtered.forEach(it => {
      const o = document.createElement('option');
      o.value = it.id;
      o.textContent = it.text;
      select.appendChild(o);
    });

    if (counter) counter.textContent = filtered.length + ' tétel látható';
    if (select.options.length) select.options[0].selected = true;
  }

  if (catSel) catSel.addEventListener('change', filter);
  if (search) search.addEventListener('input',  filter);
  filter();
})();

// Lightbox
(function(){
  const photos = <?= json_encode(array_map(fn($p) => [
    'src'     => '/'.$p['file_path'],
    'caption' => $p['uploaded_at']
  ], $omPhotos)) ?>;
  if (!photos.length) return;
  const lb    = document.getElementById('lightbox');
  const lbImg = document.getElementById('lbImg');
  const lbCap = document.getElementById('lbCaption');
  let cur = 0;
  function open(idx){ cur=(idx+photos.length)%photos.length; lbImg.src=photos[cur].src; lbCap.textContent=photos[cur].caption; lb.classList.add('open'); }
  function close(){ lb.classList.remove('open'); }
  document.querySelectorAll('.thumb-grid img').forEach(img=>img.addEventListener('click',()=>open(+img.dataset.idx)));
  document.getElementById('lbClose').addEventListener('click', close);
  document.getElementById('lbPrev').addEventListener('click', ()=>open(cur-1));
  document.getElementById('lbNext').addEventListener('click', ()=>open(cur+1));
  lb.addEventListener('click', e=>{ if(e.target===lb) close(); });
  document.addEventListener('keydown', e=>{ if(!lb.classList.contains('open')) return; if(e.key==='Escape') close(); if(e.key==='ArrowLeft') open(cur-1); if(e.key==='ArrowRight') open(cur+1); });
})();
</script>

</body></html>

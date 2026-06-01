<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_login();

$db   = agv_db();
$agvs = $db->query("SELECT * FROM agv ORDER BY id")->fetch_all(MYSQLI_ASSOC);

$page  = 'agvs';
$title = 'AGV-k';
require __DIR__ . '/_header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 class="fw-bold mb-0">AGV-k</h5>
  <div class="d-flex align-items-center gap-3">
    <span class="live-indicator" id="live-ind">
      <span class="live-dot-small"></span>
      <span id="live-ts">–</span>
    </span>
    <button id="refresh-btn" class="btn btn-sm btn-outline-secondary">⟳ Frissítés</button>
    <?php if (!empty($_SESSION['agv_admin'])): ?>
      <a href="admin.php" class="btn btn-sm btn-outline-primary">Kezelés</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$agvs): ?>
  <div class="card shadow-sm">
    <div class="card-body text-muted text-center py-5">
      Nincs AGV felvéve.
      <?php if (!empty($_SESSION['agv_admin'])): ?>
        <br><a href="admin.php" class="mt-2 d-inline-block">Hozzáadás →</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

<?php foreach ($agvs as $a): ?>
<div class="card shadow-sm mb-3" id="card-<?= $a['id'] ?>">
  <div class="card-header d-flex align-items-center justify-content-between py-2">
    <div class="d-flex align-items-center gap-2">
      <span class="fw-semibold"><?= e($a['name'] ?: $a['serial_no']) ?></span>
      <span class="text-muted small">
        <?= e($a['manufacturer']) ?><?= $a['type'] ? ' · ' . e($a['type']) : '' ?>
        &nbsp;/&nbsp;<?= e($a['serial_no']) ?>
      </span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="small text-muted font-monospace" id="src-<?= $a['id'] ?>"></span>
      <span class="badge bg-secondary" id="status-<?= $a['id'] ?>">
        <?= $a['enabled'] ? 'Várakozás' : 'Letiltva' ?>
      </span>
    </div>
  </div>

  <?php if ($a['enabled']): ?>
  <div class="card-body pb-2">
    <div class="row g-0">

      <!-- Pozíció csoport -->
      <div class="col-12 col-lg-4 pe-lg-3 mb-3 mb-lg-0">
        <div class="agv-group-label">Pozíció</div>
        <div class="row g-1">
          <div class="col-4 text-center">
            <div class="agv-field-label">X (m)</div>
            <div class="agv-field-val font-monospace fw-bold" id="x-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-4 text-center">
            <div class="agv-field-label">Y (m)</div>
            <div class="agv-field-val font-monospace fw-bold" id="y-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-4 text-center">
            <div class="agv-field-label">θ (fok)</div>
            <div class="agv-field-val font-monospace fw-bold" id="theta-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-4 text-center">
            <div class="agv-field-label">θ (radián)</div>
            <div class="agv-field-val font-monospace small" id="theta-rad-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-4 text-center">
            <div class="agv-field-label">Térkép</div>
            <div class="agv-field-val font-monospace small" id="map-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-4 text-center">
            <div class="agv-field-label">Lok. min.</div>
            <div class="agv-field-val small" id="loc-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-6 text-center">
            <div class="agv-field-label">Poz. init.</div>
            <div class="agv-field-val small" id="posinit-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-6 text-center">
            <div class="agv-field-label">Bizonytalanság</div>
            <div class="agv-field-val font-monospace small" id="devrange-<?= $a['id'] ?>">–</div>
          </div>
        </div>
      </div>

      <!-- Sebesség + Akkumulátor csoport -->
      <div class="col-12 col-lg-4 px-lg-3 border-lg-start border-lg-end mb-3 mb-lg-0">
        <div class="agv-group-label">Sebesség</div>
        <div class="row g-1 mb-2">
          <div class="col-6 text-center">
            <div class="agv-field-label">|v| (m/s)</div>
            <div class="agv-field-val font-monospace fw-bold" id="speed-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-6 text-center">
            <div class="agv-field-label">ω (rad/s)</div>
            <div class="agv-field-val font-monospace small" id="omega-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-6 text-center">
            <div class="agv-field-label">vx (m/s)</div>
            <div class="agv-field-val font-monospace small" id="vx-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-6 text-center">
            <div class="agv-field-label">vy (m/s)</div>
            <div class="agv-field-val font-monospace small" id="vy-<?= $a['id'] ?>">–</div>
          </div>
        </div>
        <div class="agv-group-label">Akkumulátor</div>
        <div class="row g-1">
          <div class="col-6 text-center">
            <div class="agv-field-label">Töltöttség</div>
            <div class="agv-field-val fw-bold" id="bat-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-6 text-center">
            <div class="agv-field-label">Feszültség</div>
            <div class="agv-field-val font-monospace small" id="volt-<?= $a['id'] ?>">–</div>
          </div>
        </div>
      </div>

      <!-- Állapot csoport -->
      <div class="col-12 col-lg-4 ps-lg-3">
        <div class="agv-group-label">Állapot</div>
        <div class="row g-1">
          <div class="col-6 text-center">
            <div class="agv-field-label">Mozgás</div>
            <div class="agv-field-val" id="motion-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-6 text-center">
            <div class="agv-field-label">Üzemmód</div>
            <div class="agv-field-val small" id="mode-<?= $a['id'] ?>">–</div>
          </div>
          <div class="col-12 text-center">
            <div class="agv-field-label">Utolsó adat</div>
            <div class="agv-field-val small font-monospace" id="ts-<?= $a['id'] ?>">–</div>
          </div>
        </div>
      </div>

    </div><!-- /row -->

    <!-- MQTT topic -->
    <div class="mt-2 pt-2 border-top">
      <code class="small text-muted"><?= e($a['topic']) ?>/visualization|state</code>
    </div>
  </div>
  <?php else: ?>
  <div class="card-body py-2">
    <span class="text-muted small">AGV letiltva – nem figyelve.</span>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<script>
function flash(el){ if(!el) return; el.classList.remove('val-updated'); void el.offsetWidth; el.classList.add('val-updated'); }
function setv(el, txt){ if(el && el.textContent !== txt){ el.textContent = txt; flash(el); } }
function setLive(state, ts){
    var ind = document.getElementById('live-ind');
    var lts = document.getElementById('live-ts');
    ind.className = 'live-indicator ' + (state==='ok'?'live-active':state==='stale'?'live-stale':'live-offline');
    if(lts && ts) lts.textContent = ts;
}

function fmtBat(v){
    if(v===null) return '–';
    var color = v<20?'#dc3545':v<50?'#ffc107':'#198754';
    return '<span style="color:'+color+';font-weight:700">'+v.toFixed(1)+'%</span>';
}
function fmtLoc(v){
    if(v===null) return '–';
    var pct = Math.round(v*100);
    var color = pct<50?'#dc3545':pct<80?'#ffc107':'#198754';
    return '<span style="color:'+color+'">'+pct+'%</span>';
}
function fmtMotion(driving, paused){
    if(driving===null) return '–';
    if(paused) return '<span class="badge bg-warning" style="color:#333">Szünet</span>';
    if(driving) return '<span class="badge bg-success">Mozog</span>';
    return '<span class="badge bg-secondary">Áll</span>';
}

function loadCoords(){
    fetch('coords_api.php')
        .then(function(r){ return r.json(); })
        .then(function(data){
            var anyFresh = false;
            data.forEach(function(d){
                var id = d.id;
                function el(sfx){ return document.getElementById(sfx+'-'+id); }

                var age = d.age_sec!==null ? parseInt(d.age_sec) : 9999;
                var es = el('status');
                if(es){
                    if(age<10){       es.textContent='Aktív';   es.className='badge bg-success'; anyFresh=true; }
                    else if(age<60){  es.textContent='Lassú';   es.className='badge bg-warning'; }
                    else{             es.textContent='Offline'; es.className='badge bg-danger';  }
                }
                var src = el('src');
                if(src && d.source) src.textContent = d.source;

                // Pozíció
                setv(el('x'),         d.x!==null         ? d.x.toFixed(3)+' m'         : '–');
                setv(el('y'),         d.y!==null         ? d.y.toFixed(3)+' m'         : '–');
                setv(el('theta'),     d.theta_deg!==null ? d.theta_deg.toFixed(1)+'°'  : '–');
                setv(el('theta-rad'), d.theta!==null     ? d.theta.toFixed(4)+' rad'   : '–');
                setv(el('map'),       d.map_id || '–');
                if(el('loc'))         el('loc').innerHTML    = fmtLoc(d.loc_score);
                setv(el('posinit'),   d.pos_init!==null  ? (d.pos_init ? 'igen' : 'nem') : '–');
                setv(el('devrange'),  d.dev_range!==null ? d.dev_range.toFixed(3)+' m' : '–');
                // Sebesség
                setv(el('speed'),     d.speed!==null ? d.speed.toFixed(3)+' m/s' : '–');
                setv(el('omega'),     d.omega!==null ? d.omega.toFixed(4)+' r/s' : '–');
                setv(el('vx'),        d.vx!==null    ? d.vx.toFixed(3)+' m/s'   : '–');
                setv(el('vy'),        d.vy!==null    ? d.vy.toFixed(3)+' m/s'   : '–');
                // Akkumulátor
                if(el('bat'))         el('bat').innerHTML = fmtBat(d.battery);
                setv(el('volt'),      d.voltage!==null ? d.voltage.toFixed(1)+' V' : '–');
                // Állapot
                if(el('motion'))      el('motion').innerHTML = fmtMotion(d.driving, d.paused);
                setv(el('mode'),      d.mode || '–');
                setv(el('ts'),        d.updated || '–');
            });
            var now = new Date().toLocaleTimeString('hu-HU');
            setLive(anyFresh ? 'ok' : 'stale', now);
        })
        .catch(function(){ setLive('err', null); });
}

document.getElementById('refresh-btn').addEventListener('click', loadCoords);
loadCoords();
setInterval(loadCoords, 2000);
</script>

<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>

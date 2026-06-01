<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_login();

$db     = agv_db();
$agvs   = $db->query("SELECT * FROM agv ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$broker = $db->query("SELECT * FROM mqtt_broker WHERE id=1")->fetch_assoc()
          ?? ['ip'=>'','port'=>1883,'username'=>'','password'=>''];

$page  = 'index';
$title = 'Dashboard';
require __DIR__ . '/_header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 class="fw-bold mb-0">Dashboard</h5>
  <div class="d-flex align-items-center gap-3">
    <span class="live-indicator" id="live-ind">
      <span class="live-dot-small"></span>
      <span id="live-ts">–</span>
    </span>
    <button id="refresh-btn" class="btn btn-sm btn-outline-secondary">⟳ Frissítés</button>
  </div>
</div>

<!-- Worker státusz -->
<div class="card shadow-sm mb-3">
  <div class="card-header d-flex align-items-center justify-content-between py-2">
    <span class="fw-semibold">MQTT Worker</span>
    <span id="worker-badge" class="badge bg-secondary">Ellenőrzés...</span>
  </div>
  <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
    <span class="small text-muted">Utolsó aktivitás:</span>
    <span class="small font-monospace" id="worker-mtime">–</span>
    <span class="small text-muted ms-3">Utolsó log:</span>
    <span class="small text-muted font-monospace text-truncate" id="worker-lastlog" style="max-width:420px">–</span>
  </div>
</div>

<!-- Broker státusz -->
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold">MQTT Broker</span>
    <span id="broker-badge" class="badge bg-secondary">Ellenőrzés...</span>
  </div>
  <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
    <?php if ($broker['ip']): ?>
      <code class="small"><?= e($broker['ip']) ?>:<?= (int)$broker['port'] ?></code>
      <?php if ($broker['username']): ?>
        <span class="text-muted small">Felhasználó: <strong><?= e($broker['username']) ?></strong></span>
      <?php endif; ?>
    <?php else: ?>
      <span class="text-muted small">Nincs IP beállítva.</span>
    <?php endif; ?>
    <a href="admin.php" class="small ms-auto">Beállítások →</a>
  </div>
</div>

<!-- AGV lista -->
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold">AGV-k</span>
    <span class="badge bg-secondary"><?= count($agvs) ?> db</span>
  </div>
  <div class="card-body p-0">
    <?php if (!$agvs): ?>
      <div class="text-muted text-center py-4 small">
        Nincs AGV felvéve. <a href="admin.php">Hozzáadás →</a>
      </div>
    <?php else: ?>
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">#</th>
          <th>Név</th>
          <th>Gyártó / Típus / S/N</th>
          <th>MQTT Topic</th>
          <th>Koordináta</th>
          <th>Utolsó adat</th>
          <th>Állapot</th>
        </tr>
      </thead>
      <tbody id="agv-tbody">
      <?php foreach ($agvs as $a): ?>
        <tr id="agv-row-<?= $a['id'] ?>">
          <td class="ps-3 text-muted small"><?= $a['id'] ?></td>
          <td>
            <strong><?= e($a['name'] ?: $a['serial_no']) ?></strong>
          </td>
          <td>
            <span class="text-muted small"><?= e($a['manufacturer']) ?></span>
            <?php if ($a['type']): ?><span class="small ms-1 text-muted">· <?= e($a['type']) ?></span><?php endif; ?>
            <span class="font-monospace ms-1"><?= e($a['serial_no']) ?></span>
          </td>
          <td><code class="small"><?= e($a['topic']) ?></code></td>
          <td id="agv-coord-<?= $a['id'] ?>" class="font-monospace small text-muted">–</td>
          <td id="agv-ts-<?= $a['id'] ?>" class="small text-muted">–</td>
          <td>
            <?php if (!$a['enabled']): ?>
              <span class="badge bg-secondary">Letiltva</span>
            <?php else: ?>
              <span class="badge bg-secondary" id="agv-status-<?= $a['id'] ?>">Várakozás</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
function flash(el) {
    if (!el) return;
    el.classList.remove('val-updated');
    void el.offsetWidth;
    el.classList.add('val-updated');
}
function setLive(state, ts) {
    var ind = document.getElementById('live-ind');
    var lts = document.getElementById('live-ts');
    ind.className = 'live-indicator ' + (state === 'ok' ? 'live-active' : state === 'stale' ? 'live-stale' : 'live-offline');
    if (lts && ts) lts.textContent = ts;
}

function loadCoords() {
    fetch('coords_api.php')
        .then(function(r){ return r.json(); })
        .then(function(data){
            var anyFresh = false;
            data.forEach(function(d){
                var coord = document.getElementById('agv-coord-' + d.id);
                var ts    = document.getElementById('agv-ts-'    + d.id);
                var st    = document.getElementById('agv-status-'+ d.id);
                var age   = d.age_sec !== null ? parseInt(d.age_sec) : 9999;

                if (coord) {
                    var txt = d.x !== null
                        ? 'X:' + d.x.toFixed(3) + '  Y:' + d.y.toFixed(3)
                          + (d.theta_deg !== null ? '  θ:' + d.theta_deg.toFixed(1) + '°' : '')
                          + (d.speed !== null ? '  v:' + d.speed.toFixed(2) + 'm/s' : '')
                        : '–';
                    if (coord.textContent !== txt) { coord.textContent = txt; flash(coord); }
                }
                if (ts && d.updated) ts.textContent = d.updated;
                if (st) {
                    if      (age < 10)  { st.textContent = 'Aktív';   st.className = 'badge bg-success'; anyFresh = true; }
                    else if (age < 60)  { st.textContent = 'Lassú';   st.className = 'badge bg-warning'; }
                    else                { st.textContent = 'Offline'; st.className = 'badge bg-danger';  }
                }
            });
            var now = new Date().toLocaleTimeString('hu-HU');
            setLive(anyFresh ? 'ok' : 'stale', now);
        })
        .catch(function(){ setLive('err', null); });
}

function checkBroker() {
    fetch('broker_test.php')
        .then(function(r){ return r.json(); })
        .then(function(d){
            var b = document.getElementById('broker-badge');
            b.textContent = d.ok ? 'Elérhető' : 'Nem elérhető';
            b.className   = 'badge ' + (d.ok ? 'bg-success' : 'bg-danger');
        })
        .catch(function(){
            var b = document.getElementById('broker-badge');
            b.textContent = 'Hiba'; b.className = 'badge bg-danger';
        });
}

function checkWorker() {
    fetch('worker_status.php')
        .then(function(r){ return r.json(); })
        .then(function(d){
            var badge = document.getElementById('worker-badge');
            var mtime = document.getElementById('worker-mtime');
            var llog  = document.getElementById('worker-lastlog');

            if (d.active === 'active') {
                badge.textContent = 'Fut';
                badge.className   = 'badge bg-success';
            } else if (d.active === 'failed') {
                badge.textContent = 'Hiba';
                badge.className   = 'badge bg-danger';
            } else if (d.active === 'inactive') {
                badge.textContent = 'Leállt';
                badge.className   = 'badge bg-warning';
            } else {
                badge.textContent = 'Ismeretlen';
                badge.className   = 'badge bg-secondary';
            }

            if (d.log_mtime) {
                var age = d.log_age_sec !== null ? parseInt(d.log_age_sec) : 9999;
                var ageStr = age < 60 ? age + ' mp' : Math.round(age/60) + ' perce';
                mtime.textContent = d.log_mtime + '  (' + ageStr + ')';
                mtime.style.color = age > 120 ? '#dc3545' : '#198754';
            } else {
                mtime.textContent = 'log nem olvasható';
                mtime.style.color = '#6c757d';
            }

            if (llog) llog.textContent = d.last_log || '–';
        })
        .catch(function(){
            var b = document.getElementById('worker-badge');
            b.textContent = 'Hiba'; b.className = 'badge bg-danger';
        });
}

document.getElementById('refresh-btn').addEventListener('click', function(){ checkBroker(); checkWorker(); loadCoords(); });

checkBroker();
checkWorker();
loadCoords();
setInterval(loadCoords,  2000);
setInterval(checkBroker, 30000);
setInterval(checkWorker, 15000);
</script>

<?php require __DIR__ . '/_footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_admin();

$db  = agv_db();
$msg = '';
$err = '';

// Elérhető mezők definíciója
$ALL_FIELDS = [
    'Pozíció' => [
        'x'         => 'X koordináta (m)',
        'y'         => 'Y koordináta (m)',
        'theta'     => 'Forgásszög θ (radián)',
        'theta_deg' => 'Forgásszög θ (fok)',
        'map_id'    => 'Térkép azonosító',
        'pos_init'  => 'Pozíció inicializálva (bool)',
        'loc_score' => 'Lokalizáció minősége (0–1)',
        'dev_range' => 'Pozíció bizonytalanság (m)',
    ],
    'Sebesség' => [
        'speed' => 'Sebesség nagysága (m/s)',
        'vx'    => 'Sebesség X-irányban (m/s)',
        'vy'    => 'Sebesség Y-irányban (m/s)',
        'omega' => 'Szögsebesség ω (rad/s)',
    ],
    'Akkumulátor' => [
        'battery' => 'Töltöttség (%)',
        'voltage' => 'Feszültség (V)',
    ],
    'Állapot' => [
        'mode'    => 'Üzemmód (AUTOMATIC stb.)',
        'driving' => 'Mozog-e (bool)',
        'paused'  => 'Szünetel-e (bool)',
    ],
    'Azonosítók' => [
        'timestamp'  => 'Időbélyeg (ISO 8601)',
        'agv_name'   => 'AGV neve',
        'serial_no'  => 'Sorozatszám',
    ],
];

// ── Omron broker mentés ───────────────────────────────────────────────────
if (isset($_POST['save_omron_broker'])) {
    $ip   = trim($_POST['omron_ip']   ?? '');
    $port = max(1, min(65535, (int)($_POST['omron_port'] ?? 1883)));
    $user = trim($_POST['omron_user'] ?? '');
    $pass = $_POST['omron_pass']      ?? '';
    $ena  = isset($_POST['omron_enabled']) ? 1 : 0;

    $st = $db->prepare("INSERT INTO omron_broker (id,ip,port,username,password,enabled,updated)
        VALUES (1,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE ip=VALUES(ip),port=VALUES(port),
            username=VALUES(username),password=VALUES(password),
            enabled=VALUES(enabled),updated=NOW()");
    $st->bind_param('sissi', $ip, $port, $user, $pass, $ena);
    $st->execute(); $st->close();
    $msg = 'Omron broker beállítás mentve.';
}

// ── AGV forward konfig mentés ─────────────────────────────────────────────
if (isset($_POST['save_forward'])) {
    $agv_id  = (int)$_POST['agv_id'];
    $topic   = trim($_POST['fwd_topic'] ?? '');
    $enabled = isset($_POST['fwd_enabled']) ? 1 : 0;
    $fields  = $_POST['fwd_fields'] ?? [];
    if (!is_array($fields)) $fields = [];
    $fields_json = json_encode(array_values($fields));

    $st = $db->prepare("INSERT INTO omron_forward (agv_id,topic_template,fields,enabled)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE topic_template=VALUES(topic_template),
            fields=VALUES(fields), enabled=VALUES(enabled)");
    $st->bind_param('issi', $agv_id, $topic, $fields_json, $enabled);
    $st->execute(); $st->close();
    $msg = 'Továbbítási konfig mentve.';
}

// ── Adatok betöltése ───────────────────────────────────────────────────────
$omron = $db->query("SELECT * FROM omron_broker WHERE id=1")->fetch_assoc()
         ?? ['ip'=>'','port'=>1883,'username'=>'','password'=>'','enabled'=>0];

$agvs = $db->query("SELECT a.*, f.topic_template, f.fields, f.enabled AS fwd_enabled
    FROM agv a
    LEFT JOIN omron_forward f ON f.agv_id = a.id
    ORDER BY a.id")->fetch_all(MYSQLI_ASSOC);

$page  = 'omron';
$title = 'Omron átadás';
require __DIR__ . '/_header.php';
?>

<?php if ($msg): ?>
  <div class="agv-alert agv-alert-ok">✓ <?= e($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="agv-alert agv-alert-err">✗ <?= e($err) ?></div>
<?php endif; ?>

<!-- ── Omron MQTT broker ──────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold">Omron MQTT Broker (cél)</span>
    <span id="omron-test-badge" class="badge bg-secondary small">–</span>
  </div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <div class="col-12 col-md-4">
        <label class="form-label">Broker IP / Hostname</label>
        <input type="text" name="omron_ip" class="form-control" placeholder="192.168.1.10"
               value="<?= e($omron['ip']) ?>">
      </div>
      <div class="col-4 col-md-2">
        <label class="form-label">Port</label>
        <input type="number" name="omron_port" class="form-control" min="1" max="65535"
               value="<?= (int)$omron['port'] ?>">
      </div>
      <div class="col col-md-3">
        <label class="form-label">Felhasználónév</label>
        <input type="text" name="omron_user" class="form-control" autocomplete="off"
               value="<?= e($omron['username']) ?>">
      </div>
      <div class="col col-md-3">
        <label class="form-label">Jelszó</label>
        <div class="input-group">
          <input type="password" name="omron_pass" id="omron_pass" class="form-control" autocomplete="off"
                 value="<?= e($omron['password']) ?>">
          <button type="button" class="btn btn-outline-secondary btn-sm"
                  onclick="var f=document.getElementById('omron_pass');f.type=f.type==='password'?'text':'password'">👁</button>
        </div>
      </div>
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="omron_enabled" id="omron-ena"
                 <?= $omron['enabled'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="omron-ena">Továbbítás engedélyezve</label>
        </div>
      </div>
      <div class="col-12 d-flex gap-2">
        <button name="save_omron_broker" class="btn btn-primary btn-sm">Mentés</button>
        <button type="button" id="omron-test-btn" class="btn btn-outline-secondary btn-sm">Kapcsolat tesztelése</button>
        <span id="omron-test-result" class="small align-self-center ms-2"></span>
      </div>
    </form>
  </div>
</div>

<!-- ── Per-AGV forwarding konfig ─────────────────────────────────────── -->
<?php if (!$agvs): ?>
  <div class="card shadow-sm">
    <div class="card-body text-muted text-center py-4">Nincs AGV felvéve.</div>
  </div>
<?php else: ?>
  <h6 class="fw-semibold mb-3 text-muted text-uppercase" style="font-size:11px;letter-spacing:.08em">
    AGV-nkénti továbbítási konfig
  </h6>

  <?php foreach ($agvs as $a):
    $fwd_fields = [];
    if ($a['fields']) {
        $decoded = json_decode($a['fields'], true);
        if (is_array($decoded)) $fwd_fields = $decoded;
    }
    $default_topic = 'omron/' . strtolower(e($a['manufacturer'])) . '/' . e($a['serial_no']) . '/position';
    $cur_topic = $a['topic_template'] ?: $default_topic;
    $fwd_ena   = $a['fwd_enabled'] !== null ? (bool)$a['fwd_enabled'] : true;
  ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
      <span class="fw-semibold"><?= e($a['name'] ?: $a['serial_no']) ?></span>
      <span class="text-muted small"><?= e($a['manufacturer']) ?><?= $a['type'] ? ' · '.e($a['type']) : '' ?> / <?= e($a['serial_no']) ?></span>
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="agv_id" value="<?= $a['id'] ?>">

        <div class="row g-3 mb-3">
          <div class="col-12 col-md-8">
            <label class="form-label">Cél MQTT topic
              <span class="text-muted small">(változók: <code>{serial_no}</code>, <code>{name}</code>)</span>
            </label>
            <input type="text" name="fwd_topic" class="form-control font-monospace"
                   value="<?= e($cur_topic) ?>">
          </div>
          <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="fwd_enabled"
                     id="fwd-ena-<?= $a['id'] ?>" <?= $fwd_ena ? 'checked' : '' ?>>
              <label class="form-check-label" for="fwd-ena-<?= $a['id'] ?>">Továbbítás aktív</label>
            </div>
          </div>
        </div>

        <label class="form-label fw-semibold">Átadandó mezők</label>
        <div class="row g-2 mb-3">
          <?php foreach ($ALL_FIELDS as $group => $fields): ?>
          <div class="col-12 col-md-6 col-lg-4">
            <div class="border rounded p-2">
              <div class="small fw-bold text-muted mb-2"><?= e($group) ?></div>
              <?php foreach ($fields as $key => $label): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="fwd_fields[]"
                       value="<?= e($key) ?>" id="f-<?= $a['id'] ?>-<?= $key ?>"
                       <?= in_array($key, $fwd_fields) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="f-<?= $a['id'] ?>-<?= $key ?>">
                  <code class="small"><?= e($key) ?></code>
                  <span class="text-muted"> – <?= e($label) ?></span>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Előnézet -->
        <div class="mb-3">
          <label class="form-label fw-semibold small">JSON előnézet (kiválasztott mezők alapján)</label>
          <pre class="small p-2 rounded border bg-light" id="preview-<?= $a['id'] ?>" style="max-height:160px;overflow-y:auto;font-size:11px"></pre>
        </div>

        <button name="save_forward" class="btn btn-primary btn-sm">Mentés</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
// ── JSON előnézet generálás ──────────────────────────────────────────────
var SAMPLE = {
    x: 12.345, y: 7.891, theta: 1.5708, theta_deg: 90.0,
    map_id: 'floor_1', pos_init: true, loc_score: 0.95, dev_range: 0.02,
    speed: 0.5, vx: 0.5, vy: 0.0, omega: 0.0,
    battery: 85.2, voltage: 24.1,
    mode: 'AUTOMATIC', driving: true, paused: false,
    timestamp: new Date().toISOString(), agv_name: 'AGV-1', serial_no: '36506'
};

function updatePreview(agvId) {
    var boxes = document.querySelectorAll('input[name="fwd_fields[]"]:checked');
    var result = {};
    boxes.forEach(function(b) {
        if (b.closest('.card-body') && b.closest('[id^="card-"]') === null) {
            // csak az adott AGV formjához tartozó checkboxok
        }
        var key = b.value;
        if (SAMPLE.hasOwnProperty(key)) result[key] = SAMPLE[key];
    });
    var pre = document.getElementById('preview-' + agvId);
    if (pre) pre.textContent = JSON.stringify(result, null, 2);
}

// Minden formhoz külön kezelés
<?php foreach ($agvs as $a): ?>
(function() {
    var agvId = <?= $a['id'] ?>;
    var form = document.querySelector('input[name="agv_id"][value="<?= $a['id'] ?>"]').closest('form');
    function refresh() {
        var checked = form.querySelectorAll('input[name="fwd_fields[]"]:checked');
        var result = {};
        checked.forEach(function(b) {
            if (SAMPLE.hasOwnProperty(b.value)) result[b.value] = SAMPLE[b.value];
        });
        var pre = document.getElementById('preview-' + agvId);
        if (pre) pre.textContent = Object.keys(result).length
            ? JSON.stringify(result, null, 2)
            : '(nincs mező kiválasztva)';
    }
    form.querySelectorAll('input[name="fwd_fields[]"]').forEach(function(cb) {
        cb.addEventListener('change', refresh);
    });
    refresh();
})();
<?php endforeach; ?>

// ── Omron broker kapcsolat teszt ─────────────────────────────────────────
document.getElementById('omron-test-btn').addEventListener('click', function() {
    var r = document.getElementById('omron-test-result');
    r.textContent = 'Tesztelés...'; r.style.color = '#888';
    fetch('omron_test.php')
        .then(function(res) { return res.json(); })
        .then(function(d) {
            if (d.ok) { r.textContent = '✓ Sikeres kapcsolat'; r.style.color = '#198754'; }
            else      { r.textContent = '✗ ' + (d.error||'Nem sikerült'); r.style.color = '#dc3545'; }
        })
        .catch(function() { r.textContent = '✗ Hálózati hiba'; r.style.color = '#dc3545'; });
});
</script>

<?php require __DIR__ . '/_footer.php'; ?>

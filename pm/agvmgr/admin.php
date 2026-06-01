<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_admin();

$db  = agv_db();
$msg = '';
$err = '';

// ── MQTT broker mentés ──────────────────────────────────────────────────────
if (isset($_POST['save_broker'])) {
    $ip   = trim($_POST['broker_ip']   ?? '');
    $port = max(1, min(65535, (int)($_POST['broker_port'] ?? 1883)));
    $user = trim($_POST['broker_user'] ?? '');
    $pass = $_POST['broker_pass']      ?? '';

    $st = $db->prepare("INSERT INTO mqtt_broker (id, ip, port, username, password, updated)
        VALUES (1, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE ip=VALUES(ip), port=VALUES(port),
            username=VALUES(username), password=VALUES(password), updated=NOW()");
    $st->bind_param('siss', $ip, $port, $user, $pass);
    $st->execute(); $st->close();
    $msg = 'MQTT broker beállítás mentve.';
}

// ── AGV hozzáadás ───────────────────────────────────────────────────────────
if (isset($_POST['add_agv'])) {
    $manuf  = trim($_POST['manufacturer'] ?? '');
    $type   = trim($_POST['agv_type']     ?? '');
    $sno    = trim($_POST['serial_no']    ?? '');
    $name   = trim($_POST['agv_name']     ?? '');
    $topic  = trim($_POST['topic']        ?? '');

    if ($manuf === '' || $sno === '') {
        $err = 'Gyártó és sorozatszám kötelező.';
    } else {
        if ($topic === '') {
            $topic = 'APR/v2/' . $manuf . 'robots/' . $sno;
        }
        $st = $db->prepare("INSERT INTO agv (manufacturer, type, serial_no, name, topic) VALUES (?,?,?,?,?)");
        $st->bind_param('sssss', $manuf, $type, $sno, $name, $topic);
        $st->execute(); $st->close();
        $msg = 'AGV hozzáadva.';
    }
}

// ── AGV törlés ──────────────────────────────────────────────────────────────
if (isset($_GET['del_agv'])) {
    $id = (int)$_GET['del_agv'];
    $st = $db->prepare("DELETE FROM agv WHERE id=?");
    $st->bind_param('i', $id);
    $st->execute(); $st->close();
    header('Location: admin.php?msg=deleted'); exit;
}

// ── AGV szerkesztés mentés ──────────────────────────────────────────────────
if (isset($_POST['edit_agv'])) {
    $id    = (int)$_POST['agv_id'];
    $manuf = trim($_POST['manufacturer'] ?? '');
    $type  = trim($_POST['agv_type']     ?? '');
    $sno   = trim($_POST['serial_no']    ?? '');
    $name  = trim($_POST['agv_name']     ?? '');
    $topic = trim($_POST['topic']        ?? '');
    $ena   = isset($_POST['enabled']) ? 1 : 0;

    if ($manuf === '' || $sno === '') {
        $err = 'Gyártó és sorozatszám kötelező.';
    } else {
        if ($topic === '') {
            $topic = 'APR/v2/' . $manuf . 'robots/' . $sno;
        }
        $st = $db->prepare("UPDATE agv SET manufacturer=?, type=?, serial_no=?, name=?, topic=?, enabled=? WHERE id=?");
        $st->bind_param('sssssii', $manuf, $type, $sno, $name, $topic, $ena, $id);
        $st->execute(); $st->close();
        $msg = 'AGV módosítva.';
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') $msg = 'AGV törölve.';

// ── Adatok betöltése ────────────────────────────────────────────────────────
$broker = $db->query("SELECT * FROM mqtt_broker WHERE id=1")->fetch_assoc()
          ?? ['ip'=>'','port'=>1883,'username'=>'','password'=>''];
$agvs   = $db->query("SELECT * FROM agv ORDER BY id")->fetch_all(MYSQLI_ASSOC);

// Szerkesztendő AGV
$edit_agv = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st  = $db->prepare("SELECT * FROM agv WHERE id=?");
    $st->bind_param('i', $eid);
    $st->execute();
    $edit_agv = $st->get_result()->fetch_assoc();
    $st->close();
}

$page  = 'admin';
$title = 'Beállítások';
require __DIR__ . '/_header.php';
?>

<?php if ($msg): ?>
  <div class="agv-alert agv-alert-ok">✓ <?= e($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="agv-alert agv-alert-err">✗ <?= e($err) ?></div>
<?php endif; ?>

<!-- ── MQTT Broker ─────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-header"><span class="fw-semibold">MQTT Broker</span></div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <div class="col col-md-4">
        <label class="form-label">Broker IP / Hostname</label>
        <input type="text" name="broker_ip" class="form-control" placeholder="192.168.1.100"
               value="<?= e($broker['ip']) ?>">
      </div>
      <div class="col-4 col-md-2">
        <label class="form-label">Port</label>
        <input type="number" name="broker_port" class="form-control" min="1" max="65535"
               value="<?= (int)$broker['port'] ?>">
      </div>
      <div class="col col-md-3">
        <label class="form-label">Felhasználónév</label>
        <input type="text" name="broker_user" class="form-control" autocomplete="off"
               value="<?= e($broker['username']) ?>">
      </div>
      <div class="col col-md-3">
        <label class="form-label">Jelszó</label>
        <div class="input-group">
          <input type="password" name="broker_pass" id="broker_pass" class="form-control" autocomplete="off"
                 value="<?= e($broker['password']) ?>">
          <button type="button" class="btn btn-outline-secondary btn-sm"
                  onclick="var f=document.getElementById('broker_pass');f.type=f.type==='password'?'text':'password';">
            👁
          </button>
        </div>
      </div>
      <div class="col-12 d-flex gap-2">
        <button name="save_broker" class="btn btn-primary btn-sm">Mentés</button>
        <button type="button" id="test-btn" class="btn btn-outline-secondary btn-sm">Kapcsolat tesztelése</button>
        <span id="test-result" class="small align-self-center ms-2"></span>
      </div>
    </form>
  </div>
</div>

<!-- ── AGV lista ──────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold">AGV-k</span>
    <span class="badge bg-secondary"><?= count($agvs) ?> db</span>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">#</th>
          <th>Gyártó</th>
          <th>Típus</th>
          <th>S/N</th>
          <th>Név</th>
          <th>MQTT Topic</th>
          <th>Állapot</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$agvs): ?>
        <tr><td colspan="8" class="text-muted text-center py-4 small">Még nincs AGV felvéve.</td></tr>
      <?php else: foreach ($agvs as $a): ?>
        <tr>
          <td class="ps-3 text-muted small"><?= $a['id'] ?></td>
          <td><strong><?= e($a['manufacturer']) ?></strong></td>
          <td class="small"><?= e($a['type']) ?: '<span class="text-muted">–</span>' ?></td>
          <td class="font-monospace"><?= e($a['serial_no']) ?></td>
          <td><?= e($a['name']) ?: '<span class="text-muted">–</span>' ?></td>
          <td><code class="small"><?= e($a['topic']) ?></code></td>
          <td>
            <?php if ($a['enabled']): ?>
              <span class="badge bg-success">Aktív</span>
            <?php else: ?>
              <span class="badge bg-secondary">Letiltva</span>
            <?php endif; ?>
          </td>
          <td class="text-end pe-3">
            <a href="admin.php?edit=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary">Szerkesztés</a>
            <a href="admin.php?del_agv=<?= $a['id'] ?>"
               class="btn btn-sm btn-outline-danger"
               onclick="return confirm('Biztosan törlöd?')">Törlés</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── AGV hozzáadás / szerkesztés ───────────────────────────────── -->
<div class="card shadow-sm">
  <div class="card-header">
    <span class="fw-semibold"><?= $edit_agv ? 'AGV szerkesztése' : 'Új AGV hozzáadása' ?></span>
  </div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <?php if ($edit_agv): ?>
        <input type="hidden" name="agv_id" value="<?= $edit_agv['id'] ?>">
      <?php endif; ?>
      <div class="col-12 col-md-3">
        <label class="form-label">Gyártó <span class="text-danger">*</span></label>
        <input type="text" name="manufacturer" class="form-control" placeholder="pl. Tusk"
               value="<?= e($edit_agv['manufacturer'] ?? '') ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Típus</label>
        <input type="text" name="agv_type" class="form-control" placeholder="pl. Forklift"
               value="<?= e($edit_agv['type'] ?? '') ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Sorozatszám (S/N) <span class="text-danger">*</span></label>
        <input type="text" name="serial_no" class="form-control" placeholder="pl. 36506"
               value="<?= e($edit_agv['serial_no'] ?? '') ?>" id="sno-input">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Megjelenítési név</label>
        <input type="text" name="agv_name" class="form-control" placeholder="pl. AGV-1"
               value="<?= e($edit_agv['name'] ?? '') ?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">MQTT Topic
          <span class="text-muted small">(üres = automatikus)</span>
        </label>
        <input type="text" name="topic" class="form-control font-monospace" id="topic-input"
               placeholder="APR/v2/Tuskrobots/36506"
               value="<?= e($edit_agv['topic'] ?? '') ?>">
      </div>
      <?php if ($edit_agv): ?>
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="enabled" id="ena-chk"
                 <?= $edit_agv['enabled'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="ena-chk">Aktív</label>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-12 d-flex gap-2">
        <button name="<?= $edit_agv ? 'edit_agv' : 'add_agv' ?>" class="btn btn-primary btn-sm">
          <?= $edit_agv ? 'Módosítás mentése' : '+ Hozzáadás' ?>
        </button>
        <?php if ($edit_agv): ?>
          <a href="admin.php" class="btn btn-outline-secondary btn-sm">Mégse</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<script>
// Topic auto-generálás gyártó + S/N alapján
(function(){
    var mf  = document.querySelector('input[name="manufacturer"]');
    var sno = document.getElementById('sno-input');
    var top = document.getElementById('topic-input');
    function gen(){
        if(top.value !== '' && top.dataset.manual) return;
        var m = (mf.value||'').trim();
        var s = (sno.value||'').trim();
        if(m && s) top.value = 'APR/v2/' + m + 'robots/' + s;
    }
    top.addEventListener('input', function(){ top.dataset.manual = top.value ? '1' : ''; });
    mf.addEventListener('input', gen);
    sno.addEventListener('input', gen);
})();

// Kapcsolat tesztelése
document.getElementById('test-btn').addEventListener('click', function(){
    var r = document.getElementById('test-result');
    r.textContent = 'Tesztelés...';
    r.style.color = '#888';
    fetch('broker_test.php')
        .then(function(res){ return res.json(); })
        .then(function(d){
            if(d.ok){
                r.textContent = '✓ Kapcsolat OK – teszt üzenet elküldve: ' + d.topic + ' (' + d.ts + ')';
                r.style.color = '#198754';
            } else {
                r.textContent = '✗ ' + (d.error || 'Nem sikerült');
                r.style.color = '#dc3545';
            }
        })
        .catch(function(){ r.textContent = '✗ Hálózati hiba'; r.style.color = '#dc3545'; });
});
</script>

<?php require __DIR__ . '/_footer.php'; ?>

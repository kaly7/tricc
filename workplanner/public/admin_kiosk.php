<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();
$page = 'admin_kiosk';

function kiosk_cfg_get(string $key, string $default): string {
  try {
    $st = db()->prepare("SELECT v FROM config WHERE k=?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v !== false) ? (string)$v : $default;
  } catch (Throwable) { return $default; }
}

function kiosk_cfg_set(string $key, string $value): void {
  db()->prepare("INSERT INTO config (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
     ->execute([$key, $value]);
}

// Szabványos felbontások → referencia zoom (1920×1080 = 1.00 alap)
const RESOLUTIONS = [
  ''          => ['label' => 'Egyéni (manuális zoom)', 'zoom' => null],
  '1280x720'  => ['label' => '1280 × 720  – HD',      'zoom' => 0.67],
  '1366x768'  => ['label' => '1366 × 768  – HD+',     'zoom' => 0.71],
  '1600x900'  => ['label' => '1600 × 900  – HD+',     'zoom' => 0.83],
  '1920x1080' => ['label' => '1920 × 1080 – Full HD', 'zoom' => 1.00],
  '2560x1440' => ['label' => '2560 × 1440 – QHD',     'zoom' => 1.33],
  '3840x2160' => ['label' => '3840 × 2160 – 4K UHD',  'zoom' => 2.00],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $action = (string)($_POST['action'] ?? 'settings');

  if ($action === 'add_ip') {
    $ip  = trim((string)($_POST['new_ip'] ?? ''));
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
      $ips   = json_decode(kiosk_cfg_get('kiosk_allowed_ips', '[]'), true) ?? [];
      if (!in_array($ip, $ips, true)) $ips[] = $ip;
      kiosk_cfg_set('kiosk_allowed_ips', json_encode(array_values($ips)));
      flash_set('ok', "IP hozzáadva: $ip");
    } else {
      flash_set('err', 'Érvénytelen IP cím.');
    }
    redirect('admin_kiosk.php');
  }

  if ($action === 'del_ip') {
    $ip  = trim((string)($_POST['del_ip'] ?? ''));
    $ips = json_decode(kiosk_cfg_get('kiosk_allowed_ips', '[]'), true) ?? [];
    $ips = array_values(array_filter($ips, fn($x) => $x !== $ip));
    kiosk_cfg_set('kiosk_allowed_ips', json_encode($ips));
    flash_set('ok', "IP eltávolítva: $ip");
    redirect('admin_kiosk.php');
  }

  // Alapbeállítások mentése
  $days    = (int)($_POST['kiosk_days']       ?? 5);
  $zoom    = (float)str_replace(',', '.', (string)($_POST['kiosk_zoom'] ?? '1.0'));
  $refresh = (int)($_POST['kiosk_refresh']    ?? 30);
  $reload  = (int)($_POST['kiosk_reload']     ?? 1800);
  $res     = (string)($_POST['kiosk_resolution'] ?? '');

  $days    = max(1, min(14, $days));
  $zoom    = max(0.5, min(2.0, $zoom));
  $refresh = max(10, min(300, $refresh));
  $reload  = max(60, min(86400, $reload));
  if (!array_key_exists($res, RESOLUTIONS)) $res = '';

  kiosk_cfg_set('kiosk_days',       (string)$days);
  kiosk_cfg_set('kiosk_zoom',       number_format($zoom, 2, '.', ''));
  kiosk_cfg_set('kiosk_refresh',    (string)$refresh);
  kiosk_cfg_set('kiosk_reload',     (string)$reload);
  kiosk_cfg_set('kiosk_resolution', $res);
  touch_last_modified();
  flash_set('ok', 'Kiosk beállítások mentve.');
  redirect('admin_kiosk.php');
}

$days    = kiosk_cfg_get('kiosk_days',       '5');
$zoom    = kiosk_cfg_get('kiosk_zoom',       '1.00');
$refresh = kiosk_cfg_get('kiosk_refresh',    '30');
$reload  = kiosk_cfg_get('kiosk_reload',     '1800');
$res     = kiosk_cfg_get('kiosk_resolution', '');
$allowedIps = json_decode(kiosk_cfg_get('kiosk_allowed_ips', '[]'), true) ?? [];
$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '';

require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Kiosk megjelenítés beállítása</h1>
  <a class="btn btn-sm btn-outline-secondary" href="kiosk.php" target="_blank">🖥 Kiosk megnyitása</a>
</div>

<div class="card" style="max-width:600px">
  <div class="card-body">
    <form method="post" id="kioskForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="mb-4">
        <label class="form-label fw-semibold">Kijelző felbontása</label>
        <div class="d-flex flex-column gap-1">
          <?php foreach (RESOLUTIONS as $key => $info): ?>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="kiosk_resolution"
              id="res_<?= $key ?: 'custom' ?>"
              value="<?= e($key) ?>"
              data-zoom="<?= $info['zoom'] !== null ? number_format($info['zoom'], 2, '.', '') : '' ?>"
              <?= $res === $key ? 'checked' : '' ?>
              onchange="applyResPreset(this)">
            <label class="form-check-label" for="res_<?= $key ?: 'custom' ?>">
              <?= e($info['label']) ?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">A felbontás kiválasztása automatikusan beállítja az ajánlott zoomot. Egyéni módban a csúszkával finomhangolható.</div>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold" for="kiosk_zoom">
          Zoom: <span id="zoomVal"><?= e($zoom) ?></span>×
        </label>
        <input type="range" class="form-range" name="kiosk_zoom" id="kiosk_zoom"
          min="0.5" max="2.0" step="0.05"
          value="<?= e($zoom) ?>"
          oninput="document.getElementById('zoomVal').textContent=parseFloat(this.value).toFixed(2)">
        <div class="d-flex justify-content-between text-muted" style="font-size:.75rem">
          <span>0.5× – kicsinyítés</span>
          <span>1.0× – 1:1</span>
          <span>2.0× – nagyítás</span>
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold">Megjelenített napok száma</label>
        <div class="d-flex gap-2 flex-wrap">
          <?php foreach ([3,5,7,10] as $d): ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="kiosk_days" id="days<?= $d ?>"
              value="<?= $d ?>" <?= (int)$days === $d ? 'checked' : '' ?>>
            <label class="form-check-label" for="days<?= $d ?>"><?= $d ?> nap</label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold">Adatellenőrzés intervalluma</label>
        <div class="d-flex gap-2 flex-wrap">
          <?php foreach ([15=>'15s', 30=>'30s', 60=>'60s', 120=>'120s', 300=>'5 perc'] as $s => $lbl): ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="kiosk_refresh" id="ref<?= $s ?>"
              value="<?= $s ?>" <?= (int)$refresh === $s ? 'checked' : '' ?>>
            <label class="form-check-label" for="ref<?= $s ?>"><?= $lbl ?></label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Ennyiként ellenőrzi, hogy változott-e az adat.</div>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold">Teljes oldal újratöltése</label>
        <div class="d-flex gap-2 flex-wrap">
          <?php foreach ([900=>'15 perc', 1800=>'30 perc', 3600=>'1 óra', 7200=>'2 óra'] as $s => $lbl): ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="kiosk_reload" id="rl<?= $s ?>"
              value="<?= $s ?>" <?= (int)$reload === $s ? 'checked' : '' ?>>
            <label class="form-check-label" for="rl<?= $s ?>"><?= $lbl ?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <hr class="my-3">
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Mentés</button>
        <a href="kiosk.php" target="_blank" class="btn btn-outline-secondary">🖥 Kiosk előnézet</a>
      </div>
    </form>
  </div>
</div>

<div class="card mt-4" style="max-width:600px">
  <div class="card-header fw-semibold">Engedélyezett IP címek (belépés nélküli hozzáférés)</div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Az itt felsorolt IP-kről a kiosk oldal bejelentkezés nélkül is elérhető —
      pl. a kijelzőként használt tablet vagy TV fix IP-je.
      Más IP-ről az auth_centeren keresztül kell belépni.
    </p>

    <?php if ($allowedIps): ?>
    <table class="table table-sm mb-3">
      <thead class="table-light"><tr><th>IP cím</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($allowedIps as $ip): ?>
        <tr>
          <td class="align-middle">
            <?= e($ip) ?>
            <?php if ($ip === $clientIp): ?>
              <span class="badge bg-success ms-1">ez az ön gépe</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <form method="post" style="display:inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="del_ip">
              <input type="hidden" name="del_ip" value="<?= e($ip) ?>">
              <button class="btn btn-sm btn-outline-danger"
                onclick="return confirm('Biztosan törlöd?')">Törlés</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p class="text-muted small">Még nincs engedélyezett IP. A kiosk oldal jelenleg csak bejelentkezés után érhető el.</p>
    <?php endif; ?>

    <form method="post" class="d-flex gap-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add_ip">
      <div class="flex-grow-1">
        <label class="form-label small mb-1">Új IP cím hozzáadása</label>
        <div class="input-group">
          <input type="text" name="new_ip" class="form-control form-control-sm"
            placeholder="pl. 192.168.1.100"
            value="<?= e($clientIp) ?>">
          <button class="btn btn-sm btn-outline-primary">Hozzáadás</button>
        </div>
      </div>
    </form>
    <div class="form-text mt-1">Az Ön jelenlegi IP-je: <strong><?= e($clientIp) ?></strong></div>
  </div>
</div>

<script>
function applyResPreset(radio) {
  const zoom = radio.dataset.zoom;
  if (!zoom) return; // Egyéni – csúszka marad
  const slider = document.getElementById('kiosk_zoom');
  slider.value = zoom;
  document.getElementById('zoomVal').textContent = parseFloat(zoom).toFixed(2);
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>

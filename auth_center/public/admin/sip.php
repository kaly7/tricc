<?php
declare(strict_types=1);
require_once __DIR__ . '/_admin_bootstrap.php';
require_once __DIR__ . '/_sip_helper.php';

$title    = 'SIP Admin – Dashboard';
$loggedIn = true;

$endpoints     = sip_get_endpoints();
$registrations = sip_get_registrations();
$recent_events = sip_parse_apns_log(5);

require __DIR__ . '/../../app/views/layout/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&display=swap');
.sip-mono { font-family: 'JetBrains Mono', monospace; font-size: .82rem; }
.sip-nav .nav-link         { color: #6c757d; border-radius: 6px; padding: .35rem .9rem; font-size: .875rem; font-weight: 500; }
.sip-nav .nav-link:hover   { background: #f0f4ff; color: #0d6efd; }
.sip-nav .nav-link.active  { background: #0d6efd; color: #fff; }
.sip-header { border-left: 4px solid #0d6efd; padding-left: .75rem; }
.pulse { display:inline-block; width:9px; height:9px; border-radius:50%; background:#198754; box-shadow:0 0 0 0 rgba(25,135,84,.4);
         animation: pulse-anim 1.8s infinite; }
.pulse.offline { background:#dc3545; box-shadow:0 0 0 0 rgba(220,53,69,.4); animation:none; }
@keyframes pulse-anim { 0%{box-shadow:0 0 0 0 rgba(25,135,84,.4)} 70%{box-shadow:0 0 0 8px rgba(25,135,84,0)} 100%{box-shadow:0 0 0 0 rgba(25,135,84,0)} }
.status-bar { height:3px; background:linear-gradient(90deg,#0d6efd,#6610f2); border-radius:2px; margin-bottom:1.25rem; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <div class="status-bar" style="width:120px"></div>
    <h1 class="h4 m-0 sip-header">SIP Admin</h1>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <span class="text-muted small" id="last-refresh">–</span>
    <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">&#8635; Frissítés</button>
  </div>
</div>

<ul class="nav sip-nav gap-1 mb-4">
  <li class="nav-item"><a class="nav-link active" href="sip.php">Dashboard</a></li>
  <li class="nav-item"><a class="nav-link" href="sip_log.php">Napló</a></li>
  <li class="nav-item"><a class="nav-link" href="sip_numbers.php">Számok</a></li>
</ul>

<div class="row g-3 mb-3">
  <!-- Endpoints -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header d-flex align-items-center gap-2 py-2">
        <span class="fw-semibold">Végpontok</span>
        <span class="badge bg-secondary ms-auto"><?= count($endpoints) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Végpont</th>
              <th>Felhasználónév</th>
              <th>Állapot</th>
              <th>Csatornák</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$endpoints): ?>
            <tr><td colspan="4" class="text-muted text-center py-3 small">Nincs adat (Asterisk nem elérhető?)</td></tr>
          <?php else: foreach ($endpoints as $ep):
            $registered = str_contains(strtolower($ep['state']), 'available') && !str_contains(strtolower($ep['state']), 'un');
            $inuse      = str_contains(strtolower($ep['state']), 'in use');
          ?>
            <tr>
              <td class="ps-3 sip-mono fw-semibold"><?= h($ep['name']) ?></td>
              <td class="sip-mono text-muted"><?= h($ep['username'] ?? '–') ?></td>
              <td>
                <?php if ($inuse): ?>
                  <span class="pulse me-1"></span><span class="badge bg-success">Aktív hívás</span>
                <?php elseif ($registered): ?>
                  <span class="pulse me-1"></span><span class="badge bg-success">Regisztrált</span>
                <?php else: ?>
                  <span class="pulse offline me-1"></span><span class="badge bg-secondary">Nem elérhető</span>
                <?php endif; ?>
              </td>
              <td class="text-muted sip-mono"><?= (int)$ep['channels'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Registrations -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header d-flex align-items-center gap-2 py-2">
        <span class="fw-semibold">Upstream regisztrációk</span>
        <span class="badge bg-secondary ms-auto"><?= count($registrations) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Szám / szerver</th>
              <th>Állapot</th>
              <th>Lejárat</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$registrations): ?>
            <tr><td colspan="3" class="text-muted text-center py-3 small">Nincs regisztráció</td></tr>
          <?php else: foreach ($registrations as $reg):
            $ok = ($reg['status'] === 'Registered');
          ?>
            <tr>
              <td class="ps-3 sip-mono small"><?= h($reg['server']) ?></td>
              <td>
                <?php if ($ok): ?>
                  <span class="pulse me-1"></span><span class="badge bg-success"><?= h($reg['status']) ?></span>
                <?php else: ?>
                  <span class="pulse offline me-1"></span><span class="badge bg-danger"><?= h($reg['status']) ?></span>
                <?php endif; ?>
              </td>
              <td class="sip-mono text-muted"><?= $reg['expiry'] !== null ? $reg['expiry'] . 's' : '–' ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Recent events -->
<div class="card shadow-sm">
  <div class="card-header py-2 d-flex align-items-center">
    <span class="fw-semibold">Legutóbbi push értesítések</span>
    <a href="sip_log.php" class="btn btn-sm btn-link ms-auto py-0">Teljes napló →</a>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">Időpont</th>
          <th>Végpont</th>
          <th>Hívó szám</th>
          <th>APNs eredmény</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$recent_events): ?>
        <tr><td colspan="4" class="text-muted text-center py-3 small">Még nincs push esemény</td></tr>
      <?php else: foreach ($recent_events as $ev): ?>
        <tr>
          <td class="ps-3 sip-mono text-muted small"><?= h($ev['ts']) ?></td>
          <td class="sip-mono"><?= h($ev['app_user'] ?? '–') ?></td>
          <td class="sip-mono"><?= h($ev['caller_id'] ?? '–') ?></td>
          <td>
            <?php if ($ev['ok'] === true): ?>
              <span class="badge bg-success">HTTP 200 – OK</span>
            <?php elseif ($ev['ok'] === false): ?>
              <span class="badge bg-danger">HTTP <?= (int)$ev['status_code'] ?><?= isset($ev['apns_body']) ? ' – ' . h($ev['apns_body']) : '' ?></span>
            <?php else: ?>
              <span class="badge bg-secondary">–</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.getElementById('last-refresh').textContent =
  'Frissítve: ' + new Date().toLocaleTimeString('hu-HU');
setTimeout(() => location.reload(), 30000);
</script>

<?php require __DIR__ . '/../../app/views/layout/footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/WeightService.php';
require_once __DIR__ . '/../app/Services/AIService.php';

$page  = 'goals';
$title = 'Profil és célok';

$u      = current_user() ?? [];
$userId = (int)($u['id'] ?? 0);
$svc    = new WeightService();
$ai     = new AIService();

// Mentés
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $svc->saveProfile($userId, $_POST);
  flash_set('ok', 'Profil mentve!');
  redirect('goals.php');
}

$profile  = $svc->getProfile($userId);
$latest   = $svc->getLatest($userId);

// Ajánlott kalória számítás
$recCal = null;
if ($profile && $latest) {
  $recCal = $svc->recommendedCalories($profile, (float)$latest['weight_kg']);
}

// AI edzésterv
$exercisePlan = null;
if ($ai->isEnabled() && !empty($_GET['ai_plan']) && $profile) {
  $exercisePlan = $ai->generateExercisePlan($profile);
}

require_once __DIR__ . '/_header.php';
?>

<!-- Daemon monitor – teljes szélességben felül -->
<div class="card mb-4" id="daemonCard">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>🤖 Fitness Guru – Mattermost bot figyelő</strong>
    <div class="d-flex align-items-center gap-2">
      <span id="daemonBadge" class="badge bg-secondary">Ellenőrzés...</span>
      <span id="daemonPid" class="text-muted small"></span>
    </div>
  </div>
  <div class="card-body">
    <div class="d-flex gap-2 mb-3 flex-wrap">
      <button class="btn btn-success btn-sm" onclick="daemonAction('start')">▶ Indítás</button>
      <button class="btn btn-warning btn-sm" onclick="daemonAction('restart')">🔄 Újraindítás</button>
      <button class="btn btn-danger btn-sm" onclick="daemonAction('stop')">⏹ Leállítás</button>
      <button class="btn btn-outline-secondary btn-sm ms-auto" onclick="loadStatus()">↻ Frissítés</button>
    </div>
    <div id="daemonMsg" class="small text-muted mb-2"></div>
    <div class="bg-dark text-success rounded p-2" style="font-family:monospace;font-size:.75rem;height:180px;overflow-y:auto;white-space:pre-wrap" id="daemonLog">Betöltés...</div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><strong>🎯 Profil és célok beállítása</strong></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

          <h6 class="text-muted mb-3">Személyes adatok</h6>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Magasság (cm)</label>
              <input type="number" name="height_cm" class="form-control" min="100" max="250"
                     value="<?= (int)($profile['height_cm'] ?? 0) ?: '' ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Születési év</label>
              <input type="number" name="birth_year" class="form-control" min="1940" max="2010"
                     value="<?= (int)($profile['birth_year'] ?? 0) ?: '' ?>">
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Nem</label>
              <select name="gender" class="form-select">
                <?php foreach (['férfi','nő','egyéb'] as $g): ?>
                  <option <?= ($profile['gender'] ?? 'férfi') === $g ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Aktivitási szint</label>
              <select name="activity_level" class="form-select">
                <?php foreach (['ülő'=>'Ülő (irodai munka)','könnyű'=>'Könnyű (séta)','mérsékelt'=>'Mérsékelt (3-5x/hét)','aktív'=>'Aktív (6-7x/hét)','nagyon aktív'=>'Nagyon aktív (fizikai munka)'] as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= ($profile['activity_level'] ?? 'mérsékelt') === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <h6 class="text-muted mb-3 mt-4">Célok</h6>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Célsúly (kg)</label>
              <input type="number" name="target_weight_kg" class="form-control" step="0.1" min="30" max="300"
                     value="<?= ($profile['target_weight_kg'] ?? '') ?: '' ?>">
            </div>
            <div class="col-6">
              <label class="form-label">
                Napi kalória cél
                <?php if ($recCal): ?>
                  <small class="text-success">(ajánlott: ~<?= $recCal ?> kcal)</small>
                <?php endif; ?>
              </label>
              <input type="number" name="daily_calorie_goal" class="form-control" min="800" max="5000"
                     value="<?= (int)($profile['daily_calorie_goal'] ?? 2000) ?>">
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-4">
              <label class="form-label">Fehérje cél (g)</label>
              <input type="number" name="protein_goal_g" class="form-control" min="0"
                     value="<?= (int)($profile['protein_goal_g'] ?? 120) ?>">
            </div>
            <div class="col-4">
              <label class="form-label">Szénhidrát cél (g)</label>
              <input type="number" name="carbs_goal_g" class="form-control" min="0"
                     value="<?= (int)($profile['carbs_goal_g'] ?? 200) ?>">
            </div>
            <div class="col-4">
              <label class="form-label">Zsír cél (g)</label>
              <input type="number" name="fat_goal_g" class="form-control" min="0"
                     value="<?= (int)($profile['fat_goal_g'] ?? 65) ?>">
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Napi mozgás cél (perc)</label>
              <input type="number" name="exercise_goal_min" class="form-control" min="0"
                     value="<?= (int)($profile['exercise_goal_min'] ?? 30) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Víz cél (ml)</label>
              <input type="number" name="water_goal_ml" class="form-control" min="500"
                     value="<?= (int)($profile['water_goal_ml'] ?? 2500) ?>">
            </div>
          </div>

          <h6 class="text-muted mb-3 mt-4">Mattermost integráció</h6>
          <div class="mb-3">
            <label class="form-label">Mattermost felhasználónév</label>
            <div class="input-group">
              <input type="text" name="mattermost_username" id="mmUsernameInput"
                     class="form-control" placeholder="pl. kaly (@ nélkül)"
                     value="<?= e($profile['mattermost_username'] ?? '') ?>">
              <button type="button" class="btn btn-outline-secondary"
                      onclick="mmLookup()" title="DM channel ID felderítés">
                🔍 Felderítés
              </button>
            </div>
            <div class="form-text">A bot erre a felhasználóra küld DM-et.</div>
          </div>
          <div id="mmLookupResult" class="d-none mb-3"></div>

          <button type="submit" class="btn btn-success w-100">💾 Profil mentése</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <!-- Integráció állapot -->
    <div class="card mb-3">
      <div class="card-header"><strong>⚙️ Integráció állapota</strong></div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
          <span>🤖 Claude AI</span>
          <span class="badge <?= $ai->isEnabled() ? 'bg-success' : 'bg-secondary' ?>">
            <?= $ai->isEnabled() ? 'Aktív' : 'Inaktív' ?>
          </span>
        </div>
        <?php require_once __DIR__ . '/../app/Services/MattermostService.php'; $mm = new MattermostService(); ?>
        <div class="d-flex justify-content-between mb-2">
          <span>💬 Mattermost bot</span>
          <span class="badge <?= $mm->isEnabled() ? 'bg-success' : 'bg-secondary' ?>">
            <?= $mm->isEnabled() ? 'Aktív' : 'Inaktív' ?>
          </span>
        </div>
        <?php if ($mm->isEnabled()): ?>
        <div class="small text-muted mb-2">
          <div>🌐 <?= e(cfg('mattermost.server_url','–')) ?></div>
          <div>👤 Target: <code><?= e(cfg('mattermost.target_user','–')) ?></code>
               <?php if (cfg('mattermost.dm_channel_id','')): ?>
                 · Channel: <code class="user-select-all"><?= e(cfg('mattermost.dm_channel_id')) ?></code>
               <?php endif; ?>
          </div>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-2"
                onclick="mmTestMessage()">📨 Teszt üzenet küldése</button>
        <div id="mmTestResult" class="small"></div>
        <?php endif; ?>
        <hr>
        <p class="small text-muted mb-1">Konfig: <code>fitnessmgr/app/config.php</code></p>
        <p class="small text-muted mb-0">
          Claude API: <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>
        </p>
      </div>
    </div>

    <!-- Cron job beállítási útmutató -->
    <div class="card mb-3">
      <div class="card-header"><strong>⏰ Automatikus üzenetek (crontab)</strong></div>
      <div class="card-body">
        <p class="small text-muted">Parancssor (<code>crontab -e</code>):</p>
        <pre class="bg-light p-2 rounded small" style="font-size:.75rem">0 7  * * * php /var/www/html/fitnessmgr/cli/daily_checkin.php morning
0 12 * * * php /var/www/html/fitnessmgr/cli/meal_reminder.php lunch
0 19 * * * php /var/www/html/fitnessmgr/cli/meal_reminder.php dinner
0 21 * * * php /var/www/html/fitnessmgr/cli/daily_checkin.php evening
0 20 * * 0 php /var/www/html/fitnessmgr/cli/weekly_summary.php</pre>
      </div>
    </div>

    <!-- AI edzésterv -->
    <?php if ($ai->isEnabled()): ?>
    <div class="card ai-card">
      <div class="card-body">
        <div class="card-title">🤖 AI Edzésterv javaslat</div>
        <?php if ($exercisePlan): ?>
          <p class="small"><?= nl2br(e($exercisePlan)) ?></p>
        <?php else: ?>
          <p class="small opacity-75">Az AI az aktivitási szinted és céljaid alapján személyre szabott heti edzéstervet ad.</p>
          <a href="?ai_plan=1" class="btn btn-light btn-sm">Javaslat kérése</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function loadStatus() {
  fetch('api/daemon_control.php?action=status')
    .then(r => r.json())
    .then(d => {
      const badge = document.getElementById('daemonBadge');
      const pid   = document.getElementById('daemonPid');
      const log   = document.getElementById('daemonLog');
      if (d.running) {
        badge.className = 'badge bg-success';
        badge.textContent = 'Fut';
        pid.textContent = 'PID: ' + d.pid;
      } else {
        badge.className = 'badge bg-danger';
        badge.textContent = 'Leállítva';
        pid.textContent = '';
      }
      if (d.log) {
        log.textContent = d.log;
        log.scrollTop = log.scrollHeight;
      }
    })
    .catch(() => {
      document.getElementById('daemonBadge').textContent = 'Hiba';
    });
}

function daemonAction(action) {
  const msg = document.getElementById('daemonMsg');
  msg.textContent = 'Folyamatban...';
  fetch('api/daemon_control.php?action=' + action)
    .then(r => r.json())
    .then(d => {
      msg.textContent = d.msg || d.error || '';
      setTimeout(loadStatus, 1500);
    });
}

// Kezdeti betöltés
loadStatus();
// Auto-frissítés 10 másodpercenként
setInterval(loadStatus, 10000);

function mmLookup() {
  const username = document.getElementById('mmUsernameInput').value.trim();
  if (!username) { alert('Add meg a Mattermost felhasználónevet!'); return; }
  const box = document.getElementById('mmLookupResult');
  box.className = 'alert alert-secondary mb-3 small p-2';
  box.textContent = '🔍 Keresés...';
  fetch('api/mm_lookup.php?username=' + encodeURIComponent(username))
    .then(r => r.json())
    .then(d => {
      if (d.error) {
        box.className = 'alert alert-danger mb-3 small p-2';
        box.textContent = '❌ ' + d.error;
      } else {
        box.className = 'alert alert-success mb-3 small p-2';
        box.innerHTML =
          '<strong>✅ Megtalálva!</strong><br>' +
          'User ID: <code class="user-select-all">' + d.user_id + '</code><br>' +
          'DM Channel ID: <code class="user-select-all">' + d.channel_id + '</code><br>' +
          '<span class="text-muted">Másold be a <code>config.php</code> <code>dm_channel_id</code> mezőjébe ha eltér.</span>';
      }
    })
    .catch(e => {
      box.className = 'alert alert-danger mb-3 small p-2';
      box.textContent = '❌ Hálózati hiba: ' + e;
    });
}

function mmTestMessage() {
  const btn = event.target;
  const res = document.getElementById('mmTestResult');
  btn.disabled = true;
  res.textContent = 'Küldés...';
  fetch('api/mm_lookup.php?action=test')
    .then(r => r.json())
    .then(d => {
      res.textContent = d.ok ? '✅ ' + d.msg : '❌ ' + (d.error || d.msg);
    })
    .catch(() => { res.textContent = '❌ Hálózati hiba'; })
    .finally(() => { btn.disabled = false; });
}
</script>
JS;
?>
<?php require_once __DIR__ . '/_footer.php'; ?>

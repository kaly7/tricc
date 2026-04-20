<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Core\Database;
use App\Services\DeviceService;
use App\Services\AlertService;
use App\Services\BridgeStatusService;

$db = Database::connection();
$deviceService = new DeviceService();
$alertService = new AlertService();
$bridgeService = new BridgeStatusService();
$devices = $deviceService->all();
$alertsPage = resolve_page($_GET['alerts_page'] ?? 1);
$alertsPerPage = 20;
$alertsTotal = $alertService->count();
$alerts = $alertService->recentPage($alertsPage, $alertsPerPage);
$latestAlertsForAudio = $alertService->recent(20);
$bridgeWorkers = $bridgeService->all();

$stats = [
    'devices_total' => (int) $db->query("SELECT COUNT(*) FROM devices")->fetchColumn(),
    'devices_online' => (int) $db->query("SELECT COUNT(*) FROM device_last_state WHERE online = 1")->fetchColumn(),
    'alerts_open' => (int) $db->query("SELECT COUNT(*) FROM alerts WHERE ts >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    'queued_commands' => (int) $db->query("SELECT COUNT(*) FROM command_queue WHERE status = 'queued'")->fetchColumn(),
    'config_mismatch' => (int) $db->query("SELECT COUNT(*) FROM device_last_state s JOIN (SELECT device_id, MAX(config_version) AS config_version FROM device_config GROUP BY device_id) c ON c.device_id = s.device_id WHERE s.reported_config_version IS NOT NULL AND c.config_version <> s.reported_config_version")->fetchColumn(),
    'bridge_running' => (int) $db->query("SELECT COUNT(*) FROM worker_status WHERE status = 'running'")->fetchColumn(),
    'devices_with_active_alerts' => $alertService->countDevicesWithActiveAlerts(),
];

$pageTitle = 'Vezérlőpult';
include __DIR__ . '/../templates/header.php';
?>
<div class="hero-grid">
    <section class="panel panel-highlight">
        <div class="eyebrow">Központi háttér</div>
        <h1>PP Control Center</h1>
        <p>Eszközök, konfigurációk, queue és Mattermost integráció egy helyen. Az ESP a letöltött szabályok alapján helyben dönt, a központ pedig naplóz és felügyel.</p>
        <div class="hero-actions">
            <a class="btn btn-primary" href="<?= e(app_url('devices.php')) ?>">Eszközök</a>
            <a class="btn btn-outline-secondary" href="<?= e(app_url('queue.php')) ?>">Queue</a>
        </div>
    </section>
    <section class="panel stats-panel" id="overview-stats-panel">
        <div class="stat-card"><span>Eszközök</span><strong data-stat-key="devices_total"><?= e((string) $stats['devices_total']) ?></strong></div>
        <div class="stat-card"><span>Online</span><strong data-stat-key="devices_online"><?= e((string) $stats['devices_online']) ?></strong></div>
        <div class="stat-card"><span>7 napos esemény</span><strong data-stat-key="alerts_open"><?= e((string) $stats['alerts_open']) ?></strong></div>
        <div class="stat-card"><span>Queue</span><strong data-stat-key="queued_commands"><?= e((string) $stats['queued_commands']) ?></strong></div>
        <div class="stat-card"><span>Config eltérés</span><strong data-stat-key="config_mismatch"><?= e((string) $stats['config_mismatch']) ?></strong></div>
        <div class="stat-card"><span>Aktív riasztásos eszköz</span><strong data-stat-key="devices_with_active_alerts"><?= e((string) $stats['devices_with_active_alerts']) ?></strong></div>
        <div class="stat-card"><span>Bridge worker</span><strong data-stat-key="bridge_running"><?= e((string) $stats['bridge_running']) ?></strong></div>
    </section>
</div>

<div class="content-grid two-col">
    <section class="panel">
        <div class="section-head">
            <h2>Eszközök</h2>
            <a class="text-link" href="<?= e(app_url('devices.php')) ?>">Összes</a>
        </div>
        <div id="overview-devices-block">
            <?php include __DIR__ . '/../templates/overview_devices_fragment.php'; ?>
        </div>
    </section>

    <section class="panel">
        <div class="section-head alerts-head">
            <h2>Friss riasztások</h2>
            <div class="alert-tools">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="alert-audio-toggle">
                    <label class="form-check-label" for="alert-audio-toggle">Hangjelzés</label>
                </div>
                <span class="audio-status muted small" id="alert-audio-status">Ki</span>
                <a class="text-link" href="<?= e(app_url('alerts.php')) ?>">Összes</a>
            </div>
        </div>
        <div id="overview-alerts-block">
            <?php include __DIR__ . '/../templates/overview_alerts_fragment.php'; ?>
        </div>
    </section>
</div>

<section class="panel mt-4">
    <div class="section-head">
        <h2>Bridge státusz</h2>
        <a class="text-link" href="<?= e(app_url('bridge.php')) ?>">Részletek</a>
    </div>
    <div class="table-wrap">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>Worker</th>
                <th>Állapot</th>
                <th>Heartbeat</th>
                <th>Utolsó hiba</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($bridgeWorkers as $worker): ?>
                <tr>
                    <td><code><?= e($worker['worker_name']) ?></code></td>
                    <td><?= bridge_status_badge((string) $worker['status']) ?></td>
                    <td><?= e($worker['heartbeat_at'] ?: '—') ?></td>
                    <td><?= e($worker['last_error'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$bridgeWorkers): ?>
                <tr><td colspan="4" class="text-center text-muted">Még nincs bridge heartbeat adat.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(function () {
    const devicesBlock = document.getElementById('overview-devices-block');
    const alertsBlock = document.getElementById('overview-alerts-block');
    const statsPanel = document.getElementById('overview-stats-panel');
    const audioToggle = document.getElementById('alert-audio-toggle');
    const audioStatus = document.getElementById('alert-audio-status');
    if (!devicesBlock || !alertsBlock || !statsPanel || !audioToggle || !audioStatus) {
        return;
    }

    const statNodes = Array.from(statsPanel.querySelectorAll('[data-stat-key]')).reduce((map, node) => {
        const key = String(node.getAttribute('data-stat-key') || '');
        if (key !== '') {
            map[key] = node;
        }
        return map;
    }, {});

    const endpoint = <?= json_encode(app_url('api/overview_snapshot.php')) ?>;
    const alertsPage = <?= (int) $alertsPage ?>;
    const initialLatestAlerts = <?= json_encode(array_map(static function (array $alert): array {
        return [
            'id' => (int) ($alert['id'] ?? 0),
            'event_type' => (string) ($alert['event_type'] ?? ''),
            'severity' => (string) ($alert['severity'] ?? ''),
        ];
    }, $latestAlertsForAudio), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const audioPrefKey = 'pp_center_alert_audio_enabled';

    let refreshInFlight = false;
    let lastSeenAlertId = initialLatestAlerts.reduce((maxId, alert) => {
        const id = Number(alert && alert.id ? alert.id : 0);
        return id > maxId ? id : maxId;
    }, 0);
    let audioContext = null;
    let audioUnlocked = false;

    function loadAudioPreference() {
        try {
            return window.localStorage.getItem(audioPrefKey) === '1';
        } catch (error) {
            console.debug('localStorage nem elérhető', error);
            return false;
        }
    }

    function saveAudioPreference(enabled) {
        try {
            window.localStorage.setItem(audioPrefKey, enabled ? '1' : '0');
        } catch (error) {
            console.debug('localStorage mentési hiba', error);
        }
    }

    function ensureAudioContext() {
        if (audioContext) {
            return audioContext;
        }
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) {
            return null;
        }
        audioContext = new Ctx();
        audioUnlocked = audioContext.state === 'running';
        return audioContext;
    }

    async function unlockAudioContext() {
        if (!audioToggle.checked) {
            audioUnlocked = false;
            updateAudioStatus();
            return false;
        }
        const ctx = ensureAudioContext();
        if (!ctx) {
            audioUnlocked = false;
            updateAudioStatus();
            return false;
        }
        try {
            if (ctx.state === 'suspended') {
                await ctx.resume();
            }
        } catch (error) {
            console.debug('AudioContext resume hiba', error);
        }
        audioUnlocked = ctx.state === 'running';
        updateAudioStatus();
        return audioUnlocked;
    }

    function updateAudioStatus() {
        if (!audioToggle.checked) {
            audioStatus.textContent = 'Ki';
            return;
        }
        audioStatus.textContent = audioUnlocked ? 'Be' : 'Be · érintés kell';
    }

    function playToneSequence(sequence) {
        const ctx = ensureAudioContext();
        if (!ctx || ctx.state !== 'running') {
            audioUnlocked = false;
            updateAudioStatus();
            return;
        }

        const startAt = ctx.currentTime + 0.02;
        sequence.forEach((tone) => {
            const oscillator = ctx.createOscillator();
            const gainNode = ctx.createGain();
            oscillator.type = tone.type || 'sine';
            oscillator.frequency.setValueAtTime(tone.frequency, startAt + tone.offset);

            const gain = tone.gain || 0.05;
            const attack = tone.attack || 0.01;
            const duration = tone.duration || 0.16;
            const release = tone.release || 0.12;

            gainNode.gain.setValueAtTime(0.0001, startAt + tone.offset);
            gainNode.gain.exponentialRampToValueAtTime(gain, startAt + tone.offset + attack);
            gainNode.gain.exponentialRampToValueAtTime(0.0001, startAt + tone.offset + duration + release);

            oscillator.connect(gainNode);
            gainNode.connect(ctx.destination);
            oscillator.start(startAt + tone.offset);
            oscillator.stop(startAt + tone.offset + duration + release + 0.02);
        });
    }

    function playAlarmSound() {
        playToneSequence([
            { offset: 0.00, frequency: 784, duration: 0.12, gain: 0.05, type: 'square', attack: 0.005, release: 0.09 },
            { offset: 0.18, frequency: 988, duration: 0.12, gain: 0.055, type: 'square', attack: 0.005, release: 0.09 },
            { offset: 0.36, frequency: 784, duration: 0.15, gain: 0.05, type: 'square', attack: 0.005, release: 0.10 }
        ]);
    }

    function playClearSound() {
        playToneSequence([
            { offset: 0.00, frequency: 659, duration: 0.18, gain: 0.028, type: 'sine', attack: 0.02, release: 0.18 },
            { offset: 0.22, frequency: 523, duration: 0.26, gain: 0.026, type: 'sine', attack: 0.02, release: 0.22 }
        ]);
    }

    function classifyLatestAlerts(alerts) {
        if (!Array.isArray(alerts) || alerts.length === 0) {
            return null;
        }

        const newAlerts = alerts.filter((alert) => Number(alert && alert.id ? alert.id : 0) > lastSeenAlertId);
        if (newAlerts.length === 0) {
            return null;
        }

        newAlerts.forEach((alert) => {
            const id = Number(alert && alert.id ? alert.id : 0);
            if (id > lastSeenAlertId) {
                lastSeenAlertId = id;
            }
        });

        let hasActiveAlarm = false;
        let hasClearedAlarm = false;

        newAlerts.forEach((alert) => {
            const eventType = String(alert && alert.event_type ? alert.event_type : '');
            const severity = String(alert && alert.severity ? alert.severity : '');
            if (/_cleared$/i.test(eventType)) {
                hasClearedAlarm = true;
                return;
            }
            if (['warning', 'danger', 'critical'].includes(severity)) {
                hasActiveAlarm = true;
            }
        });

        if (hasActiveAlarm) {
            return 'alarm';
        }
        if (hasClearedAlarm) {
            return 'clear';
        }
        return null;
    }

    async function handleLatestAlerts(latestAlerts) {
        const soundType = classifyLatestAlerts(latestAlerts);
        if (!soundType || !audioToggle.checked) {
            return;
        }

        const unlocked = await unlockAudioContext();
        if (!unlocked) {
            return;
        }

        if (soundType === 'alarm') {
            playAlarmSound();
        } else if (soundType === 'clear') {
            playClearSound();
        }
    }

    function applyOverviewSnapshot(data) {
        if (data && typeof data.devices_html === 'string') {
            devicesBlock.innerHTML = data.devices_html;
        }
        if (data && typeof data.alerts_html === 'string') {
            alertsBlock.innerHTML = data.alerts_html;
        }
        if (data && typeof data.stats === 'object' && data.stats !== null) {
            Object.entries(data.stats).forEach(([key, value]) => {
                if (Object.prototype.hasOwnProperty.call(statNodes, key)) {
                    statNodes[key].textContent = String(value);
                }
            });
        }
    }

    async function fetchOverviewSnapshot() {
        const url = endpoint + '?alerts_page=' + encodeURIComponent(String(alertsPage));
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timeoutId = window.setTimeout(() => {
            if (controller) {
                controller.abort();
            }
        }, 8000);

        try {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store',
                signal: controller ? controller.signal : undefined
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return await response.json();
        } finally {
            window.clearTimeout(timeoutId);
        }
    }

    async function refreshOverview() {
        if (refreshInFlight || document.hidden) {
            return;
        }
        refreshInFlight = true;
        try {
            const data = await fetchOverviewSnapshot();
            applyOverviewSnapshot(data);
            Promise.resolve(handleLatestAlerts(data ? data.latest_alerts : null)).catch((error) => {
                console.debug('Hangjelzés feldolgozási hiba', error);
            });
        } catch (error) {
            console.debug('Áttekintés frissítési hiba', error);
        } finally {
            refreshInFlight = false;
        }
    }

    audioToggle.checked = loadAudioPreference();
    updateAudioStatus();

    audioToggle.addEventListener('change', async function () {
        saveAudioPreference(audioToggle.checked);
        if (!audioToggle.checked && audioContext && audioContext.state === 'running') {
            try {
                await audioContext.suspend();
            } catch (error) {
                console.debug('AudioContext suspend hiba', error);
            }
            audioUnlocked = false;
            updateAudioStatus();
            return;
        }
        await unlockAudioContext();
    });

    ['pointerdown', 'keydown'].forEach((eventName) => {
        document.addEventListener(eventName, function () {
            if (audioToggle.checked) {
                unlockAudioContext();
            }
        });
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refreshOverview();
        }
    });

    refreshOverview();
    setInterval(refreshOverview, 10000);
})();
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>


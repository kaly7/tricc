<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\DeviceService;

$deviceService = new DeviceService();
$page = resolve_page($_GET['page'] ?? 1);
$perPage = resolve_per_page($_GET['per_page'] ?? 20);
$total = $deviceService->count();
$devices = $deviceService->allPage($page, $perPage);
$pageTitle = 'Eszközök';
include __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Eszközkezelés</div>
        <h1>Eszközök</h1>
    </div>
    <div class="d-flex gap-2">
        <form method="get">
            <select class="form-select" name="per_page" onchange="this.form.submit()">
                <?php foreach (per_page_options() as $opt): ?>
                    <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?>/oldal</option>
                <?php endforeach; ?>
            </select>
        </form>
        <a class="btn btn-primary" href="<?= e(app_url('device.php')) ?>">Új eszköz</a>
    </div>
</div>

<section class="panel">
    <div class="table-wrap">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Név</th>
                    <th>Azonosító</th>
                    <th>Hely</th>
                    <th>Állapot</th>
                    <th>Utolsó kapcsolat</th>
                    <th>Riasztás</th>
                    <th>Akku</th>
                    <th>Táp</th>
                    <th>RSSI</th>
                    <th>Config</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $device): ?>
                <tr>
                    <td><a href="<?= e(app_url('device.php?device_id=' . urlencode($device['device_id']))) ?>"><?= e($device['name']) ?></a></td>
                    <td><code><?= e($device['device_id']) ?></code></td>
                    <td><?= e($device['location']) ?></td>
                    <td><?= device_status_badge((bool) ($device['online'] ?? false)) ?></td>
                    <td><?= e($device['last_seen_at'] ?? '—') ?></td>
                    <td>
                        <?php $activeAlertCount = (int) ($device['active_alert_count'] ?? 0); ?>
                        <?php $recentAlertCount = (int) ($device['recent_alert_count'] ?? 0); ?>
                        <?php if ($activeAlertCount > 0): ?>
                            <span class="badge-status status-offline">Aktív: <?= e((string) $activeAlertCount) ?></span>
                        <?php else: ?>
                            <span class="badge-status status-online">Nincs aktív</span>
                        <?php endif; ?>
                        <div class="small muted">24 óra: <?= e((string) $recentAlertCount) ?></div>
                    </td>
                    <td><?= e($device['battery_pct'] !== null ? $device['battery_pct'] . '%' : '—') ?></td>
                    <td><?= e($device['power_mode'] ?? '—') ?></td>
                    <td><?= e($device['rssi'] ?? '—') ?></td>
                    <td>
                        <div class="small">K: <?= e((string) ($device['desired_config_version'] ?? '—')) ?></div>
                        <div class="small">J: <?= e((string) ($device['reported_config_version'] ?? '—')) ?></div>
                        <?php if ((int) ($device['config_mismatch'] ?? 0) === 1): ?><span class="badge-status status-warn">Eltérés</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($page, $perPage, $total, 'devices.php') ?>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>

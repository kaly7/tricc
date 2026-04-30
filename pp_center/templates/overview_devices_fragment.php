<?php
/** @var array<int,array<string,mixed>> $devices */

$overviewRawPick = static function (array $raw, array $paths, mixed $default = null): mixed {
    foreach ($paths as $path) {
        $value = $raw;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }
            $value = null;
            break;
        }
        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return $default;
};
?>
<div class="overview-device-list">
    <?php foreach ($devices as $device): ?>
        <?php
            $deviceUrl = app_url('device.php?device_id=' . urlencode((string) $device['device_id']));
            $raw = json_decode((string) ($device['raw_json'] ?? ''), true);
            if (!is_array($raw)) {
                $raw = [];
            }
            $lastSeenSeconds = null;
            if (!empty($device['last_seen_at'])) {
                $lastSeenTs = strtotime((string) $device['last_seen_at']);
                if ($lastSeenTs !== false) {
                    $lastSeenSeconds = max(0, time() - $lastSeenTs);
                }
            }
            $activeAlertCount = (int) ($device['active_alert_count'] ?? 0);
            $recentAlertCount = (int) ($device['recent_alert_count'] ?? 0);
            $activeTempAlertCount = (int) ($device['active_temp_alert_count'] ?? 0);
            $activeContactAlertCount = (int) ($device['active_contact_alert_count'] ?? 0);
            $activeHcCount = (int) ($device['active_hc_count'] ?? 0);
            $hasAnyAlarm = $activeAlertCount > 0 || $activeHcCount > 0;
            $powerMode = strtolower(trim((string) ($device['power_mode'] ?? '')));
            $onBattery = ($powerMode === 'battery');
            $contactValue = '—';
            $transportValue = $overviewRawPick($raw, ['telemetry_transport', 'meta.telemetry_transport', 'signal.transport'], '—');
            $wifiRssiValue = $overviewRawPick($raw, ['wifi_rssi', 'signal.wifi_rssi', 'signal.rssi'], $device['rssi'] ?? null);
            foreach (['contact_1', 'contact_2', 'contact_3', 'contact_4'] as $contactField) {
                if (!empty($device[$contactField]) && $device[$contactField] !== 'closed') {
                    $contactValue = (string) $device[$contactField];
                    break;
                }
            }
        ?>
        <article class="overview-device-card<?= $hasAnyAlarm ? ' overview-device-card--hc-alarm' : '' ?>" data-device-id="<?= e((string) $device['device_id']) ?>">
            <div class="overview-device-card__top">
                <span class="overview-device-id"><?= e((string) $device['device_id']) ?></span>
                <a class="overview-device-link" href="<?= e($deviceUrl) ?>"><?= e((string) $device['name']) ?></a>
            </div>
            <div class="overview-device-location"><?= e((string) ($device['location'] ?: '—')) ?></div>

            <div class="overview-device-row overview-device-row--status">
                <?= device_status_badge((bool) ($device['online'] ?? false)) ?>
                <?php if ($activeAlertCount > 0): ?>
                    <span class="badge-status status-offline">Aktív riasztás: <?= e((string) $activeAlertCount) ?></span>
                <?php else: ?>
                    <span class="badge-status status-online">Nincs aktív riasztás</span>
                <?php endif; ?>
            </div>

            <div class="overview-device-row overview-device-row--meta">
                <span><strong>Utolsó kapcsolat:</strong> <?= e($lastSeenSeconds !== null ? (string) $lastSeenSeconds . ' mp' : '—') ?></span>
                <span><strong>24 órás riasztások:</strong> <?= e((string) $recentAlertCount) ?></span>
                <span><strong>Konfig D/R:</strong> <?= e((string) ($device['desired_config_version'] ?? '—')) ?>/<?= e((string) ($device['reported_config_version'] ?? '—')) ?></span>
            </div>

            <div class="overview-device-row overview-device-row--metrics">
                <span class="overview-chip <?= $activeTempAlertCount > 0 ? 'overview-chip--alert' : '' ?>"><strong>Hő:</strong> <?= e($device['temperature'] !== null ? (string) $device['temperature'] . ' °C' : '—') ?></span>
                <span class="overview-chip"><strong>Pára:</strong> <?= e($device['humidity'] !== null ? (string) $device['humidity'] . ' %' : '—') ?></span>
                <span class="overview-chip"><strong>Légnyomás:</strong> <?= e($device['pressure_hpa'] !== null ? (string) $device['pressure_hpa'] . ' hPa' : '—') ?></span>
            </div>
            <div class="overview-device-row overview-device-row--metrics">
                <?php $battPctVal = $device['battery_pct']; ?>
                <span class="overview-chip<?= ($battPctVal !== null && (float)$battPctVal <= 20) ? ' overview-chip--alert' : '' ?>"><strong>Akku:</strong> <?= e($battPctVal !== null ? (string) $battPctVal . '%' : '—') ?></span>
                <span class="overview-chip"><strong>Átvitel:</strong> <?= e((string) $transportValue) ?></span>
                <span class="overview-chip"><strong>Wi‑Fi:</strong> <?= e($wifiRssiValue !== null ? (string) $wifiRssiValue . ' dBm' : '—') ?></span>
                <span class="overview-chip<?= $onBattery ? ($activeHcCount > 0 ? ' overview-chip--alert' : ' overview-chip--warn') : '' ?>"><strong>Táp:</strong> <?= e(power_mode_label($device['power_mode'] ?? null)) ?></span>
                <span class="overview-chip <?= $activeContactAlertCount > 0 ? 'overview-chip--alert' : '' ?>"><strong>Kontakt:</strong> <?= e($contactValue) ?></span>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if (!$devices): ?>
        <p class="muted">Még nincs felvett eszköz.</p>
    <?php endif; ?>
</div>

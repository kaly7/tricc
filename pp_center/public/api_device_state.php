<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Core\Database;
use App\Services\DeviceService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$deviceId = trim((string) ($_GET['device_id'] ?? ''));
if ($deviceId === '') {
    echo json_encode(['error' => 'device_id required']);
    exit;
}

$deviceService = new DeviceService();
$state = $deviceService->lastState($deviceId);
if (!is_array($state)) {
    echo json_encode(['error' => 'not found']);
    exit;
}

// Aktív riasztás számok (a lastState nem tartalmazza, külön lekérdezzük)
$db = Database::connection();
$alertSql = "
    SELECT
        COUNT(*) AS active_alert_count,
        SUM(CASE WHEN latest.event_type IN ('temp_high','temp_low') THEN 1 ELSE 0 END) AS active_temp_alert_count,
        SUM(CASE WHEN latest.event_type = 'contact_active' THEN 1 ELSE 0 END) AS active_contact_alert_count
    FROM (
        SELECT a.event_type
        FROM alerts a
        INNER JOIN (
            SELECT CASE
                       WHEN event_type IN ('temp_high','temp_high_cleared') THEN 'temp_high'
                       WHEN event_type IN ('temp_low','temp_low_cleared') THEN 'temp_low'
                       WHEN event_type IN ('contact_active','contact_cleared') THEN 'contact'
                   END AS alert_family,
                   MAX(id) AS max_id
            FROM alerts
            WHERE device_id = :did
              AND event_type IN ('temp_high','temp_high_cleared','temp_low','temp_low_cleared','contact_active','contact_cleared')
            GROUP BY alert_family
        ) latest_ids ON latest_ids.max_id = a.id
        WHERE a.event_type IN ('temp_high','temp_low','contact_active')
    ) latest
";
$astmt = $db->prepare($alertSql);
$astmt->execute(['did' => $deviceId]);
$alertCounts = $astmt->fetch(\PDO::FETCH_ASSOC) ?: ['active_alert_count' => 0, 'active_temp_alert_count' => 0, 'active_contact_alert_count' => 0];

// Aktív riasztás típusok tömbként (az applyState JS-hez kell)
$atypeStmt = $db->prepare("
    SELECT a.event_type
    FROM alerts a
    INNER JOIN (
        SELECT CASE
                   WHEN event_type IN ('temp_high','temp_high_cleared') THEN 'temp_high'
                   WHEN event_type IN ('temp_low','temp_low_cleared') THEN 'temp_low'
                   WHEN event_type IN ('contact_active','contact_cleared') THEN 'contact'
               END AS alert_family,
               MAX(id) AS max_id
        FROM alerts
        WHERE device_id = :did
          AND event_type IN ('temp_high','temp_high_cleared','temp_low','temp_low_cleared','contact_active','contact_cleared')
        GROUP BY alert_family
    ) li ON li.max_id = a.id
    WHERE a.event_type IN ('temp_high','temp_low','contact_active')
");
$atypeStmt->execute(['did' => $deviceId]);
$activeAlarmTypes = array_column($atypeStmt->fetchAll(\PDO::FETCH_ASSOC), 'event_type');

$stateRaw = json_decode((string) ($state['raw_json'] ?? ''), true);
if (!is_array($stateRaw)) {
    $stateRaw = [];
}

// LED állapotok és sensor_ok a raw_json-ból
$leds = isset($stateRaw['leds']) && is_array($stateRaw['leds']) ? array_values($stateRaw['leds']) : null;
$sensorOk = isset($stateRaw['sensor_ok']) ? (bool) $stateRaw['sensor_ok'] : null;

function raw_pick_api(array $raw, array $paths, mixed $default = null): mixed
{
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
}

echo json_encode([
    'online'                     => (bool) ($state['online'] ?? false),
    'last_seen_at'               => $state['last_seen_at'] ?? null,
    'desired_config_version'     => $state['desired_config_version'] ?? null,
    'reported_config_version'    => $state['reported_config_version'] ?? null,
    'temperature'                => $state['temperature'] ?? null,
    'humidity'                   => $state['humidity'] ?? null,
    'pressure_hpa'               => $state['pressure_hpa'] ?? null,
    'battery_pct'                => $state['battery_pct'] ?? null,
    'power_mode'                 => $state['power_mode'] ?? null,
    'active_alert_count'         => (int) ($alertCounts['active_alert_count'] ?? 0),
    'active_temp_alert_count'    => (int) ($alertCounts['active_temp_alert_count'] ?? 0),
    'active_contact_alert_count' => (int) ($alertCounts['active_contact_alert_count'] ?? 0),
    'active_alarm_types'         => $activeAlarmTypes,
    'leds'                       => $leds,
    'sensor_ok'                  => $sensorOk,
    'telemetry_transport'        => raw_pick_api($stateRaw, ['telemetry_transport', 'meta.telemetry_transport', 'signal.transport']),
    'wifi_ok'                    => raw_pick_api($stateRaw, ['wifi_ok', 'signal.wifi_ok']),
    'wifi_rssi'                  => raw_pick_api($stateRaw, ['wifi_rssi', 'signal.wifi_rssi', 'signal.rssi'], $state['rssi'] ?? null),
    'wifi_ip'                    => raw_pick_api($stateRaw, ['wifi_ip', 'details.wifi_ip', 'details.ip']),
    'gsm_ok'                     => raw_pick_api($stateRaw, ['gsm_ok', 'signal.gsm_ok']),
    'gsm_rssi'                   => raw_pick_api($stateRaw, ['gsm_rssi', 'signal.gsm_rssi']),
    'gsm_operator'               => raw_pick_api($stateRaw, ['gsm_operator', 'signal.gsm_operator', 'meta.gsm_operator', 'details.gsm_operator']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
/**
 * SSE (Server-Sent Events) endpoint az eszköz állapot valós idejű frissítéséhez.
 * A böngésző EventSource-szal csatlakozik; a PHP 2 mp-enként ellenőrzi
 * a DB-t, és ha last_seen_at változott, azonnal pusholja az új adatot.
 * Max 90 másodperces kapcsolat után a kliens automatikusan újracsatlakozik.
 */
require __DIR__ . '/../app/web_bootstrap.php';

use App\Core\Database;

// ── SSE fejlécek ────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');    // nginx: ne pufferelj
header('Connection: keep-alive');

// Output pufferelés kikapcsolása
if (ob_get_level()) {
    ob_end_flush();
}

$deviceId = trim((string) ($_GET['device_id'] ?? ''));
if ($deviceId === '') {
    echo "data: {\"error\":\"device_id_required\"}\n\n";
    flush();
    exit;
}

function raw_pick_sse(array $raw, array $paths, mixed $default = null): mixed
{
    foreach ($paths as $path) {
        $value = $raw;
        foreach (explode('.', $path) as $seg) {
            if (is_array($value) && array_key_exists($seg, $value)) {
                $value = $value[$seg];
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

function build_state_payload(string $deviceId, \PDO $db): ?array
{
    $stmt = $db->prepare("SELECT * FROM device_last_state WHERE device_id = :did LIMIT 1");
    $stmt->execute(['did' => $deviceId]);
    $state = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$state) {
        return null;
    }

    $raw = json_decode((string) ($state['raw_json'] ?? ''), true);
    if (!is_array($raw)) {
        $raw = [];
    }

    // LED állapotok a raw_json-ból (firmware küldi a reported state-ben)
    $leds = isset($raw['leds']) && is_array($raw['leds']) ? array_values($raw['leds']) : null;

    // Aktív riasztás típusok
    $astmt = $db->prepare("
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
    $astmt->execute(['did' => $deviceId]);
    $activeAlarms = array_column($astmt->fetchAll(\PDO::FETCH_ASSOC), 'event_type');

    return [
        'online'                     => (bool) ($state['online'] ?? false),
        'last_seen_at'               => $state['last_seen_at'] ?? null,
        'reported_config_version'    => $state['reported_config_version'] ?? null,
        'temperature'                => $state['temperature'] ?? null,
        'humidity'                   => $state['humidity'] ?? null,
        'pressure_hpa'               => $state['pressure_hpa'] ?? null,
        'battery_pct'                => $state['battery_pct'] ?? null,
        'power_mode'                 => $state['power_mode'] ?? null,
        'active_temp_alert_count'    => in_array('temp_high', $activeAlarms) || in_array('temp_low', $activeAlarms) ? 1 : 0,
        'active_contact_alert_count' => in_array('contact_active', $activeAlarms) ? 1 : 0,
        'active_alarm_types'         => $activeAlarms,
        'telemetry_transport'        => raw_pick_sse($raw, ['telemetry_transport', 'meta.telemetry_transport']),
        'wifi_ok'                    => raw_pick_sse($raw, ['wifi_ok', 'signal.wifi_ok']),
        'wifi_rssi'                  => raw_pick_sse($raw, ['wifi_rssi', 'signal.wifi_rssi', 'signal.rssi'], $state['rssi'] ?? null),
        'wifi_ip'                    => raw_pick_sse($raw, ['wifi_ip', 'details.wifi_ip', 'details.ip']),
        'gsm_ok'                     => raw_pick_sse($raw, ['gsm_ok', 'signal.gsm_ok']),
        'gsm_rssi'                   => raw_pick_sse($raw, ['gsm_rssi', 'signal.gsm_rssi']),
        'gsm_operator'               => raw_pick_sse($raw, ['gsm_operator', 'signal.gsm_operator', 'details.gsm_operator']),
        'leds'                       => $leds,
    ];
}

// ── Főhurok ─────────────────────────────────────────────────────────────────
$db = Database::connection();
$lastSeenAt = null;
$deadline = time() + 90;            // 90 mp után a kliens automatikusan újracsatlakozik

// Azonnal küldjük az aktuális állapotot
$payload = build_state_payload($deviceId, $db);
if ($payload) {
    $lastSeenAt = $payload['last_seen_at'];
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

while (time() < $deadline) {
    // Gyors ellenőrzés: változott-e last_seen_at?
    $chk = $db->prepare("SELECT last_seen_at FROM device_last_state WHERE device_id = :did LIMIT 1");
    $chk->execute(['did' => $deviceId]);
    $row = $chk->fetch(\PDO::FETCH_ASSOC);
    $currentTs = $row['last_seen_at'] ?? null;

    if ($currentTs !== $lastSeenAt) {
        $lastSeenAt = $currentTs;
        $payload = build_state_payload($deviceId, $db);
        if ($payload) {
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
        }
    }

    // Keepalive comment 10 másodpercenként (proxy-k nem zárják le az idle kapcsolatot)
    static $lastKeepalive = 0;
    if (time() - $lastKeepalive >= 10) {
        $lastKeepalive = time();
        echo ": keepalive\n\n";
        flush();
    }

    if (connection_aborted()) {
        break;
    }

    usleep(2_000_000);  // 2 másodperc
}

// Újracsatlakozás jelzése
echo "data: {\"reconnect\":true}\n\n";
flush();

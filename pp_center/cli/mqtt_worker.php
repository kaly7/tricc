<?php
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Logger;
use App\Services\AlertService;
use App\Services\BridgeStatusService;
use App\Services\CommandService;
use App\Services\MattermostService;
use App\Services\PayloadNormalizer;
use App\Services\TelemetryService;
use App\Services\DeviceService;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Kiértékeli a health-check feltételeket egy nyers firmware payload alapján.
 * Visszaad egy bool tömböt: hcKey => true ha hiba van.
 */
function evaluateHcConditions(array $raw, float $battLowPct): array
{
    $power  = is_array($raw['power']  ?? null) ? $raw['power']  : [];
    $wifi   = is_array($raw['wifi']   ?? null) ? $raw['wifi']   : [];
    $signal = is_array($raw['signal'] ?? null) ? $raw['signal'] : [];
    $gsm    = is_array($raw['gsm']    ?? null) ? $raw['gsm']    : [];

    // WiFi: explicit wifi_ok mező, fallback wifi.connected, fallback transport
    $wifiOk = $raw['wifi_ok'] ?? $wifi['connected'] ?? null;
    if ($wifiOk === null) {
        $transport = $raw['telemetry_transport'] ?? $signal['transport'] ?? null;
        if ($transport !== null) {
            $wifiOk = ($transport === 'wifi');
        }
    }

    // Power mode
    $powerMode = $raw['power_mode'] ?? $power['mode'] ?? null;
    if ($powerMode === null) {
        $usbPresent = $power['usb'] ?? $power['usb_present'] ?? null;
        if ($usbPresent !== null) {
            $powerMode = $usbPresent ? 'usb' : 'battery';
        }
    }

    // Battery %
    $battPct = $raw['battery_pct'] ?? $power['battery_pct'] ?? null;

    // GSM
    $gsmModemOk = $gsm['modem_ok'] ?? $raw['gsm_modem_ok'] ?? null;
    $gsmOperator = trim((string) ($gsm['operator'] ?? $raw['gsm_operator'] ?? $signal['gsm_operator'] ?? ''));

    // Szenzor
    $sensorOk = $raw['sensor_ok'] ?? null;

    return [
        'no_wifi'         => ($wifiOk !== null) ? !(bool) $wifiOk : false,
        'no_gsm_modem'    => ($gsmModemOk !== null) ? !(bool) $gsmModemOk : false,
        'no_gsm_operator' => ($gsmModemOk === true && $gsmOperator === ''),
        'no_usb_power'    => ($powerMode === 'battery'),
        'no_sensor'       => ($sensorOk !== null) ? !(bool) $sensorOk : false,
        'mqtt_offline'    => false,  // ha telemetria érkezik, MQTT él
        'battery_low'     => ($battPct !== null) ? ((float) $battPct <= $battLowPct) : false,
    ];
}

$telemetryService = new TelemetryService();
$alertService = new AlertService();
$commandService = new CommandService();
$mattermost = new MattermostService();
$bridgeStatus = new BridgeStatusService();
$deviceService = new DeviceService();

$server = (string) cfg('mqtt.host', '127.0.0.1');
$port = (int) cfg('mqtt.port', 1883);
$clientId = (string) cfg('mqtt.client_id', 'pp-bridge-worker');
$topics = (array) cfg('mqtt.topics', []);
$workerName = 'mqtt_worker';

Logger::write('worker', 'MQTT worker indul', compact('server', 'port', 'clientId'));
$bridgeStatus->heartbeat($workerName, 'starting', [
    'mqtt_host' => $server,
    'mqtt_port' => $port,
    'topic_count' => count($topics),
]);

while (true) {
    try {
        $mqtt = new MqttClient($server, $port, $clientId);
        $settings = (new ConnectionSettings())
            ->setUsername((string) cfg('mqtt.username', ''))
            ->setPassword((string) cfg('mqtt.password', ''))
            ->setKeepAliveInterval((int) cfg('mqtt.keep_alive', 60));

        $mqtt->connect($settings, (bool) cfg('mqtt.clean_session', false));
        $bridgeStatus->heartbeat($workerName, 'running', [
            'mqtt_host' => $server,
            'mqtt_port' => $port,
            'connected_at' => date('c'),
        ]);
        Logger::write('worker', 'MQTT worker csatlakozott', ['server' => $server, 'port' => $port]);

        foreach ($topics as $topic => $qos) {
            $mqtt->subscribe($topic, function (string $topic, string $message) use ($telemetryService, $alertService, $commandService, $mattermost, $deviceService): void {
                try {
                    Logger::write('mqtt', 'Bejövő MQTT üzenet', ['topic' => $topic, 'message' => $message]);
                    $payload = json_decode($message, true);
                    if (!is_array($payload)) {
                        Logger::write('mqtt', 'Érvénytelen JSON payload', ['topic' => $topic]);
                        return;
                    }

                    $parts = explode('/', $topic);
                    $deviceId = $parts[1] ?? ($payload['device_id'] ?? 'unknown');
                    $device = $deviceService->find($deviceId);
                    $deviceName = $device['name'] ?? $deviceId;
                    $previousState = $deviceService->lastState($deviceId);
                    $wasOnline = $previousState !== null ? (int) ($previousState['online'] ?? 0) : null;
                    $payloadIp = trim((string) (($payload['details']['ip'] ?? $payload['wifi_ip'] ?? $payload['ip'] ?? '') ?: ''));
                    $payloadGsmOperator = trim((string) (($payload['details']['gsm_operator'] ?? $payload['gsm_operator'] ?? (($payload['signal']['gsm_operator'] ?? '') ?: ($payload['meta']['gsm_operator'] ?? ''))) ?: ''));

                    if (str_ends_with($topic, '/telemetry')) {
                        $telemetryService->store($deviceId, $payload);
                        if ($wasOnline === 0) {
                            $normalized = $alertService->store($deviceId, [
                                'device_id' => $deviceId,
                                'event_type' => 'device_online',
                                'severity' => 'info',
                                'message' => 'Eszkoz ujra elerheto',
                                'ts' => $payload['ts'] ?? null,
                                'reason' => 'telemetry_resumed',
                            ]);
                            $mattermost->notify(
                                'Eszköz újra elérhető: ' . $deviceName,
                                'Az eszköz ismét jelentkezett és online állapotba került.',
                                'good',
                                [
                                    'Eszköz' => $deviceId,
                                    'Idő (szerver)' => (string) ($normalized['server_received_at'] ?? date('Y-m-d H:i:s')),
                                    'Ok' => 'telemetry',
                                    'IP' => $payloadIp !== '' ? $payloadIp : '—',
                                    'GSM szolgáltató' => $payloadGsmOperator !== '' ? $payloadGsmOperator : '—',
                                ]
                            );
                        }

                        // ── Health-check figyelő ────────────────────────────────────────
                        $configRow  = $deviceService->currentConfig($deviceId);
                        $configJson = is_string($configRow['config_json'] ?? null)
                            ? (json_decode($configRow['config_json'], true) ?? [])
                            : [];
                        $hcConfig   = is_array($configJson['health_checks'] ?? null) ? $configJson['health_checks'] : [];

                        if (!empty($hcConfig)) {
                            $battLowPct = (float) ($configJson['thresholds']['battery_low_pct'] ?? 20.0);
                            $prevRaw    = is_string($previousState['raw_json'] ?? null)
                                ? (json_decode($previousState['raw_json'], true) ?? [])
                                : [];
                            $prevConds  = evaluateHcConditions($prevRaw, $battLowPct);
                            $newConds   = evaluateHcConditions($payload, $battLowPct);

                            $hcLabels = [
                                'no_wifi'         => 'Nincs WiFi kapcsolat',
                                'no_gsm_modem'    => 'Nincs GSM modem',
                                'no_gsm_operator' => 'Nincs GSM szolgáltató',
                                'no_usb_power'    => 'Nincs USB táp (akkuról megy)',
                                'no_sensor'       => 'Szenzor nem elérhető',
                                'mqtt_offline'    => 'MQTT elérhetetlen',
                                'battery_low'     => 'Alacsony akkumulátor szint',
                            ];

                            foreach ($hcConfig as $hcKey => $hc) {
                                if (!($hc['alarm'] ?? false)) {
                                    continue;
                                }
                                $nowBad = $newConds[$hcKey] ?? false;
                                $wasBad = $prevConds[$hcKey] ?? false;
                                if ($nowBad === $wasBad) {
                                    continue;
                                }
                                $hcLabel = $hcLabels[$hcKey] ?? $hcKey;

                                if ($nowBad) {
                                    // Átmenet: OK → HIBA — csak ha nincs már nyitott alert
                                    if ($alertService->isActiveHcAlert($deviceId, $hcKey)) {
                                        continue;
                                    }
                                    $alertService->store($deviceId, [
                                        'device_id'  => $deviceId,
                                        'event_type' => 'hc_' . $hcKey,
                                        'severity'   => 'warning',
                                        'message'    => 'Health check hiba: ' . $hcLabel,
                                        'ts'         => $payload['ts'] ?? null,
                                    ]);
                                    Logger::write('worker', 'HC hiba detektálva', ['device' => $deviceId, 'key' => $hcKey]);

                                    // SMS/hívás: az ESP maga küldi firmware szinten (GSM-en,
                                    // WiFi/MQTT nélkül is működik). A szerver csak Mattermost-ot küld.
                                    if ($hc['mattermost'] ?? false) {
                                        $mattermost->notify(
                                            '⚠️ Hibaállapot: ' . $deviceName,
                                            $hcLabel,
                                            'warning',
                                            [
                                                'Eszköz'        => $deviceId,
                                                'Feltétel'      => $hcKey,
                                                'Idő (szerver)' => date('Y-m-d H:i:s'),
                                            ]
                                        );
                                    }
                                } else {
                                    // Átmenet: HIBA → OK (helyreállt)
                                    if (!$alertService->isActiveHcAlert($deviceId, $hcKey)) {
                                        continue;
                                    }
                                    $alertService->store($deviceId, [
                                        'device_id'  => $deviceId,
                                        'event_type' => 'hc_' . $hcKey . '_cleared',
                                        'severity'   => 'info',
                                        'message'    => 'Helyreállt: ' . $hcLabel,
                                        'ts'         => $payload['ts'] ?? null,
                                    ]);
                                    Logger::write('worker', 'HC helyreállt', ['device' => $deviceId, 'key' => $hcKey]);

                                    if ($hc['mattermost'] ?? false) {
                                        $mattermost->notify(
                                            '✅ Helyreállt: ' . $deviceName,
                                            $hcLabel,
                                            'good',
                                            [
                                                'Eszköz'        => $deviceId,
                                                'Feltétel'      => $hcKey,
                                                'Idő (szerver)' => date('Y-m-d H:i:s'),
                                            ]
                                        );
                                    }
                                }
                            }
                        }
                        // ── Health-check figyelő vége ───────────────────────────────────

                        return;
                    }

                    if (str_ends_with($topic, '/state/reported')) {
                        $telemetryService->updateReportedState($deviceId, $payload);
                        if ($wasOnline === 0) {
                            $normalized = $alertService->store($deviceId, [
                                'device_id' => $deviceId,
                                'event_type' => 'device_online',
                                'severity' => 'info',
                                'message' => 'Eszkoz ujra elerheto',
                                'ts' => $payload['ts'] ?? null,
                                'reason' => 'reported_state',
                            ]);
                            $mattermost->notify(
                                'Eszköz újra elérhető: ' . $deviceName,
                                'Az eszköz ismét jelentkezett és online állapotba került.',
                                'good',
                                [
                                    'Eszköz' => $deviceId,
                                    'Idő (szerver)' => (string) ($normalized['server_received_at'] ?? date('Y-m-d H:i:s')),
                                    'Ok' => 'reported state',
                                    'IP' => $payloadIp !== '' ? $payloadIp : '—',
                                    'GSM szolgáltató' => $payloadGsmOperator !== '' ? $payloadGsmOperator : '—',
                                ]
                            );
                        }
                        return;
                    }

                    if (str_ends_with($topic, '/alert')) {
                        $normalizedAlert = $alertService->store($deviceId, $payload);
                        $eventType = strtolower(trim((string) ($normalizedAlert['event_type'] ?? '')));

                        // Eszköz újrainduláskor ragadt riasztásokat automatikusan lezárjuk
                        if ($eventType === 'device_boot') {
                            $alertService->autoClearAlarmsOnBoot($deviceId);
                        }

                        // Tápváltás eseményekhez dedikált, informatív Mattermost üzenet
                        if ($eventType === 'power_loss') {
                            $mattermost->notify(
                                '⚠️ Tápellátás kiesett: ' . $deviceName,
                                'Az eszköz USB tápról akkumulátorra váltott.',
                                'warning',
                                [
                                    'Eszköz'          => $deviceId,
                                    'Idő (szerver)'   => (string) ($normalizedAlert['server_received_at'] ?? date('Y-m-d H:i:s')),
                                    'Üzenet'          => (string) ($normalizedAlert['message'] ?? '—'),
                                    'GSM szolgáltató' => $payloadGsmOperator !== '' ? $payloadGsmOperator : '—',
                                ]
                            );
                            return;
                        }
                        if ($eventType === 'power_restored') {
                            $mattermost->notify(
                                '✅ Tápellátás visszaállt: ' . $deviceName,
                                'Az eszköz ismét USB tápról működik.',
                                'good',
                                [
                                    'Eszköz'          => $deviceId,
                                    'Idő (szerver)'   => (string) ($normalizedAlert['server_received_at'] ?? date('Y-m-d H:i:s')),
                                    'Üzenet'          => (string) ($normalizedAlert['message'] ?? '—'),
                                    'GSM szolgáltató' => $payloadGsmOperator !== '' ? $payloadGsmOperator : '—',
                                ]
                            );
                            return;
                        }

                        $severity = $normalizedAlert['severity'] ?? 'info';
                        $mattermost->notify(
                            'Eszköz riasztás: ' . ($normalizedAlert['device_id'] ?? $deviceId),
                            (string) ($normalizedAlert['message'] ?? 'Új riasztás érkezett'),
                            $severity === 'critical' ? 'danger' : ($severity === 'warning' ? 'warning' : 'good'),
                            [
                                'Idő (szerver)' => (string) ($normalizedAlert['server_received_at'] ?? date('Y-m-d H:i:s')),
                                'Típus' => $normalizedAlert['event_type'] ?? 'unknown',
                                'Eszköz' => $normalizedAlert['device_id'] ?? $deviceId,
                                'Akciók' => implode(', ', (array) ($normalizedAlert['actions_taken'] ?? [])),
                                'Rule ID' => (string) ($normalizedAlert['rule_id'] ?? '—'),
                                'GSM szolgáltató' => $payloadGsmOperator !== '' ? $payloadGsmOperator : '—',
                            ]
                        );
                        return;
                    }

                    if (str_ends_with($topic, '/cmd/out')) {
                        $reply = PayloadNormalizer::normalizeCommandReply($deviceId, $payload);
                        if ($reply['request_id'] !== '') {
                            $commandService->markAcked((string) $reply['request_id'], (string) $reply['device_id'], $payload);
                            if ((bool) cfg('mattermost.notify_command_results', false)) {
                                $ok = (bool) ($reply['ok'] ?? true);
                                $mattermost->notify(
                                    'Eszköz parancsválasz: ' . $reply['device_id'],
                                    (string) ($reply['message'] ?? ($ok ? 'Parancs feldolgozva.' : 'Parancs hiba.')),
                                    $ok ? 'good' : 'danger',
                                    [
                                        'Request ID' => (string) $reply['request_id'],
                                        'Eszköz' => (string) $reply['device_id'],
                                        'OK' => $ok ? 'igen' : 'nem',
                                    ]
                                );
                            }
                        }
                        return;
                    }

                    if (str_ends_with($topic, '/lwt')) {
                        $telemetryService->markPresence($deviceId, $payload);
                        $status = strtolower((string) ($payload['status'] ?? 'unknown'));
                        if ($status === 'offline' && $wasOnline === 1) {
                            $normalized = $alertService->store($deviceId, [
                                'device_id' => $deviceId,
                                'event_type' => 'device_offline',
                                'severity' => 'warning',
                                'message' => 'Eszkoz offline (LWT)',
                                'ts' => $payload['ts'] ?? null,
                                'reason' => 'mqtt_lwt',
                            ]);
                            $mattermost->notify(
                                'Eszköz offline: ' . $deviceName,
                                'Az eszköz váratlanul lekapcsolódott az MQTT brokerrol.',
                                'warning',
                                [
                                    'Eszköz' => $deviceId,
                                    'Idő (szerver)' => (string) ($normalized['server_received_at'] ?? date('Y-m-d H:i:s')),
                                    'Ok' => 'MQTT LWT offline',
                                    'GSM szolgáltató' => $payloadGsmOperator !== '' ? $payloadGsmOperator : '—',
                                ]
                            );
                        } elseif ($status === 'online' && $wasOnline === 0) {
                            $normalized = $alertService->store($deviceId, [
                                'device_id' => $deviceId,
                                'event_type' => 'device_online',
                                'severity' => 'info',
                                'message' => 'Eszkoz ujra elerheto',
                                'ts' => $payload['ts'] ?? null,
                                'reason' => 'mqtt_lwt_online',
                            ]);
                            $mattermost->notify(
                                'Eszköz újra elérhető: ' . $deviceName,
                                'Az eszköz újra online lett az MQTT broker felől.',
                                'good',
                                [
                                    'Eszköz' => $deviceId,
                                    'Idő (szerver)' => (string) ($normalized['server_received_at'] ?? date('Y-m-d H:i:s')),
                                    'Ok' => 'MQTT LWT online',
                                    'IP' => $payloadIp !== '' ? $payloadIp : '—',
                                    'GSM szolgáltató' => $payloadGsmOperator !== '' ? $payloadGsmOperator : '—',
                                ]
                            );
                        }
                        return;
                    }
                } catch (Throwable $e) {
                    Logger::write('mqtt', 'MQTT üzenet feldolgozási hiba', [
                        'topic' => $topic,
                        'error' => $e->getMessage(),
                        'message' => $message,
                    ]);
                }
            }, (int) $qos);
        }

        $lastQueueCheck = 0.0;
        $lastHeartbeat = 0.0;

        $mqtt->registerLoopEventHandler(function (MqttClient $mqtt, float $elapsedTime) use ($commandService, $bridgeStatus, $workerName, &$lastQueueCheck, &$lastHeartbeat): void {
            $now = microtime(true);

            if (($now - $lastHeartbeat) >= 15) {
                $bridgeStatus->heartbeat($workerName, 'running', [
                    'loop_time' => round($elapsedTime, 3),
                ]);
                $lastHeartbeat = $now;
            }

            if (($now - $lastQueueCheck) < 5) {
                return;
            }

            $lastQueueCheck = $now;
            $queued = $commandService->fetchQueued(20);

            foreach ($queued as $row) {
                $topic = $row['command_type'] === 'state_desired'
                    ? sprintf('pp/%s/state/desired', $row['device_id'])
                    : sprintf('pp/%s/cmd/in', $row['device_id']);

                try {
                    $payload = $row['payload_json'];
                    $mqtt->publish($topic, $payload, 1, $row['command_type'] === 'state_desired');
                    $commandService->markSent((int) $row['id']);
                    Logger::write('mqtt', 'Kimenő MQTT publish', ['topic' => $topic, 'payload' => $payload, 'request_id' => $row['request_id']]);
                } catch (Throwable $e) {
                    $commandService->markFailed((int) $row['id'], $e->getMessage());
                    Logger::write('mqtt', 'MQTT publish hiba', ['topic' => $topic, 'error' => $e->getMessage(), 'request_id' => $row['request_id']]);
                }
            }
        });

        $mqtt->loop(true);
    } catch (Throwable $e) {
        $bridgeStatus->error($workerName, $e->getMessage(), [
            'mqtt_host' => $server,
            'mqtt_port' => $port,
        ]);
        Logger::write('worker', 'MQTT worker hiba, újracsatlakozás később', ['error' => $e->getMessage()]);
        sleep(10);
    }
}

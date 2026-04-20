<?php

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;
use RuntimeException;

class MattermostCommandProcessor
{
    private DeviceService $deviceService;
    private ConfigService $configService;
    private CommandService $commandService;
    private BridgeStatusService $bridgeStatus;
    private AuditService $audit;
    private PDO $db;

    public function __construct()
    {
        $this->deviceService = new DeviceService();
        $this->configService = new ConfigService();
        $this->commandService = new CommandService();
        $this->bridgeStatus = new BridgeStatusService();
        $this->audit = new AuditService();
        $this->db = Database::connection();
    }

    /**
     * @return array{ok:bool,text:string,command_name:string,device_id:?string,response_type:string}
     */
    public function handle(string $text, string $user, string $channelName = '', string $source = 'mattermost', string $responseType = 'ephemeral'): array
    {
        $rawText = trim($text);
        $parts = preg_split('/\s+/', $rawText) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
        $deviceId = null;

        try {
            if (!$parts) {
                $reply = "Használható: `help`, `bridge status`, `status <id>`, `cfg show <id>`, `cfg push <id>`, `cfg validate <id>`, `cmd <id> <parancs> [json]`, `queue <id>`";
                $this->log($rawText, 'help', $user, $deviceId, 'ok', $reply);
                return [
                    'ok' => true,
                    'text' => $reply,
                    'command_name' => 'help',
                    'device_id' => null,
                    'response_type' => $responseType,
                ];
            }

            $command = strtolower($parts[0]);
            $reply = '';

            switch ($command) {
                case 'help':
                    $reply = "Használható: `help`, `bridge status`, `status <id>`, `cfg show <id>`, `cfg push <id>`, `cfg validate <id>`, `cmd <id> <parancs> [json]`, `queue <id>`";
                    break;

                case 'bridge':
                    $sub = strtolower($parts[1] ?? 'status');
                    if ($sub !== 'status') {
                        throw new RuntimeException('Ismeretlen bridge alparancs. Használat: bridge status');
                    }
                    $statuses = $this->bridgeStatus->all();
                    $queued = (int) $this->db->query("SELECT COUNT(*) FROM command_queue WHERE status = 'queued'")->fetchColumn();
                    $lines = [
                        '**Bridge állapot**',
                        'MQTT host: `' . cfg('mqtt.host', '127.0.0.1') . ':' . (int) cfg('mqtt.port', 1883) . '`',
                        'Queue: `' . $queued . '`',
                        'Mattermost: `' . (cfg('mattermost.enabled', false) ? 'bekapcsolva' : 'kikapcsolva') . '`',
                        'Csatorna: `' . $channelName . '`',
                        '',
                    ];
                    foreach ($statuses as $status) {
                        $lines[] = sprintf(
                            '- `%s`: %s · heartbeat: %s%s',
                            $status['worker_name'],
                            $status['status'],
                            $status['heartbeat_at'] ?: '—',
                            !empty($status['last_error']) ? ' · hiba: ' . $status['last_error'] : ''
                        );
                    }
                    if (!$statuses) {
                        $lines[] = '- Nincs még worker heartbeat adat.';
                    }
                    $reply = implode("\n", $lines);
                    break;

                case 'status':
                    $deviceId = $parts[1] ?? '';
                    $device = $this->deviceService->find($deviceId);
                    $state = $this->deviceService->lastState($deviceId);
                    $cfg = $this->configService->getCurrent($deviceId);
                    if (!$device) {
                        throw new RuntimeException('Ismeretlen eszköz: ' . $deviceId);
                    }
                    $stateRaw = is_array($state) ? json_decode((string) ($state['raw_json'] ?? ''), true) : [];
                    if (!is_array($stateRaw)) {
                        $stateRaw = [];
                    }
                    $telemetryTransport = $this->rawPick($stateRaw, ['telemetry_transport', 'meta.telemetry_transport', 'signal.transport'], '—');
                    $wifiIp = $this->rawPick($stateRaw, ['wifi_ip', 'details.wifi_ip', 'details.ip'], '—');
                    $gsmRssi = $this->rawPick($stateRaw, ['gsm_rssi', 'signal.gsm_rssi'], '—');
                    $gsmOperator = $this->rawPick($stateRaw, ['gsm_operator', 'signal.gsm_operator', 'meta.gsm_operator', 'details.gsm_operator'], '—');
                    $reply = sprintf(
                        "**%s**
Online: %s
Utolsó kapcsolat: %s
Hőm.: %s °C
Pára: %s %%
Akku: %s %%
Táp: %s
Átvitel: %s
Wi‑Fi IP: %s
GSM RSSI: %s
GSM szolgáltató: %s
Desired cfg: %s
Reported cfg: %s",
                        $device['name'],
                        (int) ($state['online'] ?? 0) === 1 ? 'igen' : 'nem',
                        $state['last_seen_at'] ?? '—',
                        $state['temperature'] ?? '—',
                        $state['humidity'] ?? '—',
                        $state['battery_pct'] ?? '—',
                        $state['power_mode'] ?? '—',
                        $telemetryTransport,
                        $wifiIp,
                        $gsmRssi === '—' ? '—' : ((string) $gsmRssi . ' dBm'),
                        $gsmOperator,
                        $cfg['config_version'] ?? '—',
                        $state['reported_config_version'] ?? '—'
                    );
                    break;

                case 'queue':
                    $deviceId = $parts[1] ?? '';
                    $stats = $this->deviceService->queueStats($deviceId);
                    $reply = sprintf("Queue `%s` · queued: %d · sent: %d · acked: %d · failed: %d", $deviceId, $stats['queued'], $stats['sent'], $stats['acked'], $stats['failed']);
                    break;

                case 'cfg':
                    $sub = strtolower($parts[1] ?? '');
                    $deviceId = $parts[2] ?? '';
                    if ($sub === 'show') {
                        $cfgRow = $this->configService->getCurrent($deviceId);
                        if (!$cfgRow) {
                            throw new RuntimeException('Nincs konfiguráció ehhez az eszközhöz.');
                        }
                        $reply = "```json\n" . $cfgRow['config_json'] . "\n```";
                    } elseif ($sub === 'push') {
                        $requestId = $this->configService->queuePush($deviceId, $source . ':' . $user);
                        $reply = "Konfig push sorba állítva. request_id=`{$requestId}`";
                        $this->audit->log($source, $user, 'config_push_queued', $deviceId, ['request_id' => $requestId]);
                    } elseif ($sub === 'validate') {
                        $cfgRow = $this->configService->getCurrent($deviceId);
                        if (!$cfgRow) {
                            throw new RuntimeException('Nincs konfiguráció ehhez az eszközhöz.');
                        }
                        $validated = $this->configService->validate(json_decode((string) $cfgRow['config_json'], true, 512, JSON_THROW_ON_ERROR));
                        $reply = "Konfiguráció rendben. Rules: `" . count((array) ($validated['rules'] ?? [])) . "`, contact_groups: `" . count((array) ($validated['contact_groups'] ?? [])) . "`";
                    } else {
                        throw new RuntimeException('Ismeretlen cfg alparancs.');
                    }
                    break;

                case 'cmd':
                    $deviceId = $parts[1] ?? '';
                    $cmdName = $parts[2] ?? '';
                    if ($deviceId === '' || $cmdName === '') {
                        throw new RuntimeException('Használat: cmd <device_id> <parancs> [json]');
                    }
                    $extra = implode(' ', array_slice($parts, 3));
                    $payloadJson = $extra !== '' ? json_decode($extra, true, 512, JSON_THROW_ON_ERROR) : [];
                    $payloadJson = ['request_id' => '', 'cmd' => $cmdName, 'args' => $payloadJson];
                    $requestId = $this->commandService->queueCommand($deviceId, 'cmd_in', $payloadJson, $source . ':' . $user);
                    $reply = "Parancs sorba állítva: `{$cmdName}` · request_id=`{$requestId}`";
                    $this->audit->log($source, $user, 'command_queued', $deviceId, ['request_id' => $requestId, 'cmd' => $cmdName]);
                    break;

                default:
                    throw new RuntimeException('Nem ismert parancs. Írd be: help');
            }

            $this->log($rawText, $command, $user, $deviceId, 'ok', $reply);

            return [
                'ok' => true,
                'text' => $reply,
                'command_name' => $command,
                'device_id' => $deviceId,
                'response_type' => $responseType,
            ];
        } catch (Throwable $e) {
            $commandName = strtolower((string) ($parts[0] ?? 'unknown'));
            $reply = 'Hiba: ' . $e->getMessage();
            $this->log($rawText, $commandName, $user, $deviceId, 'error', $reply);

            return [
                'ok' => false,
                'text' => $reply,
                'command_name' => $commandName,
                'device_id' => $deviceId,
                'response_type' => $responseType,
            ];
        }
    }


    private function rawPick(array $raw, array $paths, mixed $default = null): mixed
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

    private function log(string $commandText, string $commandName, string $actor, ?string $deviceId, string $status, string $responseText): void
    {
        $stmt = $this->db->prepare("INSERT INTO mattermost_command_log (command_text, command_name, actor, device_id, status, response_text, created_at) VALUES (:command_text, :command_name, :actor, :device_id, :status, :response_text, NOW())");
        $stmt->execute([
            'command_text' => $commandText,
            'command_name' => $commandName,
            'actor' => $actor,
            'device_id' => $deviceId,
            'status' => $status,
            'response_text' => $responseText,
        ]);
    }
}

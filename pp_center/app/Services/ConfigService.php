<?php

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

class ConfigService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function save(string $deviceId, array $payload, string $actor = 'web'): int
    {
        $payload = $this->normalizePayload($payload);
        $current = $this->getCurrent($deviceId);
        $version = ((int) ($current['config_version'] ?? 0)) + 1;
        $payload['device_id'] = $deviceId;
        $payload['config_version'] = $version;

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('A konfiguráció JSON formátumba alakítása sikertelen.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO device_config (
                device_id, config_version, sampling_sec, heartbeat_sec,
                temp_min, temp_max, humidity_min, humidity_max, airq_max, battery_low_pct,
                contact_1_mode, contact_2_mode, contact_3_mode, contact_4_mode,
                config_json, updated_by, updated_at
            ) VALUES (
                :device_id, :config_version, :sampling_sec, :heartbeat_sec,
                :temp_min, :temp_max, :humidity_min, :humidity_max, :airq_max, :battery_low_pct,
                :contact_1_mode, :contact_2_mode, :contact_3_mode, :contact_4_mode,
                :config_json, :updated_by, NOW()
            )"
        );

        $stmt->execute([
            'device_id' => $deviceId,
            'config_version' => $version,
            'sampling_sec' => (int) ($payload['sampling_sec'] ?? 180),
            'heartbeat_sec' => (int) ($payload['heartbeat_sec'] ?? 180),
            'temp_min' => $payload['thresholds']['temp_min'] ?? null,
            'temp_max' => $payload['thresholds']['temp_max'] ?? null,
            'humidity_min' => $payload['thresholds']['humidity_min'] ?? null,
            'humidity_max' => $payload['thresholds']['humidity_max'] ?? null,
            'airq_max' => $payload['thresholds']['airq_max'] ?? null,
            'battery_low_pct' => $payload['thresholds']['battery_low_pct'] ?? null,
            'contact_1_mode' => $payload['contacts']['c1_mode'] ?? 'nc',
            'contact_2_mode' => $payload['contacts']['c2_mode'] ?? 'nc',
            'contact_3_mode' => $payload['contacts']['c3_mode'] ?? 'nc',
            'contact_4_mode' => $payload['contacts']['c4_mode'] ?? 'nc',
            'config_json' => $json,
            'updated_by' => $actor,
        ]);

        $history = $this->db->prepare("INSERT INTO device_config_history (device_id, config_version, config_json, changed_by, changed_at) VALUES (:device_id, :config_version, :config_json, :changed_by, NOW())");
        $history->execute([
            'device_id' => $deviceId,
            'config_version' => $version,
            'config_json' => $json,
            'changed_by' => $actor,
        ]);

        return $version;
    }

    public function getCurrent(string $deviceId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM device_config WHERE device_id = :device_id ORDER BY id DESC LIMIT 1");
        $stmt->execute(['device_id' => $deviceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function buildDefaultConfig(): array
    {
        return $this->normalizePayload((array) cfg('devices.default_config', []));
    }

    public function validate(array $payload): array
    {
        return $this->normalizePayload($payload);
    }

    public function queuePush(string $deviceId, string $actor = 'web'): string
    {
        $current = $this->getCurrent($deviceId);
        if (!$current) {
            throw new RuntimeException('Nincs még konfiguráció ehhez az eszközhöz.');
        }

        $requestId = bin2hex(random_bytes(8));
        $stmt = $this->db->prepare("INSERT INTO command_queue (device_id, request_id, command_type, payload_json, status, created_by, created_at) VALUES (:device_id, :request_id, 'state_desired', :payload_json, 'queued', :created_by, NOW())");
        $stmt->execute([
            'device_id' => $deviceId,
            'request_id' => $requestId,
            'payload_json' => $current['config_json'],
            'created_by' => $actor,
        ]);

        return $requestId;
    }

    private function normalizePayload(array $payload): array
    {
        $defaults = (array) cfg('devices.default_config', []);
        $merged = array_replace_recursive($defaults, $payload);

        $merged['sampling_sec'] = max(30, (int) ($merged['sampling_sec'] ?? 180));
        $merged['heartbeat_sec'] = max(30, (int) ($merged['heartbeat_sec'] ?? 180));

        foreach (['temp_min','temp_max','humidity_min','humidity_max','airq_max','battery_low_pct'] as $key) {
            if (isset($merged['thresholds'][$key]) && $merged['thresholds'][$key] !== '') {
                $merged['thresholds'][$key] = (float) $merged['thresholds'][$key];
            } else {
                $merged['thresholds'][$key] = null;
            }
        }

        foreach (['c1_mode','c2_mode','c3_mode','c4_mode'] as $key) {
            $mode = strtolower(trim((string) ($merged['contacts'][$key] ?? 'nc')));
            if (!in_array($mode, ['nc', 'no', 'unused'], true)) {
                $mode = 'nc';
            }
            $merged['contacts'][$key] = $mode;
        }

        $merged['rules'] = array_values(array_map(function ($rule, $index) {
            if (!is_array($rule)) {
                throw new RuntimeException('A rules tömb minden eleme objektum legyen.');
            }
            $rule['rule_id'] = trim((string) ($rule['rule_id'] ?? ('rule_' . ($index + 1))));
            if ($rule['rule_id'] === '') {
                throw new RuntimeException('Üres rule_id nem megengedett.');
            }
            $rule['type'] = trim((string) ($rule['type'] ?? 'threshold'));
            $rule['sensor'] = trim((string) ($rule['sensor'] ?? 'temperature'));
            $rule['actions'] = array_values(array_map('strval', (array) ($rule['actions'] ?? [])));
            return $rule;
        }, (array) ($merged['rules'] ?? []), array_keys((array) ($merged['rules'] ?? []))));

        $groups = [];
        foreach ((array) ($merged['contact_groups'] ?? []) as $group => $phones) {
            $group = trim((string) $group);
            if ($group === '') {
                continue;
            }
            $groups[$group] = array_values(array_map('strval', array_filter((array) $phones, fn($v) => trim((string) $v) !== '')));
        }
        $merged['contact_groups'] = $groups;

        $routes = [];
        foreach ((array) ($merged['routes'] ?? []) as $event => $actions) {
            $event = trim((string) $event);
            if ($event === '') {
                continue;
            }
            $routes[$event] = array_values(array_map('strval', (array) $actions));
        }
        $merged['routes'] = $routes;

        // Health checks – per-check: alarm bool + mattermost bool + sms_target + call_target
        $knownHcKeys = ['no_wifi', 'no_gsm_modem', 'no_gsm_operator', 'no_usb_power', 'no_sensor', 'mqtt_offline', 'battery_low'];
        $hcAlarmDef  = ['no_wifi' => true, 'no_gsm_modem' => false, 'no_gsm_operator' => false,
                        'no_usb_power' => false, 'no_sensor' => true, 'mqtt_offline' => true, 'battery_low' => true];
        $hcRaw = (array) ($merged['health_checks'] ?? []);
        $hcNorm = [];
        foreach ($knownHcKeys as $key) {
            $row = is_array($hcRaw[$key] ?? null) ? $hcRaw[$key] : [];
            // Visszafelé-kompatibilitás: régi bool formátum
            $oldBool = is_bool($hcRaw[$key] ?? null) ? $hcRaw[$key] : null;
            $hcNorm[$key] = [
                'alarm'       => $oldBool ?? (bool) ($row['alarm'] ?? $hcAlarmDef[$key]),
                'mattermost'  => (bool) ($row['mattermost'] ?? false),
                'sms_target'  => trim((string) ($row['sms_target'] ?? '')),
                'call_target' => trim((string) ($row['call_target'] ?? '')),
            ];
        }
        $merged['health_checks'] = $hcNorm;

        // ESP-NOW relay konfig normalizálás
        $espnowRaw = is_array($merged['espnow'] ?? null) ? $merged['espnow'] : [];
        $espnowSlaves = array_values(array_filter(
            array_map('trim', (array) ($espnowRaw['slaves'] ?? [])),
            static fn ($v) => $v !== ''
        ));
        $merged['espnow'] = [
            'enabled' => (bool) ($espnowRaw['enabled'] ?? false),
            'slaves'  => $espnowSlaves,
        ];

        return $merged;
    }
}

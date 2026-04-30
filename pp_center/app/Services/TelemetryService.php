<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class TelemetryService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function latest(int $limit = 100, ?string $deviceId = null): array
    {
        return $this->searchPage(1, $limit, [
            'device_id' => $deviceId,
        ]);
    }

    public function latestPage(int $page = 1, int $perPage = 20, ?string $deviceId = null): array
    {
        return $this->searchPage($page, $perPage, [
            'device_id' => $deviceId,
        ]);
    }

    public function count(?string $deviceId = null): int
    {
        return $this->searchCount([
            'device_id' => $deviceId,
        ]);
    }

    public function searchPage(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        [$whereSql, $params] = $this->buildSearchWhere($filters);
        $sql = "SELECT * FROM telemetry_log {$whereSql} ORDER BY ts DESC, id DESC LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function searchCount(array $filters = []): int
    {
        [$whereSql, $params] = $this->buildSearchWhere($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM telemetry_log {$whereSql}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function buildSearchWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        $deviceId = trim((string) ($filters['device_id'] ?? ''));
        if ($deviceId !== '') {
            $conditions[] = 'device_id = :device_id';
            $params['device_id'] = $deviceId;
        }

        $fromTs = trim((string) ($filters['from_ts'] ?? ''));
        if ($fromTs !== '') {
            $conditions[] = 'ts >= :from_ts';
            $params['from_ts'] = $fromTs;
        }

        $toTs = trim((string) ($filters['to_ts'] ?? ''));
        if ($toTs !== '') {
            $conditions[] = 'ts <= :to_ts';
            $params['to_ts'] = $toTs;
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$whereSql, $params];
    }

    public function store(string $deviceId, array $payload): void
    {
        $normalized = PayloadNormalizer::normalizeTelemetry($deviceId, $payload);
        $deviceTs = $this->normalizeTs($normalized['ts'] ?? null);
        $serverNow = date('Y-m-d H:i:s');
        $rawPayload = $this->decorateRawPayload($normalized['raw'], $deviceTs, $serverNow);

        $stmt = $this->db->prepare("INSERT INTO telemetry_log (device_id, ts, temperature, humidity, pressure_hpa, air_quality, battery_pct, power_mode, contact_1, contact_2, contact_3, contact_4, rssi, raw_json) VALUES (:device_id, :ts, :temperature, :humidity, :pressure_hpa, :air_quality, :battery_pct, :power_mode, :contact_1, :contact_2, :contact_3, :contact_4, :rssi, :raw_json)");
        $stmt->execute([
            'device_id' => $normalized['device_id'],
            'ts' => $serverNow,
            'temperature' => $normalized['temperature'],
            'humidity' => $normalized['humidity'],
            'pressure_hpa' => $normalized['pressure_hpa'] ?? null,
            'air_quality' => $normalized['air_quality'],
            'battery_pct' => $normalized['battery_pct'],
            'power_mode' => $normalized['power_mode'],
            'contact_1' => $normalized['contacts']['c1'] ?? null,
            'contact_2' => $normalized['contacts']['c2'] ?? null,
            'contact_3' => $normalized['contacts']['c3'] ?? null,
            'contact_4' => $normalized['contacts']['c4'] ?? null,
            'rssi' => $normalized['rssi'],
            'raw_json' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $normalized['raw'] = $rawPayload;
        $this->upsertLastState($normalized['device_id'], $normalized, 1, $serverNow);
    }

    public function updateReportedState(string $deviceId, array $payload): void
    {
        $normalized = PayloadNormalizer::normalizeReportedState($deviceId, $payload);
        $deviceTs = $this->normalizeTs($normalized['ts'] ?? null);
        $serverNow = date('Y-m-d H:i:s');
        $normalized['raw'] = $this->decorateRawPayload($normalized['raw'], $deviceTs, $serverNow);
        $this->upsertLastState($normalized['device_id'], $normalized, 1, $serverNow);
    }

    public function markPresence(string $deviceId, array $payload): void
    {
        $normalized = PayloadNormalizer::normalizePresence($deviceId, $payload);
        $status = $normalized['status'];
        $online = $status === 'online' ? 1 : 0;
        $deviceTs = $this->normalizeTs($normalized['ts'] ?? null);
        $serverNow = date('Y-m-d H:i:s');
        $rawPayload = $this->decorateRawPayload($normalized['raw'], $deviceTs, $serverNow);

        $stmt = $this->db->prepare("INSERT INTO device_presence_log (device_id, status, payload_json, happened_at) VALUES (:device_id, :status, :payload_json, NOW())");
        $stmt->execute([
            'device_id' => $normalized['device_id'],
            'status' => $status,
            'payload_json' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $upsert = $this->db->prepare("INSERT INTO device_last_state (device_id, last_seen_at, online, raw_json, updated_at)
        VALUES (:device_id, :last_seen_at, :online, :raw_json, NOW())
        ON DUPLICATE KEY UPDATE
            last_seen_at = VALUES(last_seen_at), online = VALUES(online), raw_json = VALUES(raw_json), updated_at = NOW()");
        $raw = json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $upsert->execute([
            'device_id' => $normalized['device_id'],
            'last_seen_at' => $serverNow,
            'online' => $online,
            'raw_json' => $raw,
        ]);
    }

    private function upsertLastState(string $deviceId, array $payload, int $online = 1, ?string $serverNow = null): void
    {
        $rawJson = json_encode($payload['raw'] ?? $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lastSeen = $serverNow ?? date('Y-m-d H:i:s');

        $upsert = $this->db->prepare("INSERT INTO device_last_state (device_id, last_seen_at, online, power_mode, battery_pct, temperature, humidity, pressure_hpa, air_quality, contact_1, contact_2, contact_3, contact_4, rssi, reported_config_version, raw_json, updated_at)
        VALUES (:device_id, :last_seen_at, :online, :power_mode, :battery_pct, :temperature, :humidity, :pressure_hpa, :air_quality, :contact_1, :contact_2, :contact_3, :contact_4, :rssi, :reported_config_version, :raw_json, NOW())
        ON DUPLICATE KEY UPDATE
            last_seen_at = VALUES(last_seen_at), online = VALUES(online), power_mode = COALESCE(VALUES(power_mode), power_mode), battery_pct = COALESCE(VALUES(battery_pct), battery_pct),
            temperature = COALESCE(VALUES(temperature), temperature), humidity = COALESCE(VALUES(humidity), humidity), pressure_hpa = COALESCE(VALUES(pressure_hpa), pressure_hpa), air_quality = COALESCE(VALUES(air_quality), air_quality),
            contact_1 = COALESCE(VALUES(contact_1), contact_1), contact_2 = COALESCE(VALUES(contact_2), contact_2), contact_3 = COALESCE(VALUES(contact_3), contact_3), contact_4 = COALESCE(VALUES(contact_4), contact_4),
            rssi = COALESCE(VALUES(rssi), rssi), reported_config_version = COALESCE(VALUES(reported_config_version), reported_config_version), raw_json = VALUES(raw_json), updated_at = NOW()"
        );
        $upsert->execute([
            'device_id' => $deviceId,
            'last_seen_at' => $lastSeen,
            'online' => $online,
            'power_mode' => $payload['power_mode'] ?? null,
            'battery_pct' => $payload['battery_pct'] ?? null,
            'temperature' => $payload['temperature'] ?? null,
            'humidity' => $payload['humidity'] ?? null,
            'pressure_hpa' => $payload['pressure_hpa'] ?? null,
            'air_quality' => $payload['air_quality'] ?? null,
            'contact_1' => $payload['contacts']['c1'] ?? null,
            'contact_2' => $payload['contacts']['c2'] ?? null,
            'contact_3' => $payload['contacts']['c3'] ?? null,
            'contact_4' => $payload['contacts']['c4'] ?? null,
            'rssi' => $payload['rssi'] ?? null,
            'reported_config_version' => $payload['config_version'] ?? $payload['reported_config_version'] ?? null,
            'raw_json' => $rawJson,
        ]);
    }



    public function historySeries(string $deviceId, \DateTimeImmutable $from, \DateTimeImmutable $to, int $targetPoints = 720): array
    {
        if ($to <= $from) {
            $to = $from->modify('+1 hour');
        }

        $stmt = $this->db->prepare("SELECT ts, temperature, humidity, pressure_hpa, contact_1, contact_2, contact_3, contact_4, rssi, raw_json FROM telemetry_log WHERE device_id = :device_id AND ts BETWEEN :from_ts AND :to_ts ORDER BY ts ASC");
        $stmt->execute([
            'device_id' => $deviceId,
            'from_ts' => $from->format('Y-m-d H:i:s'),
            'to_ts' => $to->format('Y-m-d H:i:s'),
        ]);
        $rows = $stmt->fetchAll();

        $fromEpoch = $from->getTimestamp();
        $toEpoch = $to->getTimestamp();
        $rangeSeconds = max(1, $toEpoch - $fromEpoch);
        $bucketSeconds = max(1, (int) ceil($rangeSeconds / max(10, $targetPoints)));
        $buckets = [];

        foreach ($rows as $row) {
            $tsEpoch = strtotime((string) ($row['ts'] ?? ''));
            if ($tsEpoch === false) {
                continue;
            }

            $bucketIndex = (int) floor(max(0, $tsEpoch - $fromEpoch) / $bucketSeconds);
            if (!isset($buckets[$bucketIndex])) {
                $buckets[$bucketIndex] = [
                    'epoch' => $tsEpoch,
                    'ts' => (string) ($row['ts'] ?? ''),
                    'temperature_sum' => 0.0,
                    'temperature_count' => 0,
                    'humidity_sum' => 0.0,
                    'humidity_count' => 0,
                    'pressure_sum' => 0.0,
                    'pressure_count' => 0,
                    'wifi_sum' => 0.0,
                    'wifi_count' => 0,
                    'gsm_sum' => 0.0,
                    'gsm_count' => 0,
                    'contacts' => ['c1' => null, 'c2' => null, 'c3' => null, 'c4' => null],
                ];
            }

            $bucket = &$buckets[$bucketIndex];
            $bucket['epoch'] = $tsEpoch;
            $bucket['ts'] = (string) ($row['ts'] ?? '');

            if (is_numeric($row['temperature'] ?? null)) {
                $bucket['temperature_sum'] += (float) $row['temperature'];
                $bucket['temperature_count']++;
            }
            if (is_numeric($row['humidity'] ?? null)) {
                $bucket['humidity_sum'] += (float) $row['humidity'];
                $bucket['humidity_count']++;
            }
            if (is_numeric($row['pressure_hpa'] ?? null)) {
                $bucket['pressure_sum'] += (float) $row['pressure_hpa'];
                $bucket['pressure_count']++;
            }

            $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
            if (!is_array($raw)) {
                $raw = [];
            }

            $wifiRssi = $this->firstNumericValue([
                $this->rawValue($raw, ['wifi_rssi', 'signal.wifi_rssi', 'signal.rssi']),
                $row['rssi'] ?? null,
            ]);
            if ($wifiRssi !== null) {
                $bucket['wifi_sum'] += $wifiRssi;
                $bucket['wifi_count']++;
            }

            $gsmRssi = $this->firstNumericValue([
                $this->rawValue($raw, ['gsm_rssi', 'signal.gsm_rssi']),
            ]);
            if ($gsmRssi !== null) {
                $bucket['gsm_sum'] += $gsmRssi;
                $bucket['gsm_count']++;
            }

            foreach (['c1' => 'contact_1', 'c2' => 'contact_2', 'c3' => 'contact_3', 'c4' => 'contact_4'] as $key => $column) {
                $bucket['contacts'][$key] = $this->normalizeContactSeriesValue($row[$column] ?? null, $bucket['contacts'][$key]);
            }
            unset($bucket);
        }

        ksort($buckets);
        $points = [];
        foreach ($buckets as $bucket) {
            $points[] = [
                'epoch' => (int) $bucket['epoch'],
                'ts' => (string) $bucket['ts'],
                'temperature' => $bucket['temperature_count'] > 0 ? round($bucket['temperature_sum'] / $bucket['temperature_count'], 2) : null,
                'humidity' => $bucket['humidity_count'] > 0 ? round($bucket['humidity_sum'] / $bucket['humidity_count'], 2) : null,
                'pressure_hpa' => $bucket['pressure_count'] > 0 ? round($bucket['pressure_sum'] / $bucket['pressure_count'], 1) : null,
                'wifi_rssi' => $bucket['wifi_count'] > 0 ? (int) round($bucket['wifi_sum'] / $bucket['wifi_count']) : null,
                'gsm_rssi' => $bucket['gsm_count'] > 0 ? (int) round($bucket['gsm_sum'] / $bucket['gsm_count']) : null,
                'contacts' => $bucket['contacts'],
            ];
        }

        return [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'point_count' => count($points),
            'row_count' => count($rows),
            'bucket_seconds' => $bucketSeconds,
            'points' => $points,
        ];
    }

    private function decorateRawPayload(array $raw, ?string $deviceTs, string $serverNow): array
    {
        $raw['_server_received_at'] = $serverNow;
        if ($deviceTs !== null) {
            $raw['_device_ts_normalized'] = $deviceTs;
        }
        return $raw;
    }


    private function rawValue(array $raw, array $paths, mixed $default = null): mixed
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

    private function firstNumericValue(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }
        return null;
    }

    private function normalizeContactSeriesValue(mixed $value, mixed $default = null): ?int
    {
        if ($value === null || $value === '') {
            return is_numeric($default) ? (int) $default : null;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return ((int) $value) === 1 ? 1 : 0;
        }

        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            '1', 'true', 'high', 'open', 'opened', 'nyitott', 'on', 'active', 'aktív' => 1,
            '0', 'false', 'low', 'closed', 'zart', 'zárt', 'off', 'inactive', 'inaktív' => 0,
            default => is_numeric($default) ? (int) $default : null,
        };
    }

    private function normalizeTs(mixed $ts): string
    {
        if (is_numeric($ts)) {
            $ts = (string) $ts;
            if (strlen($ts) >= 13) {
                return date('Y-m-d H:i:s', (int) floor(((int) $ts) / 1000));
            }
            return date('Y-m-d H:i:s', (int) $ts);
        }

        if (is_string($ts) && trim($ts) !== '') {
            try {
                return (new \DateTimeImmutable($ts))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
            }
        }

        return date('Y-m-d H:i:s');
    }
}

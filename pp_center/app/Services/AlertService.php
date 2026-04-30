<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class AlertService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function recent(int $limit = 50, ?string $deviceId = null): array
    {
        return $this->searchPage(1, $limit, [
            'device_id' => $deviceId,
        ]);
    }

    public function recentPage(int $page = 1, int $perPage = 20, ?string $deviceId = null): array
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
        $sql = "SELECT * FROM alerts {$whereSql} ORDER BY ts DESC, id DESC LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function searchCount(array $filters = []): int
    {
        [$whereSql, $params] = $this->buildSearchWhere($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM alerts {$whereSql}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function eventTypes(): array
    {
        $rows = $this->db->query("SELECT DISTINCT event_type FROM alerts WHERE event_type IS NOT NULL AND event_type <> '' ORDER BY event_type ASC")->fetchAll();
        return array_values(array_map(static fn(array $row): string => (string) $row['event_type'], $rows));
    }

    public function countDevicesWithActiveAlerts(): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM (
                SELECT latest.device_id
                FROM (
                    SELECT a.device_id,
                           CASE
                               WHEN a.event_type IN ('temp_high', 'temp_high_cleared') THEN 'temp_high'
                               WHEN a.event_type IN ('temp_low', 'temp_low_cleared') THEN 'temp_low'
                               WHEN a.event_type IN ('contact_active', 'contact_cleared') THEN 'contact'
                               WHEN a.event_type IN ('device_offline', 'device_online') THEN 'device_offline'
                               ELSE NULL
                           END AS alert_family,
                           a.event_type
                    FROM alerts a
                    INNER JOIN (
                        SELECT device_id,
                               CASE
                                   WHEN event_type IN ('temp_high', 'temp_high_cleared') THEN 'temp_high'
                                   WHEN event_type IN ('temp_low', 'temp_low_cleared') THEN 'temp_low'
                                   WHEN event_type IN ('contact_active', 'contact_cleared') THEN 'contact'
                                   WHEN event_type IN ('device_offline', 'device_online') THEN 'device_offline'
                                   ELSE NULL
                               END AS alert_family,
                               MAX(id) AS max_id
                        FROM alerts
                        WHERE event_type IN ('temp_high', 'temp_high_cleared', 'temp_low', 'temp_low_cleared', 'contact_active', 'contact_cleared', 'device_offline', 'device_online')
                        GROUP BY device_id, alert_family
                    ) latest_ids ON latest_ids.max_id = a.id
                    WHERE a.event_type IN ('temp_high', 'temp_low', 'contact_active', 'device_offline')
                ) latest
                GROUP BY latest.device_id
            ) active_devices
        ";

        return (int) $this->db->query($sql)->fetchColumn();
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

        $eventType = trim((string) ($filters['event_type'] ?? ''));
        if ($eventType !== '') {
            $conditions[] = 'event_type = :event_type';
            $params['event_type'] = $eventType;
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

    public function store(string $deviceId, array $payload): array
    {
        $normalized = PayloadNormalizer::normalizeAlert($deviceId, $payload);
        $deviceTs = $this->normalizeTs($normalized['ts'] ?? null);
        $serverNow = date('Y-m-d H:i:s');
        $normalized['server_received_at'] = $serverNow;
        if ($deviceTs !== null) {
            $normalized['device_ts_normalized'] = $deviceTs;
        }
        $rawPayload = $this->decorateRawPayload($normalized['raw'], $deviceTs, $serverNow);
        $normalized['raw'] = $rawPayload;

        $stmt = $this->db->prepare("INSERT INTO alerts (device_id, ts, event_type, severity, message, actions_taken_json, raw_json) VALUES (:device_id, :ts, :event_type, :severity, :message, :actions_taken_json, :raw_json)");
        $stmt->execute([
            'device_id' => $normalized['device_id'],
            'ts' => $serverNow,
            'event_type' => $normalized['event_type'],
            'severity' => $normalized['severity'],
            'message' => $normalized['message'],
            'actions_taken_json' => json_encode($normalized['actions_taken'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw_json' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return $normalized;
    }


    /**
     * Eszköz újrainduláskor (device_boot) lezárja a ragadt riasztásokat.
     * Ha az eszköz elvesztette állapotát és nem küldött _cleared eventet, ez pótolja.
     */
    public function autoClearAlarmsOnBoot(string $deviceId): void
    {
        $sql = "
            SELECT a.event_type
            FROM alerts a
            INNER JOIN (
                SELECT device_id,
                       CASE
                           WHEN event_type IN ('temp_high', 'temp_high_cleared') THEN 'temp_high'
                           WHEN event_type IN ('temp_low', 'temp_low_cleared') THEN 'temp_low'
                           WHEN event_type IN ('contact_active', 'contact_cleared') THEN 'contact'
                       END AS alert_family,
                       MAX(id) AS max_id
                FROM alerts
                WHERE device_id = :device_id
                  AND event_type IN ('temp_high', 'temp_high_cleared', 'temp_low', 'temp_low_cleared', 'contact_active', 'contact_cleared')
                GROUP BY device_id, alert_family
            ) latest_ids ON latest_ids.max_id = a.id
            WHERE a.device_id = :device_id2
              AND a.event_type IN ('temp_high', 'temp_low', 'contact_active')
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['device_id' => $deviceId, 'device_id2' => $deviceId]);
        $activeEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $clearMap = [
            'temp_high'      => ['event_type' => 'temp_high_cleared', 'message' => 'Auto-lezaras: eszkoz ujrainditasa utan ragadt riasztas'],
            'temp_low'       => ['event_type' => 'temp_low_cleared',  'message' => 'Auto-lezaras: eszkoz ujrainditasa utan ragadt riasztas'],
            'contact_active' => ['event_type' => 'contact_cleared',   'message' => 'Auto-lezaras: eszkoz ujrainditasa utan ragadt riasztas'],
        ];

        foreach ($activeEvents as $row) {
            $eventType = (string) $row['event_type'];
            if (!isset($clearMap[$eventType])) {
                continue;
            }
            $clear = $clearMap[$eventType];
            $ins = $this->db->prepare("
                INSERT INTO alerts (device_id, ts, event_type, severity, message, actions_taken_json, raw_json)
                VALUES (:device_id, NOW(), :event_type, 'info', :message, NULL, NULL)
            ");
            $ins->execute([
                'device_id'  => $deviceId,
                'event_type' => $clear['event_type'],
                'message'    => $clear['message'],
            ]);
        }
    }

    /**
     * Megvizsgálja, hogy az adott health-check feltétel jelenleg aktív hibában van-e.
     * Akkor aktív, ha a legutolsó hc_<key> vagy hc_<key>_cleared alert típusa hc_<key>.
     */
    public function isActiveHcAlert(string $deviceId, string $hcKey): bool
    {
        $fault   = 'hc_' . $hcKey;
        $cleared = 'hc_' . $hcKey . '_cleared';
        $stmt = $this->db->prepare(
            "SELECT event_type FROM alerts WHERE device_id = :device_id AND event_type IN (:fault, :cleared) ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['device_id' => $deviceId, 'fault' => $fault, 'cleared' => $cleared]);
        $row = $stmt->fetch();
        return $row !== false && $row['event_type'] === $fault;
    }

    public function timelineData(string $deviceId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($to <= $from) {
            $to = $from->modify('+1 hour');
        }

        $fromTs = $from->format('Y-m-d H:i:s');
        $toTs = $to->format('Y-m-d H:i:s');
        $fromEpoch = $from->getTimestamp();
        $toEpoch = $to->getTimestamp();

        $trackedTypes = [
            'temp_high', 'temp_high_cleared',
            'temp_low', 'temp_low_cleared',
            'contact_active', 'contact_cleared',
            'device_offline', 'device_online',
        ];
        $placeholders = implode(',', array_fill(0, count($trackedTypes), '?'));

        $stmt = $this->db->prepare("SELECT * FROM alerts WHERE device_id = ? AND event_type IN ({$placeholders}) AND ts <= ? ORDER BY ts ASC, id ASC");
        $params = array_merge([$deviceId], $trackedTypes, [$toTs]);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $openers = [
            'temp_high' => 'temp_high',
            'temp_low' => 'temp_low',
            'contact_active' => 'contact',
            'device_offline' => 'device_offline',
        ];
        $closers = [
            'temp_high_cleared' => 'temp_high',
            'temp_low_cleared' => 'temp_low',
            'contact_cleared' => 'contact',
            'device_online' => 'device_offline',
        ];

        $openByKey = [];
        $laneMap = [];
        $intervals = [];

        foreach ($rows as $row) {
            $eventType = (string) ($row['event_type'] ?? '');
            $rowEpoch = strtotime((string) ($row['ts'] ?? ''));
            if ($rowEpoch === false) {
                continue;
            }

            [$stateKey, $lane] = $this->resolveTimelineState($row);
            $laneMap[$lane['key']] = $lane;

            if (isset($openers[$eventType])) {
                if (!isset($openByKey[$stateKey])) {
                    $openByKey[$stateKey] = [
                        'row' => $row,
                        'epoch' => $rowEpoch,
                        'lane' => $lane,
                        'family' => $openers[$eventType],
                    ];
                }
                continue;
            }

            if (isset($closers[$eventType], $openByKey[$stateKey]) && $openByKey[$stateKey]['family'] === $closers[$eventType]) {
                $start = $openByKey[$stateKey];
                unset($openByKey[$stateKey]);
                $interval = $this->buildTimelineInterval($start['row'], $row, $start['lane'], $fromEpoch, $toEpoch);
                if ($interval !== null) {
                    $intervals[] = $interval;
                }
            }
        }

        foreach ($openByKey as $start) {
            $interval = $this->buildTimelineInterval($start['row'], null, $start['lane'], $fromEpoch, $toEpoch);
            if ($interval !== null) {
                $intervals[] = $interval;
            }
        }

        usort($intervals, static function (array $a, array $b): int {
            if ($a['display_start_epoch'] === $b['display_start_epoch']) {
                return strcmp((string) $a['lane_key'], (string) $b['lane_key']);
            }
            return $a['display_start_epoch'] <=> $b['display_start_epoch'];
        });

        $lanes = array_values($laneMap);
        usort($lanes, static function (array $a, array $b): int {
            if (($a['sort_order'] ?? 0) === ($b['sort_order'] ?? 0)) {
                return strcmp((string) $a['label'], (string) $b['label']);
            }
            return ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
        });

        $eventsStmt = $this->db->prepare("SELECT * FROM alerts WHERE device_id = :device_id AND ts BETWEEN :from_ts AND :to_ts ORDER BY ts DESC, id DESC LIMIT 500");
        $eventsStmt->execute([
            'device_id' => $deviceId,
            'from_ts' => $fromTs,
            'to_ts' => $toTs,
        ]);
        $events = $eventsStmt->fetchAll();

        return [
            'from' => $fromTs,
            'to' => $toTs,
            'from_epoch' => $fromEpoch,
            'to_epoch' => $toEpoch,
            'interval_count' => count($intervals),
            'event_count' => count($events),
            'lanes' => $lanes,
            'intervals' => $intervals,
            'events' => $events,
        ];
    }

    private function resolveTimelineState(array $row): array
    {
        $eventType = (string) ($row['event_type'] ?? '');

        return match ($eventType) {
            'temp_high', 'temp_high_cleared' => [
                'temp_high',
                ['key' => 'temp_high', 'label' => 'Magas hőmérséklet', 'style' => 'temp-high', 'family' => 'temp', 'sort_order' => 10],
            ],
            'temp_low', 'temp_low_cleared' => [
                'temp_low',
                ['key' => 'temp_low', 'label' => 'Alacsony hőmérséklet', 'style' => 'temp-low', 'family' => 'temp', 'sort_order' => 20],
            ],
            'device_offline', 'device_online' => [
                'device_offline',
                ['key' => 'device_offline', 'label' => 'MQTT / eszköz offline', 'style' => 'offline', 'family' => 'presence', 'sort_order' => 30],
            ],
            'contact_active', 'contact_cleared' => $this->resolveContactTimelineState($row),
            default => [
                $eventType,
                ['key' => $eventType, 'label' => $eventType, 'style' => 'generic', 'family' => 'generic', 'sort_order' => 90],
            ],
        };
    }

    private function resolveContactTimelineState(array $row): array
    {
        $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
        if (!is_array($raw)) {
            $raw = [];
        }

        $label = $this->firstString([
            $this->rawValue($raw, ['details.contact_label', 'details.contact_name', 'details.contact', 'details.name']),
        ]);

        $gpio = $this->firstString([
            $this->rawValue($raw, ['details.gpio', 'details.pin']),
            $this->messageGpio((string) ($row['message'] ?? '')),
        ]);

        if ($label === null && $gpio !== null) {
            $label = 'Kontakt GPIO' . preg_replace('/\D+/', '', $gpio);
        }
        if ($label === null) {
            $label = 'Kontakt riasztás';
        }

        $normalizedKey = 'contact:' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
        return [
            $normalizedKey,
            ['key' => $normalizedKey, 'label' => $label, 'style' => 'contact', 'family' => 'contact', 'sort_order' => 40],
        ];
    }

    private function buildTimelineInterval(array $startRow, ?array $endRow, array $lane, int $fromEpoch, int $toEpoch): ?array
    {
        $startEpoch = strtotime((string) ($startRow['ts'] ?? ''));
        if ($startEpoch === false) {
            return null;
        }
        $endEpoch = $endRow ? strtotime((string) ($endRow['ts'] ?? '')) : null;
        if ($endEpoch === false) {
            $endEpoch = null;
        }

        $visibleEnd = $endEpoch ?? $toEpoch;
        if ($visibleEnd < $fromEpoch || $startEpoch > $toEpoch) {
            return null;
        }

        $displayStart = max($startEpoch, $fromEpoch);
        $displayEnd = min($visibleEnd, $toEpoch);
        if ($displayEnd < $displayStart) {
            $displayEnd = $displayStart;
        }

        $severity = (string) ($startRow['severity'] ?? 'warning');
        $message = trim((string) ($startRow['message'] ?? ''));
        if ($message === '') {
            $message = (string) $lane['label'];
        }

        return [
            'id' => (int) ($startRow['id'] ?? 0),
            'lane_key' => (string) $lane['key'],
            'lane_label' => (string) $lane['label'],
            'style' => (string) ($lane['style'] ?? 'generic'),
            'family' => (string) ($lane['family'] ?? 'generic'),
            'severity' => $severity,
            'message' => $message,
            'start_ts' => (string) ($startRow['ts'] ?? ''),
            'end_ts' => $endRow['ts'] ?? null,
            'start_epoch' => $startEpoch,
            'end_epoch' => $endEpoch,
            'display_start_epoch' => $displayStart,
            'display_end_epoch' => $displayEnd,
            'duration_sec' => max(0, ($endEpoch ?? $toEpoch) - $startEpoch),
            'is_ongoing' => $endRow === null,
            'started_before_range' => $startEpoch < $fromEpoch,
            'ended_after_range' => $endEpoch !== null && $endEpoch > $toEpoch,
        ];
    }

    private function messageGpio(string $message): ?string
    {
        if (preg_match('/GPIO\s*([0-9]+)/i', $message, $m) === 1) {
            return (string) $m[1];
        }
        return null;
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

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function normalizeTs(mixed $ts): ?string
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

        return null;
    }

    private function decorateRawPayload(array $raw, ?string $deviceTs, string $serverNow): array
    {
        $raw['_server_received_at'] = $serverNow;
        if ($deviceTs !== null) {
            $raw['_device_ts_normalized'] = $deviceTs;
        }
        return $raw;
    }
}

<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class DeviceService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function all(): array
    {
        return $this->allPage(1, 1000);
    }

    public function allPage(int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sql = "
            SELECT d.*, s.last_seen_at, s.online, s.power_mode, s.battery_pct, s.temperature, s.humidity, s.pressure_hpa, s.air_quality, s.rssi,
                   s.raw_json, s.reported_config_version,
                   c.config_version AS desired_config_version,
                   CASE WHEN c.config_version IS NOT NULL AND s.reported_config_version IS NOT NULL AND c.config_version <> s.reported_config_version THEN 1 ELSE 0 END AS config_mismatch,
                   COALESCE(a.recent_alert_count, 0) AS recent_alert_count,
                   COALESCE(aa.active_alert_count, 0) AS active_alert_count,
                   COALESCE(aa.active_temp_alert_count, 0) AS active_temp_alert_count,
                   COALESCE(aa.active_contact_alert_count, 0) AS active_contact_alert_count,
                   COALESCE(hca.active_hc_count, 0) AS active_hc_count,
                   a.latest_alert_ts,
                   a.latest_alert_severity
            FROM devices d
            LEFT JOIN device_last_state s ON s.device_id = d.device_id
            LEFT JOIN (
                SELECT dc1.*
                FROM device_config dc1
                INNER JOIN (
                    SELECT device_id, MAX(id) AS max_id FROM device_config GROUP BY device_id
                ) latest ON latest.max_id = dc1.id
            ) c ON c.device_id = d.device_id
            LEFT JOIN (
                SELECT t.device_id,
                       t.recent_alert_count,
                       t.latest_alert_ts,
                       (
                           SELECT a2.severity
                           FROM alerts a2
                           WHERE a2.device_id = t.device_id
                             AND a2.ts >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                           ORDER BY a2.ts DESC, a2.id DESC
                           LIMIT 1
                       ) AS latest_alert_severity
                FROM (
                    SELECT device_id,
                           COUNT(*) AS recent_alert_count,
                           MAX(ts) AS latest_alert_ts
                    FROM alerts
                    WHERE ts >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    GROUP BY device_id
                ) t
            ) a ON a.device_id = d.device_id
            LEFT JOIN (
                SELECT latest.device_id,
                       COUNT(*) AS active_alert_count,
                       SUM(CASE WHEN latest.alert_family IN ('temp_high', 'temp_low') THEN 1 ELSE 0 END) AS active_temp_alert_count,
                       SUM(CASE WHEN latest.alert_family = 'contact' THEN 1 ELSE 0 END) AS active_contact_alert_count
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
            ) aa ON aa.device_id = d.device_id
            LEFT JOIN (
                SELECT a.device_id, COUNT(*) AS active_hc_count
                FROM alerts a
                INNER JOIN (
                    SELECT device_id,
                           CASE WHEN event_type LIKE '%_cleared'
                                THEN REPLACE(event_type, '_cleared', '')
                                ELSE event_type END AS hc_family,
                           MAX(id) AS max_id
                    FROM alerts
                    WHERE event_type LIKE 'hc_%'
                    GROUP BY device_id,
                             CASE WHEN event_type LIKE '%_cleared'
                                  THEN REPLACE(event_type, '_cleared', '')
                                  ELSE event_type END
                ) latest_hc ON latest_hc.max_id = a.id
                WHERE a.event_type LIKE 'hc_%' AND a.event_type NOT LIKE '%_cleared'
                GROUP BY a.device_id
            ) hca ON hca.device_id = d.device_id
            ORDER BY d.name ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";
        return $this->db->query($sql)->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM devices")->fetchColumn();
    }

    public function find(string $deviceId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM devices WHERE device_id = :device_id LIMIT 1");
        $stmt->execute(['device_id' => $deviceId]);
        $device = $stmt->fetch();
        return $device ?: null;
    }

    public function lastState(string $deviceId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM device_last_state WHERE device_id = :device_id LIMIT 1");
        $stmt->execute(['device_id' => $deviceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function currentConfig(string $deviceId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM device_config WHERE device_id = :device_id ORDER BY id DESC LIMIT 1");
        $stmt->execute(['device_id' => $deviceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function recentAlerts(string $deviceId, int $limit = 10): array
    {
        return $this->recentAlertsPage($deviceId, 1, $limit);
    }

    public function recentAlertsPage(string $deviceId, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->prepare("SELECT * FROM alerts WHERE device_id = :device_id ORDER BY ts DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute(['device_id' => $deviceId]);
        return $stmt->fetchAll();
    }

    public function countAlerts(string $deviceId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM alerts WHERE device_id = :device_id");
        $stmt->execute(['device_id' => $deviceId]);
        return (int) $stmt->fetchColumn();
    }

    public function recentPresence(string $deviceId, int $limit = 20): array
    {
        return $this->recentPresencePage($deviceId, 1, $limit);
    }

    public function recentPresencePage(string $deviceId, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->prepare("SELECT * FROM device_presence_log WHERE device_id = :device_id ORDER BY happened_at DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute(['device_id' => $deviceId]);
        return $stmt->fetchAll();
    }

    public function countPresence(string $deviceId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM device_presence_log WHERE device_id = :device_id");
        $stmt->execute(['device_id' => $deviceId]);
        return (int) $stmt->fetchColumn();
    }

    public function queueStats(string $deviceId): array
    {
        $stmt = $this->db->prepare("SELECT status, COUNT(*) AS cnt FROM command_queue WHERE device_id = :device_id GROUP BY status");
        $stmt->execute(['device_id' => $deviceId]);
        $stats = ['queued' => 0, 'sent' => 0, 'acked' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $stats[$row['status']] = (int) $row['cnt'];
        }
        return $stats;
    }

    public function saveDevice(array $data): string
    {
        $deviceId = trim((string) ($data['device_id'] ?? ''));
        $exists = $this->find($deviceId);

        $deviceType = (string) ($data['device_type'] ?? 'master');
        if (!in_array($deviceType, ['master', 'slave'], true)) {
            $deviceType = 'master';
        }

        if ($exists) {
            $stmt = $this->db->prepare("UPDATE devices SET name=:name, location=:location, sim_phone=:sim_phone, fw_version=:fw_version, active=:active, device_type=:device_type, updated_at=NOW() WHERE device_id=:device_id");
        } else {
            $stmt = $this->db->prepare("INSERT INTO devices (device_id, name, location, sim_phone, fw_version, active, device_type, created_at, updated_at) VALUES (:device_id, :name, :location, :sim_phone, :fw_version, :active, :device_type, NOW(), NOW())");
        }

        $stmt->execute([
            'device_id'   => $deviceId,
            'name'        => trim((string) ($data['name'] ?? '')),
            'location'    => trim((string) ($data['location'] ?? '')),
            'sim_phone'   => trim((string) ($data['sim_phone'] ?? '')),
            'fw_version'  => trim((string) ($data['fw_version'] ?? '')),
            'active'      => isset($data['active']) ? 1 : 0,
            'device_type' => $deviceType,
        ]);

        return $deviceId;
    }
}

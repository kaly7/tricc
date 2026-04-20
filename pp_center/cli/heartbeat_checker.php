<?php
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Logger;
use App\Services\MattermostService;
use App\Services\AlertService;

$db = Database::connection();
$mattermost = new MattermostService();
$alertService = new AlertService();

$sql = "
    SELECT d.device_id, d.name, s.last_seen_at, c.heartbeat_sec
    FROM devices d
    JOIN device_last_state s ON s.device_id = d.device_id
    LEFT JOIN (
        SELECT dc1.*
        FROM device_config dc1
        INNER JOIN (SELECT device_id, MAX(id) AS max_id FROM device_config GROUP BY device_id) latest ON latest.max_id = dc1.id
    ) c ON c.device_id = d.device_id
    WHERE d.active = 1
      AND s.online = 1
      AND s.last_seen_at IS NOT NULL
      AND TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW()) > COALESCE(c.heartbeat_sec, 180) * 2
";

$rows = $db->query($sql)->fetchAll();
Logger::write('heartbeat', 'Heartbeat ellenőrzés lefutott', ['count' => count($rows)]);

foreach ($rows as $row) {
    $normalized = $alertService->store((string) $row['device_id'], [
        'device_id' => (string) $row['device_id'],
        'event_type' => 'device_offline',
        'severity' => 'warning',
        'message' => 'Eszkoz offline (heartbeat timeout)',
        'reason' => 'heartbeat_timeout',
        'last_seen_at' => $row['last_seen_at'],
    ]);

    $db->prepare("UPDATE device_last_state SET online = 0, updated_at = NOW() WHERE device_id = :device_id")
        ->execute(['device_id' => $row['device_id']]);

    $mattermost->notify(
        'Eszköz offline: ' . $row['name'],
        sprintf('%s több mint %d másodperce nem jelentkezett. Utolsó kapcsolat: %s', $row['name'], ((int) ($row['heartbeat_sec'] ?? 180)) * 2, $row['last_seen_at']),
        'warning',
        [
            'Eszköz' => $row['device_id'],
            'Idő (szerver)' => (string) ($normalized['server_received_at'] ?? date('Y-m-d H:i:s')),
            'Ok' => 'heartbeat timeout',
        ]
    );
}

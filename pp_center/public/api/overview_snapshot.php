<?php
require __DIR__ . '/../../app/web_bootstrap.php';

use App\Services\DeviceService;
use App\Services\AlertService;
use App\Core\Database;

$deviceService = new DeviceService();
$alertService = new AlertService();
$db = Database::connection();

$alertsPage = resolve_page($_GET['alerts_page'] ?? 1);
$alertsPerPage = 20;
$alertsTotal = $alertService->count();
$alerts = $alertService->recentPage($alertsPage, $alertsPerPage);
$latestAlerts = $alertService->recent(20);
$devices = array_slice($deviceService->all(), 0, 8);

ob_start();
include __DIR__ . '/../../templates/overview_devices_fragment.php';
$devicesHtml = ob_get_clean();

ob_start();
include __DIR__ . '/../../templates/overview_alerts_fragment.php';
$alertsHtml = ob_get_clean();

$stats = [
    'devices_total' => (int) $db->query("SELECT COUNT(*) FROM devices")->fetchColumn(),
    'devices_online' => (int) $db->query("SELECT COUNT(*) FROM device_last_state WHERE online = 1")->fetchColumn(),
    'alerts_open' => (int) $db->query("SELECT COUNT(*) FROM alerts WHERE ts >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    'queued_commands' => (int) $db->query("SELECT COUNT(*) FROM command_queue WHERE status = 'queued'")->fetchColumn(),
    'config_mismatch' => (int) $db->query("SELECT COUNT(*) FROM device_last_state s JOIN (SELECT device_id, MAX(config_version) AS config_version FROM device_config GROUP BY device_id) c ON c.device_id = s.device_id WHERE s.reported_config_version IS NOT NULL AND c.config_version <> s.reported_config_version")->fetchColumn(),
    'bridge_running' => (int) $db->query("SELECT COUNT(*) FROM worker_status WHERE status = 'running'")->fetchColumn(),
    'devices_with_active_alerts' => $alertService->countDevicesWithActiveAlerts(),
];

header('Content-Type: application/json; charset=utf-8');
$json = json_encode([
    'devices_html' => $devicesHtml,
    'alerts_html' => $alertsHtml,
    'stats' => $stats,
    'latest_alerts' => array_map(static function (array $alert): array {
        return [
            'id' => (int) ($alert['id'] ?? 0),
            'event_type' => (string) ($alert['event_type'] ?? ''),
            'severity' => (string) ($alert['severity'] ?? ''),
            'ts' => (string) ($alert['ts'] ?? ''),
        ];
    }, $latestAlerts),
    'generated_at' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

if ($json === false) {
    http_response_code(500);
    echo json_encode([
        'error' => 'snapshot_encode_failed',
        'generated_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

echo $json;

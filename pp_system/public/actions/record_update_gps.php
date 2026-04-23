<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../../src/db.php';
require_once __DIR__.'/../../src/helpers.php';

if (is_worker()) { http_response_code(403); exit; }
header('Content-Type: application/json; charset=utf-8');

$recordId = (int)($_POST['record_id'] ?? 0);
$lat      = (float)($_POST['lat'] ?? 0);
$lng      = (float)($_POST['lng'] ?? 0);

if (!$recordId || $lat === 0.0 || $lng === 0.0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Hiányzó adat']);
    exit;
}

if ($lat < 45.0 || $lat > 49.0 || $lng < 15.0 || $lng > 23.0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Koordináta Magyarországon kívül']);
    exit;
}

$db = db();

$st = $db->prepare("SELECT id FROM records WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$recordId]);
if (!$st->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Rekord nem található']);
    exit;
}

$db->prepare("UPDATE records SET gps_lat = ?, gps_lng = ? WHERE id = ?")
   ->execute([$lat, $lng, $recordId]);

log_change($db, $recordId, current_user()['id'], 'gps_update',
    '', 'GPS koordináta manuálisan javítva: ' . $lat . ', ' . $lng);

echo json_encode(['ok' => true, 'lat' => $lat, 'lng' => $lng]);

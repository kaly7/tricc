<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_login();

header('Content-Type: application/json');
$db = agv_db();

$agvs = $db->query("
    SELECT a.id, a.name, a.serial_no,
           c.x, c.y, c.theta, c.battery_charge, c.operating_mode,
           c.driving, c.paused, c.vx, c.vy, c.updated_at
    FROM agv a
    LEFT JOIN agv_coords c ON c.agv_id = a.id
    WHERE a.enabled = 1
    ORDER BY a.id
")->fetch_all(MYSQLI_ASSOC);

$trails = [];
foreach ($agvs as $agv) {
    $id = (int)$agv['id'];
    $st = $db->prepare("
        SELECT x, y, theta
        FROM agv_coords_history
        WHERE agv_id = ?
        ORDER BY recorded_at DESC
        LIMIT 80
    ");
    $st->bind_param('i', $id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $trails[$id] = array_reverse($rows);
    $st->close();
}

echo json_encode([
    'agvs'   => $agvs,
    'trails' => $trails,
    'ts'     => date('H:i:s'),
]);

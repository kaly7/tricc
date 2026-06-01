<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_login();
header('Content-Type: application/json');

$db = agv_db();

$rows = $db->query("
    SELECT a.id, a.name, a.serial_no,
           c.x, c.y, c.theta,
           c.map_id, c.position_initialized,
           c.localization_score, c.deviation_range,
           c.vx, c.vy, c.omega,
           c.battery_charge, c.battery_voltage,
           c.operating_mode, c.driving, c.paused,
           c.source, c.updated_at,
           TIMESTAMPDIFF(SECOND, c.updated_at, NOW()) AS age_sec
    FROM agv a
    LEFT JOIN agv_coords c ON c.agv_id = a.id
    WHERE a.enabled = 1
    ORDER BY a.id
")->fetch_all(MYSQLI_ASSOC);

$out = [];
foreach ($rows as $r) {
    $theta = $r['theta'] !== null ? (float)$r['theta'] : null;
    $vx    = $r['vx']    !== null ? (float)$r['vx']    : null;
    $vy    = $r['vy']    !== null ? (float)$r['vy']    : null;
    $speed = ($vx !== null && $vy !== null) ? round(sqrt($vx**2 + $vy**2), 3) : null;

    $out[] = [
        'id'        => (int)$r['id'],
        'name'      => $r['name'] ?: $r['serial_no'],
        'x'         => $r['x']     !== null ? round((float)$r['x'], 4) : null,
        'y'         => $r['y']     !== null ? round((float)$r['y'], 4) : null,
        'theta'     => $theta,
        'theta_deg' => $theta !== null ? round(rad2deg($theta), 1) : null,
        'map_id'    => $r['map_id'] ?: null,
        'pos_init'  => $r['position_initialized'] !== null ? (bool)$r['position_initialized'] : null,
        'loc_score' => $r['localization_score']  !== null ? round((float)$r['localization_score'], 3) : null,
        'dev_range' => $r['deviation_range']      !== null ? round((float)$r['deviation_range'], 3) : null,
        'vx'        => $vx !== null ? round($vx, 3) : null,
        'vy'        => $vy !== null ? round($vy, 3) : null,
        'omega'     => $r['omega'] !== null ? round((float)$r['omega'], 4) : null,
        'speed'     => $speed,
        'battery'   => $r['battery_charge']  !== null ? round((float)$r['battery_charge'], 1)  : null,
        'voltage'   => $r['battery_voltage'] !== null ? round((float)$r['battery_voltage'], 2) : null,
        'mode'      => $r['operating_mode'] ?: null,
        'driving'   => $r['driving'] !== null ? (bool)$r['driving'] : null,
        'paused'    => $r['paused']  !== null ? (bool)$r['paused']  : null,
        'source'    => $r['source'],
        'updated'   => $r['updated_at'] ? date('H:i:s', strtotime($r['updated_at'])) : null,
        'age_sec'   => $r['age_sec'],
    ];
}

echo json_encode($out);

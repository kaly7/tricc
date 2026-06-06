<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_admin();
header('Content-Type: application/json');

$broker = agv_db()->query("SELECT * FROM mqtt_broker WHERE id=1")->fetch_assoc()
          ?? ['ip'=>'','port'=>1883,'username'=>'','password'=>''];

if (empty($broker['ip'])) {
    echo json_encode(['ok'=>false, 'error'=>'Nincs broker IP beállítva.']);
    exit;
}

$payload = json_encode([
    'forrás'  => 'AGV Manager – kapcsolat teszt',
    'időpont' => date('Y-m-d H:i:s'),
    'üzenet'  => 'Az AGV Manager sikeresen csatlakozott a brokerhez.',
], JSON_UNESCAPED_UNICODE);

$args = [
    'mosquitto_pub',
    '-h', $broker['ip'],
    '-p', (string)(int)$broker['port'],
    '-t', 'AGV_TESZT',
    '-m', $payload,
];
if (!empty($broker['username'])) {
    $args[] = '-u'; $args[] = $broker['username'];
    $args[] = '-P'; $args[] = $broker['password'];
}

$cmd = implode(' ', array_map('escapeshellarg', $args));
exec($cmd . ' 2>&1', $out, $ret);

if ($ret !== 0) {
    echo json_encode(['ok'=>false, 'error'=>'mosquitto_pub hiba: ' . implode(' ', $out)]);
    exit;
}

echo json_encode(['ok'=>true, 'topic'=>'AGV_TESZT', 'ts'=>date('H:i:s')]);

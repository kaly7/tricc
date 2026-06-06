<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_admin();
header('Content-Type: application/json');

$omron = agv_db()->query("SELECT * FROM omron_broker WHERE id=1")->fetch_assoc()
         ?? ['ip'=>'','port'=>1883,'username'=>'','password'=>''];

if (empty($omron['ip'])) {
    echo json_encode(['ok'=>false, 'error'=>'Nincs Omron broker IP beállítva.']);
    exit;
}

$payload = json_encode([
    'forrás'  => 'AGV Manager – Omron kapcsolat teszt',
    'időpont' => date('Y-m-d H:i:s'),
    'üzenet'  => 'Az AGV Manager sikeresen csatlakozott az Omron brokerhez.',
], JSON_UNESCAPED_UNICODE);

$args = [
    'mosquitto_pub',
    '-h', $omron['ip'],
    '-p', (string)(int)$omron['port'],
    '-t', 'OMRON_TESZT',
    '-m', $payload,
];
if (!empty($omron['username'])) {
    $args[] = '-u'; $args[] = $omron['username'];
    $args[] = '-P'; $args[] = $omron['password'];
}

$cmd = implode(' ', array_map('escapeshellarg', $args));
exec($cmd . ' 2>&1', $out, $ret);

if ($ret !== 0) {
    echo json_encode(['ok'=>false, 'error'=>'mosquitto_pub hiba: ' . implode(' ', $out)]);
    exit;
}

echo json_encode(['ok'=>true, 'topic'=>'OMRON_TESZT', 'ts'=>date('H:i:s')]);

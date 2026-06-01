<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/worker/phpMQTT.php';
agv_require_admin();
header('Content-Type: application/json');

$db     = agv_db();
$broker = $db->query("SELECT * FROM mqtt_broker WHERE id=1")->fetch_assoc()
          ?? ['ip'=>'','port'=>1883,'username'=>'','password'=>''];

if (empty($broker['ip'])) {
    echo json_encode(['ok'=>false, 'error'=>'Nincs broker IP beállítva.']);
    exit;
}

$mqtt = new PhpMQTT($broker['ip'], (int)$broker['port'], 'agvmgr-test-' . uniqid());
if (!empty($broker['username'])) {
    $mqtt->setAuth($broker['username'], $broker['password']);
}

if (!$mqtt->connect()) {
    echo json_encode(['ok'=>false, 'error'=>'MQTT kapcsolódás sikertelen.']);
    exit;
}

$payload = json_encode([
    'forrás'    => 'AGV Manager – kapcsolat teszt',
    'időpont'   => date('Y-m-d H:i:s'),
    'üzenet'    => 'Az AGV Manager sikeresen csatlakozott a brokerhez.',
], JSON_UNESCAPED_UNICODE);

$mqtt->publish('AGV_TESZT', $payload, 0);
$mqtt->disconnect();

echo json_encode(['ok'=>true, 'topic'=>'AGV_TESZT', 'ts'=>date('H:i:s')]);

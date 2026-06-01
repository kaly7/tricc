<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/worker/phpMQTT.php';
agv_require_admin();
header('Content-Type: application/json');

$db    = agv_db();
$omron = $db->query("SELECT * FROM omron_broker WHERE id=1")->fetch_assoc()
         ?? ['ip'=>'','port'=>1883,'username'=>'','password'=>''];

if (empty($omron['ip'])) {
    echo json_encode(['ok'=>false, 'error'=>'Nincs Omron broker IP beállítva.']);
    exit;
}

$mqtt = new PhpMQTT($omron['ip'], (int)$omron['port'], 'agvmgr-omron-test-' . uniqid());
if (!empty($omron['username'])) {
    $mqtt->setAuth($omron['username'], $omron['password']);
}

if (!$mqtt->connect()) {
    echo json_encode(['ok'=>false, 'error'=>'MQTT kapcsolódás sikertelen.']);
    exit;
}

$payload = json_encode([
    'forrás'    => 'AGV Manager – Omron kapcsolat teszt',
    'időpont'   => date('Y-m-d H:i:s'),
    'üzenet'    => 'Az AGV Manager sikeresen csatlakozott az Omron brokerhez.',
], JSON_UNESCAPED_UNICODE);

$mqtt->publish('OMRON_TESZT', $payload, 0);
$mqtt->disconnect();

echo json_encode(['ok'=>true, 'topic'=>'OMRON_TESZT', 'ts'=>date('H:i:s')]);

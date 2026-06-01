<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_admin();
header('Content-Type: application/json');

$db = agv_db();
$omron = $db->query("SELECT ip, port FROM omron_broker WHERE id=1")->fetch_assoc()
         ?? ['ip' => '', 'port' => 1883];

if (empty($omron['ip'])) {
    echo json_encode(['ok' => false, 'error' => 'Nincs Omron broker IP beállítva.']);
    exit;
}

$sock = @fsockopen($omron['ip'], (int)$omron['port'], $errno, $errstr, 3);
if (!$sock) {
    echo json_encode(['ok' => false, 'error' => "TCP kapcsolódás sikertelen: $errstr ($errno)"]);
    exit;
}
fclose($sock);
echo json_encode(['ok' => true]);

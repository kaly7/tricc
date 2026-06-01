<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_admin();
header('Content-Type: application/json');

$db = agv_db();
$broker = $db->query("SELECT * FROM mqtt_broker WHERE id=1")->fetch_assoc()
          ?? ['ip'=>'','port'=>1883,'username'=>'','password'=>''];

if (empty($broker['ip'])) {
    echo json_encode(['ok'=>false,'error'=>'Nincs broker IP beállítva.']);
    exit;
}

$ip   = $broker['ip'];
$port = (int)$broker['port'];

$errno = 0; $errstr = '';
$sock = @fsockopen($ip, $port, $errno, $errstr, 3);
if (!$sock) {
    echo json_encode(['ok'=>false,'error'=>"TCP kapcsolódás sikertelen: $errstr ($errno)"]);
    exit;
}
fclose($sock);
echo json_encode(['ok'=>true]);

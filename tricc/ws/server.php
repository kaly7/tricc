<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory as LoopFactory;
use React\Socket\Server as ReactSocket;
use Tricc\WS\ChatServer;

$wsPort      = (int)($argv[1] ?? 9454);
$broadcastPort = (int)($argv[2] ?? 9455);

$loop = LoopFactory::create();
$chat = new ChatServer($loop);

// WebSocket szerver (publikus, 9454)
$wsSocket = new ReactSocket('0.0.0.0:' . $wsPort, $loop);
new IoServer(new HttpServer(new WsServer($chat)), $wsSocket, $loop);

// Belső broadcast TCP szerver (csak localhost, 9455)
// A REST API ide küld JSON-t, ez broadcast-olja a WS klienseknek
$broadcastSocket = new ReactSocket('127.0.0.1:' . $broadcastPort, $loop);
$broadcastSocket->on('connection', function(\React\Socket\ConnectionInterface $conn) use ($chat) {
    $buf = '';
    $conn->on('data', function(string $chunk) use (&$buf, $conn, $chat) {
        $buf .= $chunk;
        $data = json_decode($buf, true);
        if ($data !== null) {
            if (isset($data['target_user'], $data['payload'])) {
                $chat->sendToUser((int)$data['target_user'], $data['payload']);
            } elseif (!empty($data['broadcast_all'])) {
                $chat->broadcastAll($data['payload']);
            } elseif (isset($data['room_id'], $data['message'])) {
                $chat->broadcastMessage((int)$data['room_id'], $data['message']);
            } elseif (isset($data['room_id'])) {
                $chat->broadcastRaw((int)$data['room_id'], $data);
            }
            $conn->close();
        }
    });
    $conn->on('error', fn() => $conn->close());
});

echo "[Tricc WS] port $wsPort | broadcast 127.0.0.1:$broadcastPort\n";
$loop->run();

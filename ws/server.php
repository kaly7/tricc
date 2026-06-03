<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Tricc\WS\ChatServer;

$port = (int)($argv[1] ?? 9454);

$server = IoServer::factory(
    new HttpServer(new WsServer(new ChatServer())),
    $port,
    '0.0.0.0'
);

echo "[Tricc WS] listening on port $port\n";
$server->run();

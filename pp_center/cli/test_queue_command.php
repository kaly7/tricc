<?php
require __DIR__ . '/../app/bootstrap.php';

use App\Services\CommandService;

$deviceId = $argv[1] ?? '';
$cmd = $argv[2] ?? 'get_status';
$args = isset($argv[3]) ? json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR) : [];

if ($deviceId === '') {
    fwrite(STDERR, "Használat: php cli/test_queue_command.php <device_id> [cmd] [json_args]\n");
    exit(1);
}

$service = new CommandService();
$requestId = $service->queueCommand($deviceId, 'cmd_in', [
    'request_id' => '',
    'cmd' => $cmd,
    'args' => $args,
], 'cli');

echo "Queue OK: {$requestId}\n";

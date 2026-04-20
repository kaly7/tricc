<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\MattermostCommandProcessor;

header('Content-Type: application/json; charset=utf-8');

$payload = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?? []);
$token = (string) ($payload['token'] ?? '');

if ($token !== (string) cfg('mattermost.outgoing_token')) {
    http_response_code(403);
    echo json_encode(['text' => 'Érvénytelen Mattermost token.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$text = trim((string) ($payload['text'] ?? ''));
$user = (string) ($payload['user_name'] ?? 'mattermost');
$channelName = (string) ($payload['channel_name'] ?? '');

$processor = new MattermostCommandProcessor();
$result = $processor->handle($text, $user, $channelName, 'mattermost_outgoing', 'ephemeral');

echo json_encode(['text' => $result['text']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

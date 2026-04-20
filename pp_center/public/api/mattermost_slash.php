<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\MattermostCommandProcessor;

header('Content-Type: application/json; charset=utf-8');

$payload = $_POST ?: [];
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$headerToken = '';
if (preg_match('/^\s*Token\s+(.+)\s*$/i', (string) $authHeader, $m)) {
    $headerToken = trim((string) $m[1]);
}
$bodyToken = trim((string) ($payload['token'] ?? ''));
$expected = (string) cfg('mattermost.slash_token', cfg('mattermost.outgoing_token', ''));
$valid = ($bodyToken !== '' && hash_equals($expected, $bodyToken)) || ($headerToken !== '' && hash_equals($expected, $headerToken));

if (!$valid) {
    http_response_code(403);
    echo json_encode([
        'response_type' => 'ephemeral',
        'text' => 'Érvénytelen Mattermost slash token.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$text = trim((string) ($payload['text'] ?? ''));
$user = (string) ($payload['user_name'] ?? 'mattermost');
$channelName = (string) ($payload['channel_name'] ?? '');

$processor = new MattermostCommandProcessor();
$result = $processor->handle($text, $user, $channelName, 'mattermost_slash', 'ephemeral');

echo json_encode([
    'response_type' => $result['response_type'],
    'text' => $result['text'],
    'username' => (string) cfg('app.name', 'pp_center'),
    'skip_slack_parsing' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

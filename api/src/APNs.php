<?php
namespace Tricc;

class APNs {
    public static function send(string $device_token, string $title, string $body, array $data = [], int $badge = 1): bool {
        $cfg = require __DIR__ . '/../../config.php';

        $payload = json_encode([
            'aps' => [
                'alert' => ['title' => $title, 'body' => mb_substr($body, 0, 100, 'UTF-8')],
                'sound' => 'default',
                'badge' => $badge,
            ],
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);

        $cmd = [
            'curl', '--http2', '--silent', '--show-error',
            '-H', "apns-topic: {$cfg['apns_bundle_id']}",
            '-H', 'apns-push-type: alert',
            '-H', 'apns-priority: 10',
            '-H', "authorization: bearer " . self::jwtToken($cfg),
            '-H', 'content-type: application/json; charset=utf-8',
            '-d', $payload,
            '-w', "\nHTTP_STATUS:%{http_code}",
            "https://api.push.apple.com/3/device/{$device_token}",
        ];

        file_put_contents('/tmp/apns_debug.log', date('Y-m-d H:i:s') . ' payload=' . $payload . PHP_EOL, FILE_APPEND);
        $result = shell_exec(implode(' ', array_map('escapeshellarg', $cmd)));
        $ok = str_contains((string)$result, 'HTTP_STATUS:200');
        error_log("[APNs] " . ($ok ? 'OK' : 'HIBA') . " → $title | $body");
        return $ok;
    }

    private static function jwtToken(array $cfg): string {
        $header  = self::b64url(json_encode(['alg' => 'ES256', 'kid' => $cfg['apns_key_id']]));
        $payload = self::b64url(json_encode(['iss' => $cfg['apns_team_id'], 'iat' => time()]));
        $msg     = "$header.$payload";
        $key     = openssl_pkey_get_private(file_get_contents($cfg['apns_key_file']));
        openssl_sign($msg, $sig, $key, OPENSSL_ALGO_SHA256);
        return "$msg." . self::b64url($sig);
    }

    private static function b64url(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}

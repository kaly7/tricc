<?php
namespace Tricc;

class FCM {
    public static function send(string $device_token, string $title, string $body, array $data = [], string $subtitle = ''): int {
        $cfg = require __DIR__ . '/../../config.php';
        if (empty($cfg['fcm_service_account'])) return 0;

        $sa = json_decode(file_get_contents($cfg['fcm_service_account']), true);
        if (!$sa) return 0;

        $accessToken = self::getAccessToken($sa);
        if (!$accessToken) return 0;

        $notif = [
            'title' => mb_substr($title, 0, 25, 'UTF-8'),
            'body'  => mb_substr($body,  0, 25, 'UTF-8'),
        ];

        $msgData = array_map('strval', $data);
        if ($subtitle) $msgData['subtitle'] = mb_substr($subtitle, 0, 25, 'UTF-8');

        $payload = json_encode([
            'message' => [
                'token'        => $device_token,
                'notification' => $notif,
                'data'         => $msgData,
                'android'      => ['notification' => ['channel_id' => 'babl42_messages']],
            ],
        ]);

        $cmd = [
            'curl', '--silent', '--show-error',
            '-H', "Authorization: Bearer $accessToken",
            '-H', 'Content-Type: application/json',
            '-d', $payload,
            '-w', "\nHTTP_STATUS:%{http_code}",
            "https://fcm.googleapis.com/v1/projects/{$sa['project_id']}/messages:send",
        ];

        $result = shell_exec(implode(' ', array_map('escapeshellarg', $cmd)));
        preg_match('/HTTP_STATUS:(\d+)/', (string)$result, $sm);
        $status = (int)($sm[1] ?? 0);
        error_log("[FCM] HTTP $status → $title | $body");
        return $status;
    }

    private static function getAccessToken(array $sa): string {
        $cache = '/tmp/tricc_fcm_token.json';
        if (is_file($cache)) {
            $c = json_decode(file_get_contents($cache), true);
            if ($c && $c['expires_at'] > time() + 60) return $c['token'];
        }

        $now     = time();
        $header  = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims  = self::b64url(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $msg = "$header.$claims";
        $key = openssl_pkey_get_private($sa['private_key']);
        openssl_sign($msg, $sig, $key, OPENSSL_ALGO_SHA256);
        $jwt = "$msg." . self::b64url($sig);

        $result = shell_exec(implode(' ', array_map('escapeshellarg', [
            'curl', '--silent', '-d',
            "grant_type=urn:ietf:params:oauth2:grant-type:jwt-bearer&assertion=$jwt",
            'https://oauth2.googleapis.com/token',
        ])));

        $resp  = json_decode((string)$result, true);
        $token = $resp['access_token'] ?? '';
        if ($token) {
            file_put_contents($cache, json_encode([
                'token'      => $token,
                'expires_at' => $now + ($resp['expires_in'] ?? 3600),
            ]));
        }
        return $token;
    }

    private static function b64url(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}

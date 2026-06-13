<?php
namespace Tricc;

class FCM {
    private static ?string $cachedToken = null;
    private static int $tokenExpiry = 0;

    public static function send(string $device_token, string $title, string $body, array $data = []): bool {
        $cfg        = require __DIR__ . '/../../config.php';
        $sa         = json_decode(file_get_contents($cfg['fcm_service_account']), true);
        $project_id = $sa['project_id'];
        $access_tok = self::accessToken($sa);
        if (!$access_tok) return false;

        $payload = json_encode([
            'message' => [
                'token' => $device_token,
                'notification' => ['title' => $title, 'body' => mb_substr($body, 0, 100, 'UTF-8'), 'sound' => 'default'],
                'data'         => array_map('strval', $data),
                'android'      => ['priority' => 'high'],
            ],
        ]);

        $cmd = [
            'curl', '--silent', '--show-error',
            '-X', 'POST',
            '-H', 'Content-Type: application/json; charset=utf-8',
            '-H', "Authorization: Bearer $access_tok",
            '-d', $payload,
            '-w', "\nHTTP_STATUS:%{http_code}",
            "https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send",
        ];

        $result = shell_exec(implode(' ', array_map('escapeshellarg', $cmd)));
        $ok = str_contains((string)$result, 'HTTP_STATUS:200');
        error_log("[FCM] " . ($ok ? 'OK' : 'HIBA') . " → $title | " . trim((string)$result));
        return $ok;
    }

    private static function accessToken(array $sa): ?string {
        $cacheFile = '/tmp/tricc_fcm_token.json';
        if (self::$cachedToken && time() < self::$tokenExpiry) return self::$cachedToken;
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && $cached['expiry'] > time() + 60) {
                self::$cachedToken = $cached['token'];
                self::$tokenExpiry = $cached['expiry'];
                return self::$cachedToken;
            }
        }

        $now    = time();
        $header  = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $sa['private_key_id']]));
        $payload = self::b64url(json_encode([
            'iss'   => $sa['client_email'],
            'sub'   => $sa['client_email'],
            'aud'   => 'https://oauth2.googleapis.com/token',
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $msg = "$header.$payload";
        $key = openssl_pkey_get_private($sa['private_key']);
        if (!$key) return null;
        openssl_sign($msg, $sig, $key, OPENSSL_ALGO_SHA256);
        $jwt = "$msg." . self::b64url($sig);

        $form = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);
        $resp = shell_exec(implode(' ', array_map('escapeshellarg', [
            'curl', '--silent', '-X', 'POST',
            '-H', 'Content-Type: application/x-www-form-urlencoded',
            '-d', $form,
            'https://oauth2.googleapis.com/token',
        ])));
        $tok = json_decode((string)$resp, true);
        if (empty($tok['access_token'])) return null;

        self::$cachedToken = $tok['access_token'];
        self::$tokenExpiry = $now + (int)($tok['expires_in'] ?? 3600) - 60;
        file_put_contents($cacheFile, json_encode(['token' => self::$cachedToken, 'expiry' => self::$tokenExpiry]));
        return self::$cachedToken;
    }

    private static function b64url(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}

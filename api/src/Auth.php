<?php
namespace Tricc;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
    private static function secret(): string {
        return (require __DIR__ . '/../../config.php')['jwt_secret'];
    }

    public static function token(int $user_id, bool $is_admin): string {
        return JWT::encode([
            'sub'   => $user_id,
            'admin' => $is_admin,
            'iat'   => time(),
            'exp'   => time() + 86400 * 30,
        ], self::secret(), 'HS256');
    }

    public static function verify(string $token): ?array {
        try {
            $payload = JWT::decode($token, new Key(self::secret(), 'HS256'));
            return ['user_id' => (int)$payload->sub, 'is_admin' => (bool)$payload->admin];
        } catch (\Exception) {
            return null;
        }
    }

    public static function require(): array {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $t = preg_match('/Bearer\s+(.+)/i', $h, $m) ? $m[1] : '';
        $data = $t ? self::verify($t) : null;
        if (!$data) Response::abort(401, 'Érvénytelen vagy hiányzó token.');
        return $data;
    }

    public static function requireAdmin(): array {
        $auth = self::require();
        if (!$auth['is_admin']) Response::abort(403, 'Admin jog szükséges.');
        return $auth;
    }
}

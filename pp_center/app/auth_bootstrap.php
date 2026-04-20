<?php
declare(strict_types=1);

$authConfig = require __DIR__ . '/../config/auth.php';

require_once '/var/www/html/_common/auth/db.php';
require_once '/var/www/html/_common/auth/url.php';
require_once '/var/www/html/_common/auth/Auth.php';

if (!function_exists('safe_path')) {
    function safe_path(string $path, string $fallback = '/'): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/') {
            return $fallback;
        }
        if (str_starts_with($path, '//')) {
            return $fallback;
        }
        if (preg_match('~^[a-zA-Z][a-zA-Z0-9+\-.]*:~', $path)) {
            return $fallback;
        }
        return $path;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($authConfig['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

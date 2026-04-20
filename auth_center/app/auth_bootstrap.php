<?php
declare(strict_types=1);

$config = require __DIR__ . '/config/auth.php';

require_once '/var/www/html/_common/auth/db.php';
require_once '/var/www/html/_common/auth/url.php';   // safe_path(), build_url(), auth_login_url()
require_once '/var/www/html/_common/auth/Auth.php';  // CentralAuth class

// Fallback: ha valamiért mégsem lett betöltve a url.php (rossz útvonal), ne haljunk el fehér oldallal
if (!function_exists('safe_path')) {
  function safe_path(string $path, string $fallback = '/'): string {
    $path = trim($path);
    if ($path === '' || $path[0] !== '/') return $fallback;
    if (str_starts_with($path, '//')) return $fallback;
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+\-.]*:~', $path)) return $fallback;
    return $path;
  }
}

session_name($config['session_name']);
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'domain' => '',
  'secure' => false,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

<?php
declare(strict_types=1);

$config = require __DIR__ . '/config/app.php';

require_once '/var/www/html/_common/auth/db.php';
require_once '/var/www/html/_common/auth/url.php';
require_once '/var/www/html/_common/auth/Auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/mailer.php';

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

CentralAuth::requireLogin($config);
CentralAuth::requireModuleAccess($config, $config['module_key']);

$user = tracker_current_user($config);
if ((int)($user['id'] ?? 0) <= 0) {
    throw new RuntimeException('A felhasználó azonosítása sikertelen.');
}

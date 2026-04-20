<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Bootstrap: közös indulási pont minden védett oldalhoz.
 * - betölti a modul konfigurációját
 * - csatlakoztatja a közös auth / DB / helper réteget
 * - elindítja a session-t
 * - ellenőrzi a belépést és a moduljogosultságot
 * - előkészíti a felhasználói adatokat a sablonokhoz
 */

// A konfiguráció betöltése után jönnek a közös auth / DB / helper modulok.
$config = require __DIR__ . '/config/app.php';

require_once '/var/www/html/_common/auth/db.php';
require_once '/var/www/html/_common/auth/url.php';
require_once '/var/www/html/_common/auth/Auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

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

$user = [
    'id' => current_auth_user_id(),
    'full_name' => current_auth_display_name(),
];

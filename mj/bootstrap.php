<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

require_once '/var/www/html/_common/auth/db.php';
require_once '/var/www/html/_common/auth/url.php';
require_once '/var/www/html/_common/auth/Auth.php';
require_once __DIR__ . '/db.php';

session_name($config['session_name']);
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => '',
  'secure'   => false,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

CentralAuth::requireLogin($config);
CentralAuth::requireModuleAccess($config, $config['module_key']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$mj_user = [
  'id'        => CentralAuth::userId(),
  'full_name' => $_SESSION['full_name'] ?? 'Felhasználó',
];

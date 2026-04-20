<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/auth_bootstrap.php';

CentralAuth::requireLogin($config);
CentralAuth::requireModuleAccess($config, 'auth');

if (!CentralAuth::isAdmin($config, 'auth')) {
  http_response_code(403);
  echo "403 - Admin jogosultság szükséges az Auth Centerhez.";
  exit;
}

$pdo = auth_pdo($config);
$title = 'Admin';
$loggedIn = true;

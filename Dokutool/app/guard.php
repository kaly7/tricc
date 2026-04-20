<?php
// app/guard.php — Auth Center (central) beléptetési ellenőrzés minden admin oldalhoz
require_once __DIR__ . '/session.php';

$script = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
$public_scripts = ['login.php', 'logout.php']; // publikus fájlok

// Central Auth libs
require_once '/var/www/html/_common/auth/db.php';
require_once '/var/www/html/_common/auth/url.php';
require_once '/var/www/html/_common/auth/Auth.php'; // CentralAuth

function dt_host_no_port(): string {
  $h = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return explode(':', $h)[0];
}
function dt_auth_center_url(string $path): string {
  if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
  return 'http://' . dt_host_no_port() . ':90' . $path;
}

function dt_central_cfg(): array {
  return [
    'db' => [
      'dsn'  => 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4',
      'user' => 'ppdb',
      'pass' => 'abrakadabra', // <-- set ppdb password (same as Auth Center)
    ],
    'auth_port'    => 90,
    'session_name' => 'FEJLESZTES_SESSID',
    'module_key'   => 'dokutool',
  ];
}

function dt_sync_session_from_central(): void {
  $cfg = dt_central_cfg();

  if (CentralAuth::userId() === null) {
    unset($_SESSION['user_id'], $_SESSION['username']);
    return;
  }

  $role = CentralAuth::roleForModule($cfg, 'dokutool');
  if ($role === null) {
    // logged in, but no module access
    unset($_SESSION['user_id'], $_SESSION['username']);
    return;
  }

  // Keep Dokutool's old session fields for UI compatibility
  $_SESSION['user_id']  = (int)CentralAuth::userId();
  $_SESSION['username'] = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
  $_SESSION['role_key'] = $role;
}

// Always sync once per request
dt_sync_session_from_central();

if (!in_array($script, $public_scripts, true)) {
  if (CentralAuth::userId() === null) {
    header('Location: ' . dt_auth_center_url('/login.php?return=' . urlencode('/apps.php')));
    exit;
  }

  $cfg = dt_central_cfg();
  if (CentralAuth::roleForModule($cfg, 'dokutool') === null) {
    http_response_code(403);
    echo "Hozzáférés megtagadva (dokutool jogosultság hiányzik).";
    exit;
  }
}

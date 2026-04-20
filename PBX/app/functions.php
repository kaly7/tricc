<?php
declare(strict_types=1);

require_once __DIR__.'/db.php';

function base_url(string $path=''): string {
  $bp = rtrim((string)config()['base_path'], '/');
  $p = ltrim($path, '/');
  return $bp . '/' . $p;
}

function asset_url(string $path): string { return base_url($path); }

function e(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function start_session(): void {
  $name = (string)config()['session_name'];
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($name);
    session_start();
  }
}

function flash_set(string $k, string $v): void { start_session(); $_SESSION['_flash'][$k] = $v; }
function flash_get(string $k): ?string {
  start_session();
  if (!isset($_SESSION['_flash'][$k])) return null;
  $v = (string)$_SESSION['_flash'][$k];
  unset($_SESSION['_flash'][$k]);
  return $v;
}

function csrf_token(): string {
  start_session();
  if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['_csrf'];
}

function verify_csrf(): void {
  start_session();
  $ok = isset($_POST['_csrf'], $_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], (string)$_POST['_csrf']);
  if (!$ok) { http_response_code(400); exit('CSRF hiba'); }
}

function redirect(string $path): void { header('Location: '.base_url($path)); exit; }

function _host_no_port(): string {
  $h = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return explode(':', $h)[0];
}

function _auth_center_url(string $path): string {
  $port = (int)config()['auth_center_port'];
  if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
  return 'http://' . _host_no_port() . ':' . $port . $path;
}

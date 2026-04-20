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

function app_setting_table_exists(): bool {
  static $exists = null;
  if ($exists !== null) return $exists;
  try {
    $st = db()->query("SHOW TABLES LIKE 'app_settings'");
    $exists = (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    $exists = false;
  }
  return $exists;
}

function app_setting_cache(): array {
  if (!isset($GLOBALS['__assetmgr_app_settings_cache']) || !is_array($GLOBALS['__assetmgr_app_settings_cache'])) {
    $GLOBALS['__assetmgr_app_settings_cache'] = [];
  }
  return $GLOBALS['__assetmgr_app_settings_cache'];
}

function app_setting_cache_set(array $cache): void {
  $GLOBALS['__assetmgr_app_settings_cache'] = $cache;
}

function app_setting_get(string $key, $default = null) {
  $cache = app_setting_cache();
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $cfg = config();
  if (array_key_exists($key, $cfg)) {
    $default = $cfg[$key];
  }

  $value = $default;
  if (app_setting_table_exists()) {
    try {
      $st = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1");
      $st->execute([$key]);
      $dbValue = $st->fetchColumn();
      if ($dbValue !== false && $dbValue !== null && $dbValue !== '') {
        $value = $dbValue;
      }
    } catch (Throwable $e) {
      $value = $default;
    }
  }

  $cache[$key] = $value;
  app_setting_cache_set($cache);
  return $value;
}

function app_setting_forget(?string $key = null): void {
  if ($key === null) {
    app_setting_cache_set([]);
    return;
  }
  $cache = app_setting_cache();
  unset($cache[$key]);
  app_setting_cache_set($cache);
}

function app_setting_set(string $key, ?string $value, ?int $updatedByUserId = null): void {
  if (!app_setting_table_exists()) {
    throw new RuntimeException('Hiányzik az app_settings tábla. Futtasd le a migration fájlt.');
  }

  $value = trim((string)$value);
  $pdo = db();
  $st = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at, updated_by_user_id)
    VALUES (?, ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=VALUES(updated_at), updated_by_user_id=VALUES(updated_by_user_id)");
  $st->execute([$key, $value, $updatedByUserId]);
  app_setting_forget($key);
}

function _auth_center_url(string $path): string {
  $port = (int)config()['auth_center_port'];
  if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
  return 'http://' . _host_no_port() . ':' . $port . $path;
}

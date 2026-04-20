<?php
namespace App;

/**
 * ProjectMgr Auth adapter for Auth Center + optional sub-module "vehicles"
 *
 * Key point:
 * - Vehicles mode MUST persist after entry from Auth Center (even if subsequent links don't carry ?module=vehicles).
 *   We store the selected module in session: $_SESSION['app_module_key'].
 *
 * Module key resolution order:
 *  1) constant APP_MODULE_KEY (if defined)
 *  2) query param ?module=projectmgr|vehicles (whitelist)
 *  3) session $_SESSION['app_module_key'] (persisted mode)
 *  4) if running under port 87 -> vehicles (optional vhost)
 *  5) default -> projectmgr
 *
 * Important:
 * - This does NOT auto-switch to vehicles just because you're on a vehicle_* page.
 *   Switch happens only via explicit ?module=vehicles (Auth Center tile) or port 87.
 *   This keeps ProjectMgr full menu intact when you use vehicles from within ProjectMgr.
 */
class Auth {

  private static function moduleKey(): string {
    // 1) constant override
    if (defined('APP_MODULE_KEY')) {
      $mk = (string)constant('APP_MODULE_KEY');
      if (in_array($mk, ['projectmgr','vehicles'], true)) return $mk;
    }

    // 2) query param override (safe whitelist)
    if (!empty($_GET['module'])) {
      $mk = (string)$_GET['module'];
      if (in_array($mk, ['projectmgr','vehicles'], true)) return $mk;
    }

    // 3) session persisted mode
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['app_module_key'])) {
      $mk = (string)$_SESSION['app_module_key'];
      if (in_array($mk, ['projectmgr','vehicles'], true)) return $mk;
    }

    // 4) port-based (optional vhost on :87)
    $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    if ($port === 87) return 'vehicles';

    // 5) default
    return 'projectmgr';
  }

  public static function currentModuleKey(): string {
    self::start();
    return self::moduleKey();
  }

  private static function cfg(): array {
    return [
      'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4',
        'user' => 'ppdb',
        'pass' => 'abrakadabra', // <-- set ppdb password (same as auth_center config)
      ],
      'auth_port'    => 90,
      'session_name' => 'FEJLESZTES_SESSID',
      'module_key'   => self::moduleKey(),
    ];
  }

  public static function start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_name(self::cfg()['session_name']);
      session_start();
    }

    // Persist mode ONLY when explicitly requested (or port 87).
    $forced = null;
    if (defined('APP_MODULE_KEY')) $forced = (string)constant('APP_MODULE_KEY');
    if (!empty($_GET['module'])) $forced = (string)$_GET['module'];

    if ($forced !== null && in_array($forced, ['projectmgr','vehicles'], true)) {
      $_SESSION['app_module_key'] = $forced;
    } else {
      // If not set yet, default to projectmgr
      if (empty($_SESSION['app_module_key'])) {
        $_SESSION['app_module_key'] = ((int)($_SERVER['SERVER_PORT'] ?? 0) === 87) ? 'vehicles' : 'projectmgr';
      }
    }

    self::syncFromCentral();
  }

  private static function syncFromCentral(): void {
    // Load central auth libs
    require_once '/var/www/html/_common/auth/db.php';
    require_once '/var/www/html/_common/auth/url.php';
    require_once '/var/www/html/_common/auth/Auth.php'; // CentralAuth class

    $cfg = self::cfg();
    $moduleKey = self::moduleKey();

    // Not logged in centrally -> clear local user
    if (\CentralAuth::userId() === null) {
      unset($_SESSION['user']);
      return;
    }

    // No permission to module -> clear local user
    $roleKey = \CentralAuth::roleForModule($cfg, $moduleKey);
    if ($roleKey === null) {
      unset($_SESSION['user']);
      return;
    }

    // Map role_key to the role_id used in ProjectMgr
    $roleId = 3; // viewer
    if ($roleKey === 'admin') $roleId = 1;
    else if ($roleKey === 'user' || $roleKey === 'editor') $roleId = 2;

    // Optional email fetch
    $email = null;
    try {
      $pdo = auth_pdo($cfg);
      $st = $pdo->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
      $st->execute([\CentralAuth::userId()]);
      $row = $st->fetch();
      if ($row) $email = $row['email'] ?? null;
    } catch (\Throwable $e) { /* ignore */ }

    $_SESSION['user'] = [
      'id'       => (int)\CentralAuth::userId(),
      'name'     => (string)($_SESSION['full_name'] ?? ''),
      'email'    => $email,
      'role_id'  => $roleId,
      'role_key' => $roleKey,
      'module'   => $moduleKey,
    ];
  }

  public static function login(string $email, string $password): bool {
    self::start();
    return false;
  }

  public static function user(): ?array {
    self::start();
    return $_SESSION['user'] ?? null;
  }

  public static function logout(): void {
    self::start();
  }

  public static function check(): bool {
    return self::user() !== null;
  }

  public static function requireRole(int $minRoleId): void {
    $u = self::user();
    if (!$u) {
      self::redirectToAuthCenter();
    }
    if ((int)$u['role_id'] > $minRoleId) {
      http_response_code(403);
      exit('Hozzáférés megtagadva');
    }
  }

  public static function redirectToAuthCenter(): void {
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $hostNoPort = explode(':', (string)$host)[0];
    header('Location: http://' . $hostNoPort . ':90/login.php?return=' . urlencode('/apps.php'));
    exit;
  }
}

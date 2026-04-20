<?php
declare(strict_types=1);

/**
 * Central auth core class (renamed from Auth to avoid collisions inside modules that already have an Auth class)
 */
final class CentralAuth {
  public static function userId(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  }

  public static function requireLogin(array $config): void {
    if (!self::userId()) {
      $return = $_SERVER['REQUEST_URI'] ?? '/';
      header('Location: ' . auth_login_url($config, (string)$return));
      exit;
    }
  }

  public static function login(array $config, string $username, string $password): bool {
    $pdo = auth_pdo($config);
    $stmt = $pdo->prepare("
      SELECT id, full_name, password_hash, is_active
      FROM users
      WHERE username=?
      LIMIT 1
    ");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if (!$u) return false;
    if ((int)$u['is_active'] !== 1) return false;
    if (!password_verify($password, (string)$u['password_hash'])) return false;

    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$u['id'];
    $_SESSION['full_name'] = (string)$u['full_name'];

    $pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([(int)$u['id']]);
    return true;
  }

  public static function logout(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params["path"], '', $params["secure"], $params["httponly"]);
    }
    session_destroy();
  }

  public static function roleForModule(array $config, string $moduleKey): ?string {
    $uid = self::userId();
    if (!$uid) return null;

    $pdo = auth_pdo($config);
    $sql = "
      SELECT r.role_key
      FROM user_module_roles umr
      JOIN modules m ON m.id = umr.module_id
      JOIN roles r ON r.id = umr.role_id
      WHERE umr.user_id=? AND m.module_key=? AND m.is_enabled=1
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid, $moduleKey]);
    $row = $stmt->fetch();
    return $row ? (string)$row['role_key'] : null;
  }

  public static function requireModuleAccess(array $config, string $moduleKey): void {
    $role = self::roleForModule($config, $moduleKey);
    if (!$role) {
      // Only Auth Center should trigger password-change prompt on denied access.
      // Other modules should keep a normal 403.
      if (self::userId() && (($config['module_key'] ?? '') === 'auth')) {
        header('Location: /change_password.php');
        exit;
      }

      http_response_code(403);
      echo "403 - Nincs jogosultság ehhez a modulhoz.";
      exit;
    }
  }

  public static function isAdmin(array $config, string $moduleKey): bool {
    return self::roleForModule($config, $moduleKey) === 'admin';
  }

  public static function allowedModules(array $config): array {
    $uid = self::userId();
    if (!$uid) return [];

    $pdo = auth_pdo($config);
    $sql = "
      SELECT m.module_key, m.module_name, m.port, m.path, r.role_key
      FROM user_module_roles umr
      JOIN modules m ON m.id = umr.module_id
      JOIN roles r ON r.id = umr.role_id
      WHERE umr.user_id=? AND m.is_enabled=1
      ORDER BY m.module_name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid]);
    return $stmt->fetchAll();
  }
}

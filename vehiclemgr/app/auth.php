<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function authcfg(): array {
  return [
    'dsn'  => 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4',
    'user' => 'ppdb',
    'pass' => 'abrakadabra',
  ];
}

function auth_pdo(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  $c = authcfg();
  $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function _module_role(PDO $pdo, int $userId, string $moduleKey): ?string {
  $st = $pdo->prepare("SELECT r.role_key FROM user_module_roles umr JOIN modules m ON m.id=umr.module_id JOIN roles r ON r.id=umr.role_id WHERE umr.user_id=? AND m.module_key=? AND m.is_enabled=1 LIMIT 1");
  $st->execute([$userId, $moduleKey]);
  $rk = $st->fetchColumn();
  if (!$rk) return null;
  return match ((string)$rk) {
    'admin' => 'admin',
    'user'  => 'user',
    default => 'user',
  };
}

function try_sso_from_auth_center(): void {
  start_session();
  if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) return;

  $authSessName = 'FEJLESZTES_SESSID';
  if (empty($_COOKIE[$authSessName])) return;

  $mySessName = session_name();
  @session_write_close();

  session_name($authSessName);
  @session_start();

  $uid = null;
  if (!empty($_SESSION['user']['id']))  $uid = (int)$_SESSION['user']['id'];
  elseif (!empty($_SESSION['user_id'])) $uid = (int)$_SESSION['user_id'];
  elseif (!empty($_SESSION['uid']))     $uid = (int)$_SESSION['uid'];

  @session_write_close();
  session_name($mySessName);
  @session_start();

  if (!$uid) return;

  try {
    $pdo  = auth_pdo();
    $role = _module_role($pdo, $uid, (string)config()['module_slug']);
    if (!$role) return;

    $st = $pdo->prepare("SELECT username, email, full_name, hr_employee_id, is_active FROM users WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u || !(int)($u['is_active'] ?? 0)) return;

    $_SESSION['user'] = [
      'id'             => $uid,
      'username'       => (string)($u['username'] ?? ''),
      'email'          => $u['email'] ?? null,
      'name'           => (string)($u['full_name'] ?? $u['username'] ?? ''),
      'role'           => $role,
      'hr_employee_id' => $u['hr_employee_id'] !== null ? (int)$u['hr_employee_id'] : null,
    ];
  } catch (Throwable $e) {}
}

function attempt_login(string $username, string $password): bool {
  start_session();
  $username = trim($username);
  if ($username === '' || $password === '') return false;

  try {
    $pdo = auth_pdo();
    $st  = $pdo->prepare("SELECT id, username, email, full_name, password_hash, is_active, hr_employee_id FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !(int)($u['is_active'] ?? 0)) return false;
    if (!password_verify($password, (string)$u['password_hash'])) return false;

    $role = _module_role($pdo, (int)$u['id'], (string)config()['module_slug']);
    if (!$role) return false;

    $_SESSION['user'] = [
      'id'             => (int)$u['id'],
      'username'       => (string)$u['username'],
      'email'          => $u['email'] ?? null,
      'name'           => (string)($u['full_name'] ?? $u['username']),
      'role'           => $role,
      'hr_employee_id' => $u['hr_employee_id'] !== null ? (int)$u['hr_employee_id'] : null,
    ];
    return true;
  } catch (Throwable $e) { return false; }
}

function logout(): void {
  start_session();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
}

function current_user(): ?array {
  start_session();
  if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
    try_sso_from_auth_center();
  }
  return (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
}

function require_login(): void {
  if (!current_user()) {
    $return = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . base_url("login.php?return={$return}"));
    exit;
  }
}

function require_admin(): void {
  require_login();
  if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    flash_set('err', 'Ehhez a művelethez admin jogosultság szükséges.');
    redirect('index.php');
  }
}

// HR employee id a bejelentkezett userhez
function my_employee_id(): int {
  $u = current_user();
  return (int)($u['hr_employee_id'] ?? 0);
}

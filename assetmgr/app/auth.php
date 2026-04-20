<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * AssetMgr local auth using auth_db users table (same credentials as Auth Center),
 * with optional SSO import from Auth Center session (FEJLESZTES_SESSID) when user comes internally.
 *
 * Goals:
 * - External (8787): user logs in locally at /login.php (Auth Center web is NOT required)
 * - Internal (Auth Center app launcher): if FEJLESZTES_SESSID cookie exists and user has module permission,
 *   auto-login to AssetMgr without password prompt.
 */

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

function _role_map(string $roleKey): string {
  // auth_db.roles.role_key: admin|user|viewer
  // assetmgr roles: admin|editor|viewer
  return match ($roleKey) {
    'admin' => 'admin',
    'user'  => 'editor',
    default => 'viewer',
  };
}

function _module_role_for_user(PDO $pdo, int $userId, string $moduleKey): ?string {
  $sql = "
    SELECT r.role_key
    FROM user_module_roles umr
    JOIN modules m ON m.id = umr.module_id
    JOIN roles r   ON r.id = umr.role_id
    WHERE umr.user_id = ? AND m.module_key = ? AND m.is_enabled = 1
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$userId, $moduleKey]);
  $rk = $st->fetchColumn();
  if (!$rk) return null;
  return _role_map((string)$rk);
}

/**
 * Try to auto-login by reading Auth Center session (FEJLESZTES_SESSID).
 * - Does NOT require Auth Center web access, only the cookie/session file exists on server.
 * - Safe: if anything fails, it just does nothing.
 */
function try_sso_from_auth_center(): void {
  start_session();
  if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) return;

  $authSessName = 'FEJLESZTES_SESSID';
  if (empty($_COOKIE[$authSessName])) return;

  $assetSessName = session_name(); // ASSETMGR_SESSID (configured)
  @session_write_close();

  // Open Auth Center session
  session_name($authSessName);
  @session_start();

  $uid = null;
  if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['id'])) {
    $uid = (int)$_SESSION['user']['id'];
  } elseif (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
  } elseif (!empty($_SESSION['uid'])) {
    $uid = (int)$_SESSION['uid'];
  }

  @session_write_close();

  // Back to AssetMgr session
  session_name($assetSessName);
  @session_start();

  if (!$uid || $uid <= 0) return;

  try {
    $pdo = auth_pdo();
    $moduleKey = (string)config()['module_slug']; // 'assetmgr'
    $role = _module_role_for_user($pdo, $uid, $moduleKey);
    if ($role === null) return;

    // Load extra fields for UI/history
    $st = $pdo->prepare("SELECT username, email, full_name, hr_employee_id, is_active FROM users WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) return;
    if ((int)($u['is_active'] ?? 0) !== 1) return;

    $_SESSION['user'] = [
      'id'            => (int)$uid,
      'username'      => (string)($u['username'] ?? ''),
      'email'         => ($u['email'] ?? null) !== null ? (string)$u['email'] : null,
      'name'          => (string)($u['full_name'] ?? ($u['username'] ?? '')),
      'full_name'     => (string)($u['full_name'] ?? ($u['username'] ?? '')),
      'role'          => $role,
      'hr_employee_id'=> ($u['hr_employee_id'] ?? null) === null ? null : (int)$u['hr_employee_id'],
    ];
  } catch (Throwable $e) {
    // ignore
  }
}

function attempt_login(string $username, string $password): bool {
  start_session();
  $username = trim($username);
  if ($username === '' || $password === '') return false;

  try {
    $pdo = auth_pdo();
    $st = $pdo->prepare("SELECT id, username, email, full_name, password_hash, is_active, hr_employee_id FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u) return false;
    if ((int)($u['is_active'] ?? 0) !== 1) return false;

    $hash = (string)$u['password_hash'];
    if (!password_verify($password, $hash)) return false;

    $moduleKey = (string)config()['module_slug'];
    $role = _module_role_for_user($pdo, (int)$u['id'], $moduleKey);
    if ($role === null) return false;

    $_SESSION['user'] = [
      'id'            => (int)$u['id'],
      'username'      => (string)$u['username'],
      'email'         => $u['email'] !== null ? (string)$u['email'] : null,
      'name'          => (string)$u['full_name'],
      'full_name'     => (string)$u['full_name'],
      'role'          => $role,
      'hr_employee_id'=> $u['hr_employee_id'] === null ? null : (int)$u['hr_employee_id'],
    ];
    return true;
  } catch (Throwable $e) {
    return false;
  }
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
  if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    try_sso_from_auth_center();
  }
  return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login(): void {
  if (!current_user()) {
    $return = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: ' . base_url('login.php?return=' . urlencode($return)));
    exit;
  }
}

function require_role(string $role): void {
  require_login();
  $u = current_user();
  $map = ['viewer'=>1,'editor'=>2,'admin'=>3];
  $need = $map[$role] ?? 1;
  $have = $map[$u['role'] ?? 'viewer'] ?? 1;
  if ($have < $need) {
    flash_set('err', 'Ehhez a művelethez nincs megfelelő jogosultságod.');
    http_response_code(403);
    redirect('forbidden.php');
  }
}

/** Password policy for account page if used */
function password_meets_policy(string $password): bool {
  if (strlen($password) < 6) return false;
  $score = 0;
  if (preg_match('/[a-z]/', $password)) $score++;
  if (preg_match('/[A-Z]/', $password)) $score++;
  if (preg_match('/[0-9]/', $password)) $score++;
  if (preg_match('/[^a-zA-Z0-9]/', $password)) $score++;
  return $score >= 3;
}


function change_my_password(string $currentPassword, string $newPassword, string $newPassword2): array {
  require_login();
  $u = current_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) return ['ok'=>false,'msg'=>'Nincs bejelentkezve.'];

  $currentPassword = (string)$currentPassword;
  $newPassword  = (string)$newPassword;
  $newPassword2 = (string)$newPassword2;

  if (trim($currentPassword) === '' || trim($newPassword) === '' || trim($newPassword2) === '') {
    return ['ok'=>false,'msg'=>'Minden mező kitöltése kötelező.'];
  }
  if ($newPassword !== $newPassword2) {
    return ['ok'=>false,'msg'=>'Az új jelszó és az ismétlés nem egyezik.'];
  }
  if (!password_meets_policy($newPassword)) {
    return ['ok'=>false,'msg'=>'Az új jelszó legyen legalább 6 karakter, és legalább 3-at tartalmazzon ezek közül: kisbetű, nagybetű, szám, speciális karakter.'];
  }
  if ($newPassword === $currentPassword) {
    return ['ok'=>false,'msg'=>'Az új jelszó nem lehet azonos a jelenlegivel.'];
  }

  try {
    $pdo = auth_pdo();
    $st = $pdo->prepare("SELECT password_hash, is_active FROM users WHERE id=? LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) return ['ok'=>false,'msg'=>'Felhasználó nem található.'];
    if ((int)($row['is_active'] ?? 0) !== 1) return ['ok'=>false,'msg'=>'A felhasználó inaktív.'];

    $hash = (string)$row['password_hash'];
    if (!password_verify($currentPassword, $hash)) {
      return ['ok'=>false,'msg'=>'A jelenlegi jelszó hibás.'];
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$newHash, $userId]);
    return ['ok'=>true,'msg'=>'A jelszó sikeresen megváltozott.'];
  } catch (Throwable $e) {
    return ['ok'=>false,'msg'=>'Hiba történt a jelszó módosítása közben.'];
  }
}

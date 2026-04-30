<?php
declare(strict_types=1);

// Közös auth betöltése ELŐBB, mint a session indul
require_once '/var/www/html/_common/auth/db.php';
require_once '/var/www/html/_common/auth/url.php';
require_once '/var/www/html/_common/auth/Auth.php';

require_once __DIR__ . '/functions.php';

// Auth center konfig
function _wp_auth_cfg(): array {
  static $c = null;
  if ($c !== null) return $c;
  $cfg = config();
  $c = [
    'db'           => ['dsn' => 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4', 'user' => 'ppdb', 'pass' => 'abrakadabra'],
    'auth_port'    => (int)($cfg['auth_center_port'] ?? 90),
    'session_name' => 'FEJLESZTES_SESSID',
    'module_key'   => (string)($cfg['module_slug'] ?? 'workplanner'),
  ];
  return $c;
}

// Felhasználói adatok betöltése az auth_db-ből, cachelve a sessionbe
function _load_wp_user(): ?array {
  $uid = CentralAuth::userId();
  if (!$uid) return null;

  if (!empty($_SESSION['_wp_user']) && is_array($_SESSION['_wp_user'])
      && ($_SESSION['_wp_user']['id'] ?? 0) === $uid) {
    return $_SESSION['_wp_user'];
  }

  try {
    $cfg  = _wp_auth_cfg();
    $rk   = CentralAuth::roleForModule($cfg, $cfg['module_key']);
    if (!$rk) return null;

    $pdo = auth_pdo($cfg);
    $st  = $pdo->prepare("SELECT username, email, full_name, hr_employee_id, is_active FROM users WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u || !(int)$u['is_active']) return null;

    $user = [
      'id'             => $uid,
      'username'       => (string)$u['username'],
      'email'          => $u['email'] ?? null,
      'name'           => (string)($u['full_name'] ?? $u['username']),
      'role'           => ($rk === 'admin') ? 'admin' : 'user',
      'hr_employee_id' => $u['hr_employee_id'] !== null ? (int)$u['hr_employee_id'] : null,
    ];
    $_SESSION['_wp_user'] = $user;
    return $user;
  } catch (Throwable) { return null; }
}

function current_user(): ?array {
  start_session();
  return _load_wp_user();
}

function require_login(): void {
  start_session();
  if (!CentralAuth::userId()) {
    $cfg    = _wp_auth_cfg();
    $return = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . auth_login_url($cfg, $return));
    exit;
  }
  // Modul hozzáférés ellenőrzése
  if (!_load_wp_user()) {
    http_response_code(403);
    echo '<p>Nincs hozzáférésed a Napiterv modulhoz. Kérd az admin jogosultságot.</p>';
    exit;
  }
}

function require_admin(): void {
  require_login();
  $u = current_user();
  if (($u['role'] ?? '') !== 'admin') {
    flash_set('err', 'Ehhez admin jogosultság szükséges.');
    redirect('index.php');
  }
}

function logout(): void {
  start_session();
  unset($_SESSION['_wp_user']);
}

function my_employee_id(): int {
  return (int)(current_user()['hr_employee_id'] ?? 0);
}

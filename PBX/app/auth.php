<?php
declare(strict_types=1);

require_once __DIR__.'/functions.php';

// Central Auth includes (shared across modules)
require_once '/var/www/html/_common/auth/db.php';
require_once '/var/www/html/_common/auth/url.php';
require_once '/var/www/html/_common/auth/Auth.php'; // CentralAuth class

function pbx_auth_cfg(): array {
  // NOTE: this config is used by the shared auth library (auth_pdo, CentralAuth)
  // If your Auth Center DB name differs, change it here.
  return [
    'db' => [
      'dsn'  => 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4',
      'user' => 'ppdb',
      'pass' => 'abrakadabra',
    ],
  ];
}

/**
 * Sync local session "user" from Auth Center session + RBAC.
 * - If not logged in at Auth Center => unset local user
 * - If logged in but no permission for this module => unset local user
 */
function pbx_sync_user_from_central(): void {
  start_session();
  $cfg = pbx_auth_cfg();

  if (CentralAuth::userId() === null) {
    unset($_SESSION['user']);
    return;
  }

  $slug = (string)config()['module_slug'];
  $role = CentralAuth::roleForModule($cfg, $slug);
  if ($role === null) {
    unset($_SESSION['user']);
    return;
  }

  // Optional: fetch email from auth_db.users (if exists)
  $email = null;
  try {
    $pdo = auth_pdo($cfg);
    $st = $pdo->prepare("SELECT email, full_name FROM users WHERE id=? LIMIT 1");
    $st->execute([CentralAuth::userId()]);
    $row = $st->fetch();
    if ($row) {
      $email = $row['email'] ?? null;
      $_SESSION['full_name'] = $row['full_name'] ?? ($_SESSION['full_name'] ?? '');
    }
  } catch (Throwable $e) {
    // ignore
  }

  $_SESSION['user'] = [
    'id'    => CentralAuth::userId(),
    'name'  => (string)($_SESSION['full_name'] ?? ''),
    'email' => $email,
    'role'  => $role, // 'viewer'|'editor'|'admin'
  ];
}

pbx_sync_user_from_central();

function current_user(): ?array {
  start_session();
  return $_SESSION['user'] ?? null;
}

function require_login(): void {
  if (!current_user()) {
    header('Location: ' . _auth_center_url('/login.php?return=' . urlencode('/apps.php')));
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

// Local login is disabled (uses Auth Center)
function attempt_login(string $username, string $password): bool { return false; }

// Module logout: do nothing (real logout is in Auth Center)
function logout(): void { /* no-op */ }

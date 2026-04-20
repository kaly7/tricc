<?php
declare(strict_types=1);

/**
 * HR Auth adapter:
 * - keeps the original HR Auth API used by controllers/views
 * - delegates authentication to the central Auth Center DB/session
 *
 * IMPORTANT:
 * - Requires /var/www/html/_common/auth/*
 * - Uses CentralAuth class to avoid collision with this HR Auth class name.
 */
class Auth
{
  private array $cfg;

  public function __construct(private Db $db)
  {
    // Central auth config for HR
    $this->cfg = [
      'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4',
        'user' => 'ppdb',
        'pass' => 'abrakadabra', // <-- set to ppdb password (same as in auth_center config)
      ],
      'auth_port'    => 90,
      'session_name' => 'FEJLESZTES_SESSID',
      'module_key'   => 'hr',
    ];

    require_once '/var/www/html/_common/auth/db.php';
    require_once '/var/www/html/_common/auth/url.php';
    require_once '/var/www/html/_common/auth/Auth.php';

    // Ensure shared session is started
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_name($this->cfg['session_name']);
      session_start();
    }
  }

  // --- API used by HR controllers ---

  public function attempt(string $email, string $password): bool
  {
    // HR login form becomes effectively "username/password" for central auth.
    return CentralAuth::login($this->cfg, $email, $password);
  }

  public function check(): bool
  {
    return (CentralAuth::userId() !== null) && ($this->role() !== null);
  }

  public function logout(): void
  {
    CentralAuth::logout();
  }

  public function requireLogin(): void
  {
    if (CentralAuth::userId() === null) {
      $return = $_SERVER['REQUEST_URI'] ?? '/';
      header('Location: ' . auth_login_url($this->cfg, (string)$return));
      exit;
    }
    if ($this->role() === null) {
      http_response_code(403);
      echo "403 - Nincs jogosultság a HR modulhoz.";
      exit;
    }
  }

  public function requireRole(string $role): void
  {
    $this->requireLogin();
    $r = $this->role();
    if ($role === 'admin' && $r !== 'admin') {
      http_response_code(403);
      echo "403 - Admin jogosultság szükséges.";
      exit;
    }
  }

  public function user(): ?array
  {
    if (CentralAuth::userId() === null) return null;
    $role = $this->role();
    if ($role === null) return null;

    return [
      'id'   => CentralAuth::userId(),
      'name' => (string)($_SESSION['full_name'] ?? ''),
      'role' => $role,
    ];
  }

  private function role(): ?string
  {
    return CentralAuth::roleForModule($this->cfg, 'hr');
  }
}

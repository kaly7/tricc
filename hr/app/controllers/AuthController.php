<?php
declare(strict_types=1);

/**
 * HR AuthController (Central Auth integration)
 *
 * Change request:
 * - HR "Kilépés" should NOT log out from Auth Center (central session),
 *   because the user may want to switch to another module.
 *
 * Therefore POST /logout now redirects to Auth Center module selector (apps.php)
 * WITHOUT calling $this->auth->logout(). Real logout stays in Auth Center (/logout.php).
 */
class AuthController
{
  public function __construct(
    private Db $db,
    private View $view,
    private Flash $flash,
    private Csrf $csrf,
    private Auth $auth
  ) {}

  private function hostNoPort(): string {
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return explode(':', $host)[0];
  }

  private function authCenter(string $path): string {
    if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
    return 'http://' . $this->hostNoPort() . ':90' . $path;
  }

  public function showLogin(): void
  {
    header('Location: ' . $this->authCenter('/login.php?return=' . urlencode('/apps.php')));
    exit;
  }

  public function doLogin(): void
  {
    header('Location: ' . $this->authCenter('/login.php?return=' . urlencode('/apps.php')));
    exit;
  }

  public function logout(): void
  {
    // IMPORTANT: keep central session (do not call $this->auth->logout())
    header('Location: ' . $this->authCenter('/apps.php'));
    exit;
  }
}

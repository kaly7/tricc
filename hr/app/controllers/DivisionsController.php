<?php
class DivisionsController
{
  public function __construct(
    private Db $db,
    private View $view,
    private Flash $flash,
    private Csrf $csrf,
    private Auth $auth
  ) {}

  private function requireAdmin(): array
  {
    // HR Auth-ban nincs requireAdmin(), ezért itt ellenőrizzük.
    $this->auth->requireLogin();
    $u = $this->auth->user() ?? [];

    if (($u['role'] ?? '') !== 'admin') {
      $this->flash->set('error', 'Nincs jogosultságod ehhez a művelethez.');
      header('Location: /employees');
      exit;
    }
    return $u;
  }

  public function index(): void
  {
    $user = $this->requireAdmin();

    $q = trim((string)($_GET['q'] ?? ''));
    $where = '';
    $params = [];
    if ($q !== '') {
      $where = "WHERE name LIKE :q";
      $params['q'] = '%' . $q . '%';
    }

    $stmt = $this->db->pdo()->prepare("SELECT * FROM divisions $where ORDER BY is_active DESC, name ASC");
    $stmt->execute($params);
    $divisions = $stmt->fetchAll();

    $this->view->render('layout/header', ['title' => 'Divíziók', 'user' => $user]);
    $this->view->render('divisions/index', [
      'divisions' => $divisions,
      'q' => $q,
      'success' => $this->flash->get('success'),
      'error' => $this->flash->get('error'),
      'csrf' => $this->csrf->token(),
    ]);
    $this->view->render('layout/footer');
  }

  public function create(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /divisions');
      exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
      $this->flash->set('error', 'A divízió neve kötelező.');
      header('Location: /divisions');
      exit;
    }

    try {
      $stmt = $this->db->pdo()->prepare("INSERT INTO divisions (name, is_active) VALUES (:name, 1)");
      $stmt->execute(['name' => $name]);
      $this->flash->set('success', 'Divízió hozzáadva.');
    } catch (PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        $this->flash->set('error', 'Ez a divízió már létezik.');
      } else {
        $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      }
    }

    header('Location: /divisions');
    exit;
  }

  public function update(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /divisions');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));

    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /divisions');
      exit;
    }

    if ($name === '') {
      $this->flash->set('error', 'A divízió neve kötelező.');
      header('Location: /divisions');
      exit;
    }

    try {
      $stmt = $this->db->pdo()->prepare("UPDATE divisions SET name=:name WHERE id=:id");
      $stmt->execute(['name' => $name, 'id' => $id]);
      $this->flash->set('success', 'Divízió átnevezve.');
    } catch (PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        $this->flash->set('error', 'Ez a divízió név már foglalt.');
      } else {
        $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      }
    }

    header('Location: /divisions');
    exit;
  }

  public function toggle(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /divisions');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /divisions');
      exit;
    }

    $stmt = $this->db->pdo()->prepare("UPDATE divisions SET is_active = IF(is_active=1,0,1) WHERE id=:id");
    $stmt->execute(['id' => $id]);

    $this->flash->set('success', 'Állapot módosítva.');
    header('Location: /divisions');
    exit;
  }
}

<?php
class DocTypesController
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

    $stmt = $this->db->pdo()->prepare("SELECT * FROM document_types $where ORDER BY is_active DESC, name ASC");
    $stmt->execute($params);
    $types = $stmt->fetchAll();

    $this->view->render('layout/header', ['title' => 'Dokumentumtípusok', 'user' => $user]);
    $this->view->render('doctypes/index', [
      'types' => $types,
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
      header('Location: /doctypes');
      exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
      $this->flash->set('error', 'A dokumentumtípus neve kötelező.');
      header('Location: /doctypes');
      exit;
    }

    try {
      $stmt = $this->db->pdo()->prepare("INSERT INTO document_types (name, is_active) VALUES (:name, 1)");
      $stmt->execute(['name' => $name]);
      $this->flash->set('success', 'Dokumentumtípus hozzáadva.');
    } catch (PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        $this->flash->set('error', 'Ez a dokumentumtípus már létezik.');
      } else {
        $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      }
    }

    header('Location: /doctypes');
    exit;
  }

  public function toggle(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /doctypes');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /doctypes');
      exit;
    }

    $stmt = $this->db->pdo()->prepare("UPDATE document_types SET is_active = IF(is_active=1,0,1) WHERE id=:id");
    $stmt->execute(['id' => $id]);

    $this->flash->set('success', 'Állapot módosítva.');
    header('Location: /doctypes');
    exit;
  }
}

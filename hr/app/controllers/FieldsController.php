<?php
class FieldsController
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
    // Same pattern as UsersController
    $this->auth->requireRole('admin');
    return $this->auth->user() ?? [];
  }

  public function index(): void
  {
    $user = $this->requireAdmin();

    $stmt = $this->db->pdo()->query("SELECT * FROM employee_fields ORDER BY is_active DESC, name ASC");
    $fields = $stmt->fetchAll();

    $this->view->render('layout/header', ['title' => 'Mezők', 'user' => $user]);
    $this->view->render('fields/index', [
      'fields' => $fields,
      'success' => $this->flash->get('success'),
      'error' => $this->flash->get('error'),
      'csrf' => $this->csrf->token(),
    ]);
    $this->view->render('layout/footer');
  }

  public function showCreate(): void
  {
    $user = $this->requireAdmin();

    $this->view->render('layout/header', ['title' => 'Új mező', 'user' => $user]);
    $this->view->render('fields/create', [
      'csrf' => $this->csrf->token(),
      'old' => $this->flash->get('old') ?? [],
      'error' => $this->flash->get('error'),
    ]);
    $this->view->render('layout/footer');
  }

  public function create(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /fields');
      exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $key  = trim((string)($_POST['field_key'] ?? ''));
    $type = trim((string)($_POST['field_type'] ?? 'text'));
    $optionsText = trim((string)($_POST['options'] ?? ''));

    if ($name === '' || $key === '') {
      $this->flash->set('error', 'A név és a kulcs kötelező.');
      $this->flash->set('old', $_POST);
      header('Location: /fields_create');
      exit;
    }

    $allowed = ['text','textarea','select','multiselect','date','number'];
    if (!in_array($type, $allowed, true)) $type = 'text';

    $optionsJson = null;
    if (in_array($type, ['select','multiselect'], true)) {
      $arr = array_values(array_filter(array_map('trim', preg_split('/\R+/', $optionsText)), fn($x)=>$x!==''));
      $optionsJson = json_encode($arr, JSON_UNESCAPED_UNICODE);
    }

    try {
      $stmt = $this->db->pdo()->prepare("
        INSERT INTO employee_fields (name, field_key, field_type, options, is_active)
        VALUES (:name, :k, :t, :o, 1)
      ");
      $stmt->execute(['name'=>$name,'k'=>$key,'t'=>$type,'o'=>$optionsJson]);

      $this->flash->set('success', 'Mező létrehozva.');
      header('Location: /fields');
      exit;
    } catch (PDOException $e) {
      $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      $this->flash->set('old', $_POST);
      header('Location: /fields_create');
      exit;
    }
  }

  public function showEdit(): void
  {
    $user = $this->requireAdmin();

    $id = (int)($_GET['id'] ?? 0);
    $stmt = $this->db->pdo()->prepare("SELECT * FROM employee_fields WHERE id=:id");
    $stmt->execute(['id'=>$id]);
    $field = $stmt->fetch();

    if (!$field) {
      header('Location: /fields');
      exit;
    }

    $this->view->render('layout/header', ['title' => 'Mező szerkesztése', 'user' => $user]);
    $this->view->render('fields/edit', [
      'field' => $field,
      'csrf' => $this->csrf->token(),
      'error' => $this->flash->get('error'),
    ]);
    $this->view->render('layout/footer');
  }

  public function edit(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /fields');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $key  = trim((string)($_POST['field_key'] ?? ''));
    $type = trim((string)($_POST['field_type'] ?? 'text'));
    $isActive = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;
    $optionsText = trim((string)($_POST['options'] ?? ''));

    if ($id <= 0 || $name === '' || $key === '') {
      $this->flash->set('error', 'Hibás adatok.');
      header('Location: /fields_edit?id=' . $id);
      exit;
    }

    $allowed = ['text','textarea','select','multiselect','date','number'];
    if (!in_array($type, $allowed, true)) $type = 'text';

    $optionsJson = null;
    if (in_array($type, ['select','multiselect'], true)) {
      $arr = array_values(array_filter(array_map('trim', preg_split('/\R+/', $optionsText)), fn($x)=>$x!==''));
      $optionsJson = json_encode($arr, JSON_UNESCAPED_UNICODE);
    }

    try {
      $stmt = $this->db->pdo()->prepare("
        UPDATE employee_fields
           SET name=:name, field_key=:k, field_type=:t, options=:o, is_active=:a
         WHERE id=:id
      ");
      $stmt->execute(['id'=>$id,'name'=>$name,'k'=>$key,'t'=>$type,'o'=>$optionsJson,'a'=>$isActive]);

      $this->flash->set('success', 'Mező mentve.');
      header('Location: /fields');
      exit;
    } catch (PDOException $e) {
      $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      header('Location: /fields_edit?id=' . $id);
      exit;
    }
  }

  public function toggle(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /fields');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $this->db->pdo()->prepare("UPDATE employee_fields SET is_active = IF(is_active=1,0,1) WHERE id=:id");
      $stmt->execute(['id'=>$id]);
    }

    header('Location: /fields');
    exit;
  }
}

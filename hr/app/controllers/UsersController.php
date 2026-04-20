<?php
class UsersController
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
    $this->auth->requireRole('admin');
    return $this->auth->user() ?? [];
  }

  public function index(): void
  {
    $user = $this->requireAdmin();

    $q = trim((string)($_GET['q'] ?? ''));
    $params = [];
    $where = '';
    if ($q !== '') {
      $where = "WHERE name LIKE :q OR email LIKE :q";
      $params['q'] = '%' . $q . '%';
    }

    $stmt = $this->db->pdo()->prepare("
      SELECT id, name, email, role, is_active, last_login_at, created_at
      FROM users
      $where
      ORDER BY id DESC
      LIMIT 500
    ");
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $msg = $this->flash->get('success');
    $err = $this->flash->get('error');

    $this->view->render('layout/header', ['title' => 'Felhasználók', 'user' => $user]);
    $this->view->render('users/index', [
      'users' => $users,
      'q' => $q,
      'success' => $msg,
      'error' => $err,
      'csrf' => $this->csrf->token(),
    ]);
    $this->view->render('layout/footer');
  }

  public function showCreate(): void
  {
    $user = $this->requireAdmin();

    $err = $this->flash->get('error');
    $old = $_SESSION['_old'] ?? [];
    unset($_SESSION['_old']);

    $this->view->render('layout/header', ['title' => 'Új felhasználó', 'user' => $user]);
    $this->view->render('users/create', [
      'error' => $err,
      'old' => $old,
      'csrf' => $this->csrf->token(),
    ]);
    $this->view->render('layout/footer');
  }

  public function create(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /users');
      exit;
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $role = (string)($_POST['role'] ?? 'user');
    $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
    $pw1 = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    $_SESSION['_old'] = ['name' => $name, 'email' => $email, 'role' => $role, 'is_active' => $isActive];

    if ($name === '' || $email === '') {
      $this->flash->set('error', 'Név és email kötelező.');
      header('Location: /users_create');
      exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $this->flash->set('error', 'Érvénytelen email cím.');
      header('Location: /users_create');
      exit;
    }
    if (!in_array($role, ['admin','user'], true)) {
      $this->flash->set('error', 'Érvénytelen szerepkör.');
      header('Location: /users_create');
      exit;
    }
    if (strlen($pw1) < 8) {
      $this->flash->set('error', 'A jelszó legalább 8 karakter legyen.');
      header('Location: /users_create');
      exit;
    }
    if ($pw1 !== $pw2) {
      $this->flash->set('error', 'A két jelszó nem egyezik.');
      header('Location: /users_create');
      exit;
    }

    try {
      $hash = password_hash($pw1, PASSWORD_DEFAULT);

      $stmt = $this->db->pdo()->prepare("
        INSERT INTO users (name, email, password_hash, role, is_active)
        VALUES (:name, :email, :ph, :role, :ia)
      ");
      $stmt->execute([
        'name' => $name,
        'email' => $email,
        'ph' => $hash,
        'role' => $role,
        'ia' => $isActive,
      ]);

      unset($_SESSION['_old']);
      $this->flash->set('success', 'Felhasználó létrehozva.');
      header('Location: /users');
      exit;

    } catch (PDOException $e) {
      // Duplicate email
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        $this->flash->set('error', 'Ez az email már foglalt.');
        header('Location: /users_create');
        exit;
      }
      $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      header('Location: /users_create');
      exit;
    }
  }

  public function showEdit(): void
  {
    $user = $this->requireAdmin();

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /users');
      exit;
    }

    $stmt = $this->db->pdo()->prepare("SELECT id, name, email, role, is_active, last_login_at, created_at FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $editUser = $stmt->fetch();

    if (!$editUser) {
      $this->flash->set('error', 'A felhasználó nem található.');
      header('Location: /users');
      exit;
    }

    $err = $this->flash->get('error');
    $msg = $this->flash->get('success');

    $this->view->render('layout/header', ['title' => 'Felhasználó szerkesztése', 'user' => $user]);
    $this->view->render('users/edit', [
      'editUser' => $editUser,
      'error' => $err,
      'success' => $msg,
      'csrf' => $this->csrf->token(),
    ]);
    $this->view->render('layout/footer');
  }

  public function update(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /users');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $role = (string)($_POST['role'] ?? 'user');
    $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    $pw1 = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /users');
      exit;
    }

    if ($name === '' || $email === '') {
      $this->flash->set('error', 'Név és email kötelező.');
      header('Location: /users_edit?id=' . $id);
      exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $this->flash->set('error', 'Érvénytelen email cím.');
      header('Location: /users_edit?id=' . $id);
      exit;
    }
    if (!in_array($role, ['admin','user'], true)) {
      $this->flash->set('error', 'Érvénytelen szerepkör.');
      header('Location: /users_edit?id=' . $id);
      exit;
    }

    try {
      // Update basic fields
      $stmt = $this->db->pdo()->prepare("
        UPDATE users
        SET name=:name, email=:email, role=:role, is_active=:ia
        WHERE id=:id
      ");
      $stmt->execute([
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'ia' => $isActive,
        'id' => $id,
      ]);

      // Update password if provided
      if ($pw1 !== '' || $pw2 !== '') {
        if (strlen($pw1) < 8) {
          $this->flash->set('error', 'A jelszó legalább 8 karakter legyen.');
          header('Location: /users_edit?id=' . $id);
          exit;
        }
        if ($pw1 !== $pw2) {
          $this->flash->set('error', 'A két jelszó nem egyezik.');
          header('Location: /users_edit?id=' . $id);
          exit;
        }
        $hash = password_hash($pw1, PASSWORD_DEFAULT);
        $stmt = $this->db->pdo()->prepare("UPDATE users SET password_hash=:ph WHERE id=:id");
        $stmt->execute(['ph' => $hash, 'id' => $id]);
      }

      $this->flash->set('success', 'Mentve.');
      header('Location: /users_edit?id=' . $id);
      exit;

    } catch (PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        $this->flash->set('error', 'Ez az email már foglalt.');
        header('Location: /users_edit?id=' . $id);
        exit;
      }
      $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      header('Location: /users_edit?id=' . $id);
      exit;
    }
  }

  public function toggleActive(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /users');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /users');
      exit;
    }

    // prevent deactivating self (common footgun)
    $me = $this->auth->user();
    if ($me && (int)($me['id'] ?? 0) === $id) {
      $this->flash->set('error', 'A saját fiókodat nem tudod letiltani.');
      header('Location: /users');
      exit;
    }

    $stmt = $this->db->pdo()->prepare("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id=:id");
    $stmt->execute(['id' => $id]);

    $this->flash->set('success', 'Állapot módosítva.');
    header('Location: /users');
    exit;
  }
}

<?php
declare(strict_types=1);

class HrPermissionsController
{
  private const AUTH_DB_DSN  = 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4';
  private const AUTH_DB_USER = 'ppdb';
  private const AUTH_DB_PASS = 'abrakadabra';

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

  private function authPdo(): PDO
  {
    return new PDO(self::AUTH_DB_DSN, self::AUTH_DB_USER, self::AUTH_DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }

  /** Lista: HR modul felhasználói + jogosultság státuszuk */
  public function index(): void
  {
    $user = $this->requireAdmin();

    $apdo  = $this->authPdo();

    // Lekérjük az összes HR-hozzáféréssel rendelkező usert
    $hrUsers = $apdo->query("
      SELECT u.id, u.username, u.full_name, u.is_active
      FROM users u
      JOIN user_module_roles umr ON umr.user_id = u.id
      JOIN modules m ON m.id = umr.module_id AND m.module_key = 'hr'
      ORDER BY u.full_name ASC
    ")->fetchAll();

    // Meglévő hr_permissions rekordok
    $perms = [];
    if ($hrUsers) {
      $ids  = array_column($hrUsers, 'id');
      $ph   = implode(',', array_fill(0, count($ids), '?'));
      $stmt = $this->db->pdo()->prepare(
        "SELECT user_id FROM hr_permissions WHERE user_id IN ($ph)"
      );
      $stmt->execute($ids);
      foreach ($stmt->fetchAll() as $r) {
        $perms[(int)$r['user_id']] = true;
      }
    }

    $this->view->render('layout/header', ['title' => 'HR jogosultságok', 'user' => $user]);
    $this->view->render('hr_permissions/index', [
      'hrUsers'  => $hrUsers,
      'perms'    => $perms,
      'success'  => $this->flash->get('success'),
      'error'    => $this->flash->get('error'),
    ]);
    $this->view->render('layout/footer');
  }

  /** Szerkesztő form: egy user HR jogosultságai */
  public function showEdit(): void
  {
    $user   = $this->requireAdmin();
    $userId = (int)($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
      header('Location: /hr_permissions'); exit;
    }

    $apdo    = $this->authPdo();
    $authUser = $apdo->prepare("SELECT id, username, full_name FROM users WHERE id=? LIMIT 1");
    $authUser->execute([$userId]);
    $authUser = $authUser->fetch();
    if (!$authUser) {
      $this->flash->set('error', 'Felhasználó nem található.');
      header('Location: /hr_permissions'); exit;
    }

    // Meglévő perm rekord
    $pdo  = $this->db->pdo();
    $perm = $pdo->prepare("SELECT id FROM hr_permissions WHERE user_id=? LIMIT 1");
    $perm->execute([$userId]);
    $perm = $perm->fetch();
    $permId = $perm ? (int)$perm['id'] : null;

    // Meglévő selections
    $selDivisions   = [];
    $selFields      = [];
    $selExtraFields = [];

    if ($permId !== null) {
      $r1 = $pdo->prepare("SELECT division_id    FROM hr_perm_divisions    WHERE perm_id=?");
      $r2 = $pdo->prepare("SELECT field_key      FROM hr_perm_fields       WHERE perm_id=?");
      $r3 = $pdo->prepare("SELECT extra_field_id FROM hr_perm_extra_fields WHERE perm_id=?");
      $r1->execute([$permId]); $selDivisions   = array_map('intval',  array_column($r1->fetchAll(), 'division_id'));
      $r2->execute([$permId]); $selFields      =                       array_column($r2->fetchAll(), 'field_key');
      $r3->execute([$permId]); $selExtraFields = array_map('intval',  array_column($r3->fetchAll(), 'extra_field_id'));
    }

    // Összes divízió
    $divisions   = $pdo->query("SELECT id, name FROM divisions ORDER BY name ASC")->fetchAll();
    // Összes extra mező
    $extraFields = $pdo->query("SELECT id, name FROM employee_fields WHERE is_active=1 ORDER BY name ASC")->fetchAll();

    $this->view->render('layout/header', ['title' => 'HR jogosultság szerkesztése', 'user' => $user]);
    $this->view->render('hr_permissions/edit', [
      'authUser'       => $authUser,
      'permId'         => $permId,
      'divisions'      => $divisions,
      'extraFields'    => $extraFields,
      'staticFields'   => HrPermission::STATIC_FIELDS,
      'selDivisions'   => $selDivisions,
      'selFields'      => $selFields,
      'selExtraFields' => $selExtraFields,
      'csrf'           => $this->csrf->token(),
      'success'        => $this->flash->get('success'),
      'error'          => $this->flash->get('error'),
    ]);
    $this->view->render('layout/footer');
  }

  /** Mentés (POST) */
  public function save(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      http_response_code(400); echo 'Érvénytelen kérés.'; exit;
    }

    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
      header('Location: /hr_permissions'); exit;
    }

    // Auth_db-ből ellenőrizzük, hogy létezik a user
    $apdo = $this->authPdo();
    $au   = $apdo->prepare("SELECT id, full_name FROM users WHERE id=? LIMIT 1");
    $au->execute([$userId]);
    $au = $au->fetch();
    if (!$au) {
      $this->flash->set('error', 'Felhasználó nem található.');
      header('Location: /hr_permissions'); exit;
    }

    $userName       = (string)$au['full_name'];
    $divIds         = array_map('intval',  (array)($_POST['divisions'] ?? []));
    $fieldKeys      = array_filter(array_map('strval', (array)($_POST['fields'] ?? [])));
    $extraFieldIds  = array_map('intval',  (array)($_POST['extra_fields'] ?? []));

    // Whitelist: csak valós mezőkulcsok mehetnek be
    $allStaticKeys = array_keys(array_merge(...array_values(HrPermission::STATIC_FIELDS)));
    $fieldKeys = array_values(array_intersect($fieldKeys, $allStaticKeys));

    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      // Upsert hr_permissions
      $pdo->prepare("
        INSERT INTO hr_permissions (user_id, user_name)
        VALUES (:uid, :uname)
        ON DUPLICATE KEY UPDATE user_name=VALUES(user_name), updated_at=CURRENT_TIMESTAMP
      ")->execute(['uid' => $userId, 'uname' => $userName]);

      $permStmt = $pdo->prepare("SELECT id FROM hr_permissions WHERE user_id=? LIMIT 1");
      $permStmt->execute([$userId]);
      $permId = (int)$permStmt->fetch()['id'];

      // Divíziók: töröl + újra insert
      $pdo->prepare("DELETE FROM hr_perm_divisions    WHERE perm_id=?")->execute([$permId]);
      $pdo->prepare("DELETE FROM hr_perm_fields       WHERE perm_id=?")->execute([$permId]);
      $pdo->prepare("DELETE FROM hr_perm_extra_fields WHERE perm_id=?")->execute([$permId]);

      foreach ($divIds as $did) {
        if ($did > 0) {
          $pdo->prepare("INSERT IGNORE INTO hr_perm_divisions (perm_id, division_id) VALUES (?,?)")
              ->execute([$permId, $did]);
        }
      }
      foreach ($fieldKeys as $fk) {
        $pdo->prepare("INSERT IGNORE INTO hr_perm_fields (perm_id, field_key) VALUES (?,?)")
            ->execute([$permId, $fk]);
      }
      foreach ($extraFieldIds as $efid) {
        if ($efid > 0) {
          $pdo->prepare("INSERT IGNORE INTO hr_perm_extra_fields (perm_id, extra_field_id) VALUES (?,?)")
              ->execute([$permId, $efid]);
        }
      }

      $pdo->commit();
      $this->flash->set('success', 'Jogosultságok mentve.');
    } catch (PDOException $e) {
      $pdo->rollBack();
      $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
    }

    header('Location: /hr_permissions_edit?user_id=' . $userId); exit;
  }

  /** Jogosultság rekord törlése (user elveszíti az összes HR hozzáférést) */
  public function delete(): void
  {
    $this->requireAdmin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      http_response_code(400); echo 'Érvénytelen kérés.'; exit;
    }

    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
      header('Location: /hr_permissions'); exit;
    }

    $pdo = $this->db->pdo();
    try {
      $stmt = $pdo->prepare("SELECT id FROM hr_permissions WHERE user_id=? LIMIT 1");
      $stmt->execute([$userId]);
      $rec = $stmt->fetch();
      if ($rec) {
        $permId = (int)$rec['id'];
        $pdo->prepare("DELETE FROM hr_perm_divisions    WHERE perm_id=?")->execute([$permId]);
        $pdo->prepare("DELETE FROM hr_perm_fields       WHERE perm_id=?")->execute([$permId]);
        $pdo->prepare("DELETE FROM hr_perm_extra_fields WHERE perm_id=?")->execute([$permId]);
        $pdo->prepare("DELETE FROM hr_permissions WHERE id=?")->execute([$permId]);
      }
      $this->flash->set('success', 'Jogosultság rekord törölve.');
    } catch (PDOException $e) {
      $this->flash->set('error', 'Törlési hiba: ' . $e->getMessage());
    }

    header('Location: /hr_permissions'); exit;
  }

  /** Audit napló megtekintése – lapozás + szűrők */
  public function auditLog(): void
  {
    $user = $this->requireAdmin();

    $perPage   = 50;
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $offset    = ($page - 1) * $perPage;

    // Szűrők
    $fUser    = trim((string)($_GET['f_user']    ?? ''));
    $fEmp     = trim((string)($_GET['f_emp']     ?? ''));
    $fAction  = trim((string)($_GET['f_action']  ?? ''));
    $fDateFrom= trim((string)($_GET['f_date_from']?? ''));
    $fDateTo  = trim((string)($_GET['f_date_to']  ?? ''));

    $where  = [];
    $params = [];

    if ($fUser !== '') {
      $where[]          = 'a.user_name LIKE :uname';
      $params['uname']  = '%' . $fUser . '%';
    }
    if ($fEmp !== '') {
      $where[]          = 'e.full_name LIKE :empname';
      $params['empname']= '%' . $fEmp . '%';
    }
    if ($fAction !== '') {
      $where[]          = 'a.action = :action';
      $params['action'] = $fAction;
    }
    if ($fDateFrom !== '') {
      $where[]             = 'DATE(a.created_at) >= :dfrom';
      $params['dfrom']     = $fDateFrom;
    }
    if ($fDateTo !== '') {
      $where[]             = 'DATE(a.created_at) <= :dto';
      $params['dto']       = $fDateTo;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $pdo = $this->db->pdo();

    // Összes találat száma (lapozáshoz)
    $cntStmt = $pdo->prepare("
      SELECT COUNT(*) FROM hr_audit_log a
      LEFT JOIN employees e ON e.id = a.employee_id
      $whereSql
    ");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    $rowStmt = $pdo->prepare("
      SELECT a.*, e.full_name AS emp_name
      FROM hr_audit_log a
      LEFT JOIN employees e ON e.id = a.employee_id
      $whereSql
      ORDER BY a.id DESC
      LIMIT $perPage OFFSET $offset
    ");
    $rowStmt->execute($params);
    $rows = $rowStmt->fetchAll();

    $this->view->render('layout/header', ['title' => 'Audit napló', 'user' => $user]);
    $this->view->render('hr_permissions/audit', [
      'rows'       => $rows,
      'total'      => $total,
      'page'       => $page,
      'perPage'    => $perPage,
      'totalPages' => $totalPages,
      'f_user'     => $fUser,
      'f_emp'      => $fEmp,
      'f_action'   => $fAction,
      'f_date_from'=> $fDateFrom,
      'f_date_to'  => $fDateTo,
    ]);
    $this->view->render('layout/footer');
  }
}

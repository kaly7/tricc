<?php
class EmployeesController
{
  private const EXTRA_FIELDS_DEBUG = true;

  public function __construct(
    private Db $db,
    private View $view,
    private Flash $flash,
    private Csrf $csrf,
    private Auth $auth
  ) {}

  private function requireLogin(): array
  {
    $this->auth->requireLogin();
    return $this->auth->user() ?? [];
  }

  private function getDivisions(): array
  {
    $stmt = $this->db->pdo()->query("SELECT id, name FROM divisions WHERE is_active=1 ORDER BY name ASC");
    return $stmt->fetchAll();
  }

  private function getDocTypes(): array
  {
    // Active document types for employee upload widget
    $stmt = $this->db->pdo()->query("SELECT id, name FROM document_types WHERE is_active=1 ORDER BY name ASC");
    return $stmt->fetchAll();
  }

  /**
   * Profile image upload
   * - saves under /public/uploads/profile
   * - returns web path like /uploads/profile/xxx.jpg
   */
  private function handleProfileUpload(?array $file, ?string $currentPath = null): ?string
  {
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
      return $currentPath;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Fájl feltöltési hiba (PHP): ' . (int)$file['error']);
    }

    // 25MB (as per project default)
    $max = 25 * 1024 * 1024;
    if (($file['size'] ?? 0) > $max) {
      throw new RuntimeException('A kép túl nagy (max 25MB).');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
      throw new RuntimeException('A feltöltött fájl ideiglenes helye érvénytelen.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmp) : null;
    if ($finfo) finfo_close($finfo);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$mime || !isset($allowed[$mime])) {
      throw new RuntimeException('Csak JPG/PNG/WEBP kép tölthető fel.');
    }

    $ext = $allowed[$mime];

    // IMPORTANT: writable directory under public
    $dir = APP_ROOT . '/public/uploads/profile';
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nem lehet létrehozni a feltöltési könyvtárat: ' . $dir);
      }
    }

    if (!is_writable($dir)) {
      throw new RuntimeException('A feltöltési könyvtár nem írható a webszerver számára: ' . $dir);
    }

    $name = 'p_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;

    if (!@move_uploaded_file($tmp, $dest)) {
      $err = error_get_last();
      $msg = $err['message'] ?? 'ismeretlen ok';
      throw new RuntimeException('Nem sikerült elmenteni a feltöltött képet: ' . $msg);
    }

    // Normalize perms (best-effort)
    @chmod($dest, 0644);

    return '/uploads/profile/' . $name;
  }

  /** Jogosultság betöltése, nincs-hozzáférés kezelés */
  private function loadPerm(array $user): ?array
  {
    $isAdmin = ($user['role'] ?? '') === 'admin';
    if ($isAdmin) return null; // null = admin, minden megengedett

    $perm = HrPermission::load($this->db, (int)$user['id']);
    if ($perm === null) {
      http_response_code(403);
      $this->view->render('layout/header', ['title' => 'Nincs hozzáférés', 'user' => $user]);
      echo '<div class="alert alert-danger">Nincs HR-hozzáférésed. Kérd meg az adminisztrátort, hogy állítsa be a jogosultságodat.</div>';
      $this->view->render('layout/footer');
      exit;
    }
    return $perm;
  }

  public function index(): void
  {
    $user = $this->requireLogin();

    $q = trim((string)($_GET['q'] ?? ''));
    $showInactive = (int)($_GET['show_inactive'] ?? 0) === 1;

    // Sorting (whitelist) - toggle via table header links
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $perm    = $this->loadPerm($user);

    $sort = (string)($_GET['sort'] ?? '');
    $dir  = strtolower((string)($_GET['dir'] ?? 'asc'));
    if (!in_array($dir, ['asc','desc'], true)) $dir = 'asc';

    $sort_whitelist = [
      'name' => 'e.full_name',
      'division' => 'd.name',
    ];
    $sortKey = $sort !== '' ? $sort : 'name';
    if (!isset($sort_whitelist[$sortKey])) $sortKey = 'name';

    $orderSql = $sort_whitelist[$sortKey] . ' ' . strtoupper($dir) . ', e.id DESC';
    $whereParts = [];
    $params = [];

    if (!$showInactive) {
      $whereParts[] = "e.is_active = 1";
    }

    // Nem-admin: csak az engedélyezett divíziók látszanak
    if ($perm !== null) {
      if (empty($perm['divisions'])) {
        $whereParts[] = "1=0"; // nincs divízió beállítva → üres lista
      } else {
        $ph = implode(',', array_fill(0, count($perm['divisions']), '?'));
        $whereParts[] = "COALESCE(e.division_id, 0) IN ($ph)";
        foreach ($perm['divisions'] as $did) $params[] = $did;
      }
    }

    if ($q !== '') {
      $whereParts[] = "(
        e.full_name LIKE :q OR
        e.birth_name LIKE :q OR
        e.mother_name LIKE :q OR
        e.birth_place LIKE :q OR
        e.tax_id LIKE :q OR
        e.company_emp_no LIKE :q
      )";
      $params['q'] = '%' . $q . '%';
    }

    $where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    $stmt = $this->db->pdo()->prepare("
      SELECT e.*, d.name AS division_name,
             COALESCE(ds.expired_count, 0) AS expired_doc_count,
             COALESCE(ds.expiring_count, 0) AS expiring_doc_count
      FROM employees e
      LEFT JOIN divisions d ON d.id = e.division_id
      LEFT JOIN (
        SELECT employee_id,
               SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
               SUM(CASE WHEN expires_at IS NOT NULL AND expires_at >= CURDATE() AND expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS expiring_count
        FROM employee_documents
        GROUP BY employee_id
      ) ds ON ds.employee_id = e.id
      $where
      ORDER BY $orderSql
      LIMIT 500
    ");
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    $this->view->render('layout/header', ['title' => 'Dolgozók', 'user' => $user]);
    $this->view->render('employees/index', [
      'employees' => $employees,
      'q' => $q,
      'success' => $this->flash->get('success'),
      'error' => $this->flash->get('error'),
      'csrf' => $this->csrf->token(),
      'sort' => $sortKey,
      'dir' => $dir,
      'show_inactive' => $showInactive,
      'is_admin' => (($user['role'] ?? '') === 'admin'),
    ]);
    $this->view->render('layout/footer');
  }

  public function showCreate(): void
  {
    $user = $this->requireLogin();
    $this->auth->requireRole('admin');
    $sortKey = (string)($_GET['sort'] ?? 'name');
    $dir = strtolower((string)($_GET['dir'] ?? 'desc'));
    if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';
    $showInactive = (int)($_GET['show_inactive'] ?? 0) === 1;

    $old = $_SESSION['_old'] ?? [];
    unset($_SESSION['_old']);

    $this->view->render('layout/header', ['title' => 'Új dolgozó', 'user' => $user]);
    $this->view->render('employees/create', [
      'fields' => $this->getActiveExtraFields(),

      'error' => $this->flash->get('error'),
      'old' => $old,
      'divisions' => $this->getDivisions(),
      'csrf' => $this->csrf->token(),
      'sort' => $sortKey ?? 'name',
      'dir' => $dir ?? 'desc',
      'show_inactive' => $showInactive,
      'is_admin' => (($user['role'] ?? '') === 'admin'),
    ]);
    $this->view->render('layout/footer');
  }

  public function create(): void
  {
    $this->requireLogin();
    $this->auth->requireRole('admin');

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /employees');
      exit;
    }

    $data = [
      'full_name' => trim((string)($_POST['full_name'] ?? '')),
      'birth_name' => trim((string)($_POST['birth_name'] ?? '')) ?: null,
      'mother_name' => trim((string)($_POST['mother_name'] ?? '')) ?: null,
      'birth_place' => trim((string)($_POST['birth_place'] ?? '')) ?: null,
      'birth_date' => trim((string)($_POST['birth_date'] ?? '')) ?: null,

      'tax_id' => trim((string)($_POST['tax_id'] ?? '')) ?: null,
      'taj' => trim((string)($_POST['taj'] ?? '')) ?: null,
      'company_emp_no' => trim((string)($_POST['company_emp_no'] ?? '')) ?: null,
      'bank_account' => trim((string)($_POST['bank_account'] ?? '')) ?: null,
      'bank_name' => trim((string)($_POST['bank_name'] ?? '')) ?: null,

      'division_id' => (int)($_POST['division_id'] ?? 0),
      'addr_zip' => trim((string)($_POST['addr_zip'] ?? '')) ?: null,
      'addr_city' => trim((string)($_POST['addr_city'] ?? '')) ?: null,
      'addr_line' => trim((string)($_POST['addr_line'] ?? '')) ?: null,

      'email' => trim((string)($_POST['email'] ?? '')) ?: null,
      'phone' => trim((string)($_POST['phone'] ?? '')) ?: null,
      'notes' => trim((string)($_POST['notes'] ?? '')) ?: null,

      'is_active' => (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0,
      'hired_on' => trim((string)($_POST['hired_on'] ?? '')) ?: null,
      'left_on'  => trim((string)($_POST['left_on']  ?? '')) ?: null,
    ];

    $_SESSION['_old'] = $data;

    if ($data['full_name'] === '') {
      $this->flash->set('error', 'A név kötelező.');
      header('Location: /employees_create');
      exit;
    }

    if ($data['birth_date'] !== null && $data['birth_date'] !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $data['birth_date'])) {
      $this->flash->set('error', 'A születési dátum formátuma: ÉÉÉÉ-HH-NN.');
      header('Location: /employees_create');
      exit;
    }

    if ($data['division_id'] <= 0) $data['division_id'] = null;

    try {
      $profilePath = $this->handleProfileUpload($_FILES['profile_image'] ?? null, null);

      $stmt = $this->db->pdo()->prepare("
        INSERT INTO employees
          (full_name, birth_name, mother_name, birth_place, birth_date,
           tax_id, taj, company_emp_no, bank_account, bank_name, division_id,
           addr_zip, addr_city, addr_line,
           email, phone, notes,
           profile_image_path, is_active, hired_on, left_on)
        VALUES
          (:full_name, :birth_name, :mother_name, :birth_place, :birth_date,
           :tax_id, :taj, :company_emp_no, :bank_account, :bank_name, :division_id,
           :addr_zip, :addr_city, :addr_line,
           :email, :phone, :notes,
           :profile_image_path, :is_active, :hired_on, :left_on)
      ");
      $stmt->execute(array_merge($data, ['profile_image_path' => $profilePath]));

      $newId = (int)$this->db->pdo()->lastInsertId();
      // Save dynamic extra fields (debug)
      $this->saveEmployeeExtraValues($newId, (array)($_POST['field'] ?? []), (array)($_POST['show'] ?? []));
      if (self::EXTRA_FIELDS_DEBUG) error_log('[HR] extra fields saved for new employeeId=' . $newId);

      unset($_SESSION['_old']);
      $this->flash->set('success', 'Dolgozó létrehozva.');
      header('Location: /employees');
      exit;

    } catch (PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        $this->flash->set('error', 'Ez a céges törzsszám már foglalt (company_emp_no).');
        header('Location: /employees_create');
        exit;
      }
      $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      header('Location: /employees_create');
      exit;
    } catch (RuntimeException $e) {
      $this->flash->set('error', $e->getMessage());
      header('Location: /employees_create');
      exit;
    }
  }

  public function showView(): void
  {
    $user    = $this->requireLogin();
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $perm    = $this->loadPerm($user);

    $sortKey = (string)($_GET['sort'] ?? 'name');
    $dir = strtolower((string)($_GET['dir'] ?? 'desc'));
    if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';
    $showInactive = (int)($_GET['show_inactive'] ?? 0) === 1;

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /employees');
      exit;
    }

    $stmt = $this->db->pdo()->prepare("
      SELECT e.*, d.name AS division_name
      FROM employees e
      LEFT JOIN divisions d ON d.id = e.division_id
      WHERE e.id=:id
      LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $emp = $stmt->fetch();

    if (!$emp) {
      $this->flash->set('error', 'A dolgozó nem található.');
      header('Location: /employees');
      exit;
    }

    // Nem-admin: divízió ellenőrzés
    if ($perm !== null && !in_array((int)($emp['division_id'] ?? 0), $perm['divisions'], true)) {
      http_response_code(403);
      $this->flash->set('error', 'Nincs jogosultságod ehhez a dolgozóhoz.');
      header('Location: /employees');
      exit;
    }

    // Documents for employee card
    $stmt = $this->db->pdo()->prepare(
      "SELECT d.*, t.name AS type_name
       FROM employee_documents d
       JOIN document_types t ON t.id = d.document_type_id
       WHERE d.employee_id = :id
       ORDER BY d.created_at DESC, d.id DESC
       LIMIT 200"
    );
    $stmt->execute(['id' => $id]);
    $docs = $stmt->fetchAll();

    // Audit
    HrPermission::audit($this->db, (int)$user['id'], $user['name'], 'employee_view', $id);

    $canSee      = HrPermission::fieldChecker($perm);
    $canSeeExtra = HrPermission::extraFieldChecker($perm);

    $this->view->render('layout/header', ['title' => 'Dolgozó', 'user' => $user]);
    $this->view->render('employees/view', [
      'emp'          => $emp,
      'fields'       => $this->getActiveExtraFields(),
      'field_values' => $this->getEmployeeExtraValues((int)$emp['id']),
      'docs'         => $docs ?? [],
      'csrf'         => $this->csrf->token(),
      'sort'         => $sortKey ?? 'name',
      'dir'          => $dir ?? 'desc',
      'show_inactive'=> $showInactive,
      'is_admin'     => $isAdmin,
      'canSee'       => $canSee,
      'canSeeExtra'  => $canSeeExtra,
    ]);
    $this->view->render('layout/footer');
  }

  public function showEdit(): void
  {
    $user    = $this->requireLogin();
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $perm    = $this->loadPerm($user);

    $sortKey = (string)($_GET['sort'] ?? 'name');
    $dir = strtolower((string)($_GET['dir'] ?? 'desc'));
    if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';
    $showInactive = (int)($_GET['show_inactive'] ?? 0) === 1;

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /employees');
      exit;
    }

    $stmt = $this->db->pdo()->prepare("SELECT * FROM employees WHERE id=:id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $emp = $stmt->fetch();

    if (!$emp) {
      $this->flash->set('error', 'A dolgozó nem található.');
      header('Location: /employees');
      exit;
    }

    // Nem-admin: divízió ellenőrzés
    if ($perm !== null && !in_array((int)($emp['division_id'] ?? 0), $perm['divisions'], true)) {
      http_response_code(403);
      $this->flash->set('error', 'Nincs jogosultságod ehhez a dolgozóhoz.');
      header('Location: /employees');
      exit;
    }

    // Documents for employee edit page
    $stmt = $this->db->pdo()->prepare(
      "SELECT d.*, t.name AS type_name
       FROM employee_documents d
       JOIN document_types t ON t.id = d.document_type_id
       WHERE d.employee_id = :id
       ORDER BY d.created_at DESC, d.id DESC
       LIMIT 200"
    );
    $stmt->execute(['id' => $id]);
    $docs = $stmt->fetchAll();

    $docTypes = $this->getDocTypes();

    $canSee      = HrPermission::fieldChecker($perm);
    $canSeeExtra = HrPermission::extraFieldChecker($perm);

    $this->view->render('layout/header', ['title' => 'Dolgozó szerkesztése', 'user' => $user]);
    $this->view->render('employees/edit', [
      'emp'          => $emp,
      'fields'       => $this->getActiveExtraFields(),
      'field_values' => $this->getEmployeeExtraValues((int)$emp['id']),
      'divisions'    => $this->getDivisions(),
      'docTypes'     => $docTypes ?? [],
      'docs'         => $docs ?? [],
      'success'      => $this->flash->get('success'),
      'error'        => $this->flash->get('error'),
      'csrf'         => $this->csrf->token(),
      'sort'         => $sortKey ?? 'name',
      'dir'          => $dir ?? 'desc',
      'show_inactive'=> $showInactive,
      'is_admin'     => $isAdmin,
      'canSee'       => $canSee,
      'canSeeExtra'  => $canSeeExtra,
    ]);
    $this->view->render('layout/footer');
  }

  public function update(): void
  {
    $user    = $this->requireLogin();
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $perm    = $this->loadPerm($user);

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /employees');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /employees');
      exit;
    }

    // Aktuális adatok betöltése (nem-admin esetén a nem-látható mezők innen jönnek)
    $stmt = $this->db->pdo()->prepare("SELECT * FROM employees WHERE id=:id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $current = $stmt->fetch();

    if (!$current) {
      $this->flash->set('error', 'A dolgozó nem található.');
      header('Location: /employees');
      exit;
    }

    // Nem-admin: divízió ellenőrzés
    if ($perm !== null && !in_array((int)($current['division_id'] ?? 0), $perm['divisions'], true)) {
      http_response_code(403);
      $this->flash->set('error', 'Nincs jogosultságod ehhez a dolgozóhoz.');
      header('Location: /employees');
      exit;
    }

    $canSee = HrPermission::fieldChecker($perm);

    // POST értékek, de csak az engedélyezett mezőkre
    $postField = fn(string $key, mixed $default = null): mixed =>
      $canSee($key) ? (trim((string)($_POST[$key] ?? '')) ?: null) : ($current[$key] ?? $default);

    $data = [
      'id'             => $id,
      'full_name'      => $canSee('full_name') ? (trim((string)($_POST['full_name'] ?? '')) ?: ($current['full_name'] ?? '')) : ($current['full_name'] ?? ''),
      'birth_name'     => $postField('birth_name'),
      'mother_name'    => $postField('mother_name'),
      'birth_place'    => $postField('birth_place'),
      'birth_date'     => $postField('birth_date'),
      'tax_id'         => $postField('tax_id'),
      'taj'            => $postField('taj'),
      'company_emp_no' => $postField('company_emp_no'),
      'bank_account'   => $postField('bank_account'),
      'bank_name'      => $postField('bank_name'),
      'addr_zip'       => $postField('addr_zip'),
      'addr_city'      => $postField('addr_city'),
      'addr_line'      => $postField('addr_line'),
      'email'          => $postField('email'),
      'phone'          => $postField('phone'),
      'notes'          => $postField('notes'),
      'hired_on'       => $postField('hired_on'),
      'left_on'        => $postField('left_on'),
      // Csak admin változtathatja ezeket:
      'division_id' => $isAdmin
        ? (((int)($_POST['division_id'] ?? 0)) ?: null)
        : ($current['division_id'] ?? null),
      'is_active' => $isAdmin
        ? ((int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0)
        : (int)($current['is_active'] ?? 1),
    ];

    if (($data['full_name'] ?? '') === '') {
      $this->flash->set('error', 'A név kötelező.');
      header('Location: /employees_edit?id=' . $id);
      exit;
    }

    if ($data['birth_date'] !== null && $data['birth_date'] !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $data['birth_date'])) {
      $this->flash->set('error', 'A születési dátum formátuma: ÉÉÉÉ-HH-NN.');
      header('Location: /employees_edit?id=' . $id);
      exit;
    }

    try {
      $profilePath = $isAdmin
        ? $this->handleProfileUpload($_FILES['profile_image'] ?? null, $current['profile_image_path'] ?? null)
        : ($current['profile_image_path'] ?? null);

      $stmt = $this->db->pdo()->prepare("
        UPDATE employees SET
          full_name=:full_name,
          birth_name=:birth_name,
          mother_name=:mother_name,
          birth_place=:birth_place,
          birth_date=:birth_date,
          tax_id=:tax_id,
          taj=:taj,
          company_emp_no=:company_emp_no,
          bank_account=:bank_account,
          bank_name=:bank_name,
          division_id=:division_id,
          addr_zip=:addr_zip,
          addr_city=:addr_city,
          addr_line=:addr_line,
          email=:email,
          phone=:phone,
          notes=:notes,
          profile_image_path=:profile_image_path,
          is_active=:is_active,
          hired_on=:hired_on,
          left_on=:left_on
        WHERE id=:id
      ");
      $stmt->execute(array_merge($data, ['profile_image_path' => $profilePath]));

      // Audit: módosított mezők naplózása
      $auditFields = [
        'full_name','birth_name','mother_name','birth_place','birth_date',
        'tax_id','taj','company_emp_no','bank_account','bank_name',
        'division_id','addr_zip','addr_city','addr_line',
        'email','phone','notes','hired_on','left_on','is_active',
      ];
      $changed = false;
      foreach ($auditFields as $fk) {
        $oldVal = (string)($current[$fk] ?? '');
        $newVal = (string)($data[$fk] ?? '');
        if ($oldVal !== $newVal) {
          HrPermission::audit($this->db, (int)$user['id'], $user['name'], 'field_update', $id, $fk, $oldVal, $newVal);
          $changed = true;
        }
      }
      if ($changed) {
        HrPermission::audit($this->db, (int)$user['id'], $user['name'], 'employee_edit', $id);
      }

      // Extra mezők mentése
      $postedExtra = (array)($_POST['field'] ?? []);
      // Nem-admin: csak az engedélyezett extra mezők
      if ($perm !== null) {
        $postedExtra = array_filter(
          $postedExtra,
          fn($fid) => in_array((int)$fid, $perm['extra_fields'], true),
          ARRAY_FILTER_USE_KEY
        );
      }
      $this->saveEmployeeExtraValues((int)$id, $postedExtra, (array)($_POST['show'] ?? []));
      if (self::EXTRA_FIELDS_DEBUG) error_log('[HR] extra fields saved for employeeId=' . (int)$id);

      $this->flash->set('success', 'Mentve.');
      header('Location: /employees_edit?id=' . $id);
      exit;

    } catch (PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        $this->flash->set('error', 'Ez a céges törzsszám már foglalt (company_emp_no).');
        header('Location: /employees_edit?id=' . $id);
        exit;
      }
      $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      header('Location: /employees_edit?id=' . $id);
      exit;
    } catch (RuntimeException $e) {
      $this->flash->set('error', $e->getMessage());
      header('Location: /employees_edit?id=' . $id);
      exit;
    }
  }

  public function toggleActive(): void
  {
    $this->requireLogin();

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /employees');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /employees');
      exit;
    }

    $stmt = $this->db->pdo()->prepare("UPDATE employees SET is_active = IF(is_active=1,0,1) WHERE id=:id");
    $stmt->execute(['id' => $id]);

    $this->flash->set('success', 'Állapot módosítva.');
    header('Location: /employees_edit?id=' . $id);
    exit;
  }


  public function delete(): void
  {
    $user = $this->requireLogin();
    if (($user['role'] ?? '') !== 'admin') {
      $this->flash->set('error', 'Nincs jogosultság a törléshez.');
      header('Location: /employees');
      exit;
    }

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /employees');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó azonosító.');
      header('Location: /employees');
      exit;
    }

    $pdo = $this->db->pdo();

    try {
      $stmt = $pdo->prepare("SELECT profile_image_path FROM employees WHERE id=:id LIMIT 1");
      $stmt->execute(['id' => $id]);
      $emp = $stmt->fetch();

      if (!$emp) {
        $this->flash->set('error', 'A dolgozó nem található.');
        header('Location: /employees');
        exit;
      }

      $profilePath = (string)($emp['profile_image_path'] ?? '');

      $stmt = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id=:id");
      $stmt->execute(['id' => $id]);
      $docPaths = array_map(static fn($r) => (string)($r['file_path'] ?? ''), $stmt->fetchAll());

      $pdo->beginTransaction();

      foreach (['employee_field_value_options','employee_field_values','employee_addresses','employee_emails','employee_phones'] as $table) {
        try {
          $del = $pdo->prepare("DELETE FROM {$table} WHERE employee_id=:id");
          $del->execute(['id' => $id]);
        } catch (PDOException $e) {
          // legacy/missing tables ignored
        }
      }

      try {
        $delDocs = $pdo->prepare("DELETE FROM employee_documents WHERE employee_id=:id");
        $delDocs->execute(['id' => $id]);
      } catch (PDOException $e) {}

      $delEmp = $pdo->prepare("DELETE FROM employees WHERE id=:id");
      $delEmp->execute(['id' => $id]);

      $pdo->commit();

      if ($profilePath !== '' && str_starts_with($profilePath, '/uploads/profile/')) {
        $full = APP_ROOT . '/public' . $profilePath;
        if (is_file($full)) { @unlink($full); }
      }
      foreach ($docPaths as $filePath) {
        if ($filePath !== '' && str_starts_with($filePath, '/uploads/docs/')) {
          $full = APP_ROOT . '/public' . $filePath;
          if (is_file($full)) { @unlink($full); }
        }
      }

      $this->flash->set('success', 'Dolgozó véglegesen törölve.');
      header('Location: /employees');
      exit;

    } catch (PDOException $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $this->flash->set('error', 'Törlési hiba: ' . $e->getMessage());
      header('Location: /employees');
      exit;
    }
  }


  private function getActiveExtraFields(): array
  {
    try {
      $stmt = $this->db->pdo()->query("SELECT id, name, field_key, field_type, options FROM employee_fields WHERE is_active=1 ORDER BY name ASC");
      return $stmt->fetchAll();
    } catch (PDOException $e) {
      return [];
    }
  }

private function getEmployeeExtraValues(int $employeeId): array
  {
    try {
      $stmt = $this->db->pdo()->prepare("SELECT field_id, value, show_on_card FROM employee_field_values WHERE employee_id=:eid");
      $stmt->execute(['eid' => $employeeId]);
      $rows = $stmt->fetchAll();

      $out = [];
      foreach ($rows as $r) {
        $fid = (int)$r['field_id'];
        $out[$fid] = [
          'value' => (string)($r['value'] ?? ''),
          'show'  => (int)($r['show_on_card'] ?? 1),
        ];
      }
      return $out;
    } catch (PDOException $e) {
      if (defined('self::EXTRA_FIELDS_DEBUG') && self::EXTRA_FIELDS_DEBUG) {
        error_log('[HR] getEmployeeExtraValues error: ' . $e->getMessage());
      }
      return [];
    }
  }

  private function saveEmployeeExtraValues(int $employeeId, array $posted, array $postedShow = []): void
  {
    if (self::EXTRA_FIELDS_DEBUG) {
      error_log('[HR] saveEmployeeExtraValues employeeId=' . $employeeId . ' posted_keys=' . implode(',', array_keys($posted)));
    }
    // $posted expected: [field_id => scalar|string|array]
    if ($employeeId <= 0) return;

    // Load field types to handle multiselect storage
    $types = [];
    try {
      $stmt = $this->db->pdo()->query("SELECT id, field_type FROM employee_fields");
      foreach ($stmt->fetchAll() as $r) $types[(int)$r['id']] = (string)$r['field_type'];
    } catch (PDOException $e) {
      $types = [];
    }

    foreach ($posted as $fid => $val) {
      $fieldId = (int)$fid;
      if ($fieldId <= 0) continue;

      $type = $types[$fieldId] ?? 'text';

      if (is_array($val)) {
        // multiselect expected
        $val = json_encode(array_values(array_filter($val, fn($x)=>$x!=='' && $x!==null)), JSON_UNESCAPED_UNICODE);
      } else {
        $val = trim((string)$val);
      }

      // treat empty as NULL -> delete row to keep DB clean
      if ($val === '' || $val === '[]') {
        try {
          $del = $this->db->pdo()->prepare("DELETE FROM employee_field_values WHERE employee_id=:eid AND field_id=:fid");
          $del->execute(['eid'=>$employeeId,'fid'=>$fieldId]);
        } catch (PDOException $e) { if (self::EXTRA_FIELDS_DEBUG) error_log('[HR] extra fields DELETE error: ' . $e->getMessage()); }
        continue;
      }

      try {
        $up = $this->db->pdo()->prepare("
          INSERT INTO employee_field_values (employee_id, field_id, value, show_on_card)
          VALUES (:eid, :fid, :val, :show)
          ON DUPLICATE KEY UPDATE value=VALUES(value), show_on_card=VALUES(show_on_card), updated_at=CURRENT_TIMESTAMP()
        ");
        $show = isset($postedShow[$fieldId]) ? 1 : 0;
        $up->execute(['eid'=>$employeeId,'fid'=>$fieldId,'val'=>$val,'show'=>$show]);
} catch (PDOException $e) { if (self::EXTRA_FIELDS_DEBUG) error_log('[HR] extra fields DELETE error: ' . $e->getMessage()); }
    }
  }



public function pdf(): void
{
  $this->auth->requireLogin();
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { header('Location: /employees'); exit; }

  // Employee + division
  $stmt = $this->db->pdo()->prepare("
    SELECT e.*, dv.name AS division_name
      FROM employees e
      LEFT JOIN divisions dv ON dv.id = e.division_id
     WHERE e.id = :id
     LIMIT 1
  ");
  $stmt->execute(['id' => $id]);
  $employee = $stmt->fetch();
  if (!$employee) { header('Location: /employees'); exit; }

  $fields = $this->getActiveExtraFields();
  $field_values = $this->getEmployeeExtraValues((int)$id);
  $docs = $this->getEmployeeDocuments((int)$id);

  // Absolute URL base for images (profile picture)
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $baseUrl = $scheme . '://' . $host;

  $this->view->render('employees/pdf', [
    'employee' => $employee,
    'fields' => $fields,
    'field_values' => $field_values,
    'docs' => $docs,
    'baseUrl' => $baseUrl,
  ]);
}


private function getEmployeeDocuments(int $employeeId): array
{
  try {
    $stmt = $this->db->pdo()->prepare("
      SELECT d.*, t.name AS doc_type_name
        FROM employee_documents d
        JOIN document_types t ON t.id = d.document_type_id
       WHERE d.employee_id = :eid
       ORDER BY d.created_at DESC
    ");
    $stmt->execute(['eid' => $employeeId]);
    return $stmt->fetchAll();
  } catch (PDOException $e) {
    return [];
  }
}

// ─── Export ──────────────────────────────────────────────────────────────────

private const EXPORT_FIELD_DEFS = [
  'Személyes adatok' => [
    'full_name'   => 'Teljes név',
    'birth_name'  => 'Születési név',
    'mother_name' => 'Anyja neve',
    'birth_place' => 'Születési hely',
    'birth_date'  => 'Születési dátum',
  ],
  'Céges / azonosító' => [
    'division_name'  => 'Divízió',
    'company_emp_no' => 'Céges törzsszám',
    'tax_id'         => 'Adóazonosító',
    'taj'            => 'TAJ szám',
  ],
  'Bankszámla' => [
    'bank_account' => 'Bankszámlaszám',
    'bank_name'    => 'Bank neve',
  ],
  'Munkaviszony' => [
    'hired_on'  => 'Belépés dátuma',
    'left_on'   => 'Kilépés dátuma',
    'is_active' => 'Állapot',
  ],
  'Lakcím' => [
    'addr_zip'  => 'Irányítószám',
    'addr_city' => 'Település',
    'addr_line' => 'Cím',
  ],
  'Kapcsolat' => [
    'email' => 'Email',
    'phone' => 'Telefon',
    'notes' => 'Megjegyzés',
  ],
  'Dokumentumok' => [
    '_doc_type'    => 'Dokumentum típusa',
    '_doc_expires' => 'Lejárat dátuma',
  ],
];

public function showExport(): void
{
  $user = $this->requireLogin();
  $this->auth->requireRole('admin');

  $stmt = $this->db->pdo()->query("
    SELECT e.id, e.full_name, e.is_active,
           COALESCE(d.id, 0) AS div_id,
           COALESCE(d.name, '(Nincs divízió)') AS div_name
    FROM employees e
    LEFT JOIN divisions d ON d.id = e.division_id
    ORDER BY div_name ASC, e.full_name ASC
  ");
  $employees = $stmt->fetchAll();

  $byDivision = [];
  foreach ($employees as $emp) {
    $key = (int)$emp['div_id'];
    if (!isset($byDivision[$key])) {
      $byDivision[$key] = ['name' => $emp['div_name'], 'employees' => []];
    }
    $byDivision[$key]['employees'][] = $emp;
  }

  $this->view->render('layout/header', ['title' => 'Adatexport', 'user' => $user]);
  $allEmployees = $employees;
  usort($allEmployees, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));

  $this->view->render('employees/export', [
    'byDivision'   => $byDivision,
    'allEmployees' => $allEmployees,
    'fieldDefs'    => self::EXPORT_FIELD_DEFS,
    'extraFields'  => $this->getActiveExtraFields(),
    'csrf'         => $this->csrf->token(),
    'is_admin'     => (($user['role'] ?? '') === 'admin'),
    'error'        => $_GET['error'] ?? null,
  ]);
  $this->view->render('layout/footer');
}

public function export(): void
{
  $this->requireLogin();
  $this->auth->requireRole('admin');

  if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
    http_response_code(400); echo 'Érvénytelen kérés (CSRF).'; exit;
  }

  $ids    = array_filter(array_map('intval', (array)($_POST['emp_ids'] ?? [])));
  $selFields = (array)($_POST['fields'] ?? []);
  $format = (string)($_POST['format'] ?? 'csv');
  if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) $format = 'csv';

  if (empty($ids)) {
    header('Location: /employees_export?error=no_selection'); exit;
  }

  // Flat map of all static fields
  $allStaticDefs = array_merge(...array_values(self::EXPORT_FIELD_DEFS));

  // Extra fields
  $extraFieldRows = $this->getActiveExtraFields();
  $extraFieldMap  = [];
  foreach ($extraFieldRows as $ef) {
    $extraFieldMap['extra_' . $ef['id']] = $ef;
  }

  // Separate selected fields
  $selStatic  = [];
  $selExtra   = [];
  $selDocType = false;
  $selDocExp  = false;
  foreach ($selFields as $f) {
    $f = (string)$f;
    if ($f === '_doc_type')   { $selDocType = true; }
    elseif ($f === '_doc_expires') { $selDocExp  = true; }
    elseif (str_starts_with($f, 'extra_') && isset($extraFieldMap[$f])) { $selExtra[] = $f; }
    elseif (isset($allStaticDefs[$f])) { $selStatic[] = $f; }
  }
  $needDocs = $selDocType || $selDocExp;

  // Query employees
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $this->db->pdo()->prepare("
    SELECT e.*, COALESCE(d.name,'') AS division_name
    FROM employees e
    LEFT JOIN divisions d ON d.id = e.division_id
    WHERE e.id IN ($ph)
    ORDER BY e.full_name ASC
  ");
  $stmt->execute(array_values($ids));
  $employees = $stmt->fetchAll();

  // Extra field values
  $extraValues = [];
  if (!empty($selExtra) || $format === 'pdf') {
    $empIds = array_column($employees, 'id');
    if ($empIds) {
      $ph2 = implode(',', array_fill(0, count($empIds), '?'));
      $sv  = $this->db->pdo()->prepare(
        "SELECT employee_id, field_id, value FROM employee_field_values WHERE employee_id IN ($ph2)"
      );
      $sv->execute($empIds);
      foreach ($sv->fetchAll() as $row) {
        $extraValues[(int)$row['employee_id']][(int)$row['field_id']] = (string)$row['value'];
      }
    }
  }

  if ($format === 'pdf') {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');

    // Lejáratos dokumentumok PDF-hez is kellenek
    $pdfEmpDocs = [];
    $empIds = array_column($employees, 'id');
    if ($empIds) {
      $ph3 = implode(',', array_fill(0, count($empIds), '?'));
      $sd  = $this->db->pdo()->prepare("
        SELECT d.employee_id, t.name AS type_name, d.expires_at
        FROM employee_documents d
        JOIN document_types t ON t.id = d.document_type_id
        WHERE d.employee_id IN ($ph3) AND d.expires_at IS NOT NULL
        ORDER BY d.expires_at ASC
      ");
      $sd->execute($empIds);
      foreach ($sd->fetchAll() as $dr) {
        $pdfEmpDocs[(int)$dr['employee_id']][] = $dr;
      }
    }

    $this->view->render('employees/export_pdf', [
      'employees'   => $employees,
      'extraFields' => $extraFieldRows,
      'extraValues' => $extraValues,
      'empDocs'     => $pdfEmpDocs,
      'baseUrl'     => $baseUrl,
    ]);
    exit;
  }

  // CSV / XLSX: build headers + rows
  if (empty($selStatic) && empty($selExtra) && !$needDocs) {
    header('Location: /employees_export?error=no_fields'); exit;
  }

  // Lejáratos dokumentumok lekérése (ha kell)
  $empDocs = [];  // [emp_id => [{type_name, expires_at}]]
  if ($needDocs) {
    $empIds = array_column($employees, 'id');
    if ($empIds) {
      $ph3 = implode(',', array_fill(0, count($empIds), '?'));
      $sd  = $this->db->pdo()->prepare("
        SELECT d.employee_id, t.name AS type_name, d.expires_at
        FROM employee_documents d
        JOIN document_types t ON t.id = d.document_type_id
        WHERE d.employee_id IN ($ph3) AND d.expires_at IS NOT NULL
        ORDER BY d.expires_at ASC
      ");
      $sd->execute($empIds);
      foreach ($sd->fetchAll() as $dr) {
        $empDocs[(int)$dr['employee_id']][] = $dr;
      }
    }
  }

  $headers = [];
  foreach ($selStatic as $f) $headers[] = $allStaticDefs[$f];
  foreach ($selExtra  as $f) $headers[] = $extraFieldMap[$f]['name'];
  if ($selDocType) $headers[] = 'Dokumentum típusa';
  if ($selDocExp)  $headers[] = 'Lejárat dátuma';

  // Alap sor (dolgozó adatai doc nélkül)
  $makeBaseRow = function(array $emp) use ($selStatic, $allStaticDefs, $selExtra, $extraFieldMap, $extraValues): array {
    $row = [];
    foreach ($selStatic as $f) {
      if ($f === 'is_active') { $row[] = (int)$emp['is_active'] === 1 ? 'Aktív' : 'Inaktív'; }
      else { $row[] = (string)($emp[$f] ?? ''); }
    }
    foreach ($selExtra as $f) {
      $efId   = (int)$extraFieldMap[$f]['id'];
      $efType = (string)($extraFieldMap[$f]['field_type'] ?? 'text');
      $val    = $extraValues[(int)$emp['id']][$efId] ?? '';
      if ($efType === 'multiselect' && $val !== '') {
        $arr = json_decode($val, true);
        if (is_array($arr)) $val = implode(', ', $arr);
      }
      $row[] = $val;
    }
    return $row;
  };

  $rows = [];
  foreach ($employees as $emp) {
    $base = $makeBaseRow($emp);
    if (!$needDocs) {
      $rows[] = $base;
    } else {
      $docs = $empDocs[(int)$emp['id']] ?? [];
      if (empty($docs)) {
        // Nincs lejáratos dok – egy sor, üres dok mezőkkel
        $row = $base;
        if ($selDocType) $row[] = '';
        if ($selDocExp)  $row[] = '';
        $rows[] = $row;
      } else {
        // Egy sor per lejáratos dokumentum
        foreach ($docs as $doc) {
          $row = $base;
          if ($selDocType) $row[] = (string)($doc['type_name'] ?? '');
          if ($selDocExp)  $row[] = (string)($doc['expires_at'] ?? '');
          $rows[] = $row;
        }
      }
    }
  }

  $filename = 'hr_export_' . date('Y-m-d');

  if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    echo "\xEF\xBB\xBF";
    $fh = fopen('php://output', 'w');
    fputcsv($fh, $headers, ';');
    foreach ($rows as $row) fputcsv($fh, $row, ';');
    fclose($fh);
    exit;
  }

  // XLSX
  $this->outputXlsx($headers, $rows, $filename);
}

private function outputXlsx(array $headers, array $rows, string $filename): void
{
  $ss = []; $ss_i = [];
  $ss_add = function(string $v) use (&$ss, &$ss_i): int {
    if (!isset($ss_i[$v])) { $ss_i[$v] = count($ss); $ss[] = $v; }
    return $ss_i[$v];
  };
  foreach ($headers as $h) $ss_add($h);
  foreach ($rows as $row) foreach ($row as $cell) $ss_add((string)$cell);

  $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="9"/><name val="Calibri"/></font>
    <font><b/><sz val="9"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1F2937"/></patternFill></fill>
  </fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
  </cellXfs>
</styleSheet>';

  $abc = array_merge(range('A','Z'), array_map(fn($l)=>'A'.$l, range('A','Z')));
  $sheet_rows_xml = '<row r="1">';
  foreach ($headers as $ci => $h) {
    $ref = ($abc[$ci] ?? 'A') . '1';
    $sheet_rows_xml .= '<c r="' . $ref . '" t="s" s="1"><v>' . ($ss_i[$h] ?? 0) . '</v></c>';
  }
  $sheet_rows_xml .= '</row>';
  foreach ($rows as $ri => $row) {
    $rn = $ri + 2;
    $sheet_rows_xml .= '<row r="' . $rn . '">';
    foreach ($row as $ci => $cell) {
      $ref = ($abc[$ci] ?? 'A') . $rn;
      $sheet_rows_xml .= '<c r="' . $ref . '" t="s"><v>' . ($ss_i[(string)$cell] ?? 0) . '</v></c>';
    }
    $sheet_rows_xml .= '</row>';
  }

  $cols_xml = '<cols>';
  foreach ($headers as $ci => $h) {
    $w = max(10, min(50, mb_strlen($h) * 1.4 + 4));
    $cn = $ci + 1;
    $cols_xml .= '<col min="' . $cn . '" max="' . $cn . '" width="' . $w . '" customWidth="1"/>';
  }
  $cols_xml .= '</cols>';

  $ss_xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
  $ss_xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($ss) . '" uniqueCount="' . count($ss) . '">';
  foreach ($ss as $sv) $ss_xml .= '<si><t xml:space="preserve">' . htmlspecialchars($sv, ENT_XML1, 'UTF-8') . '</t></si>';
  $ss_xml .= '</sst>';

  $sheet_xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
  $sheet_xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
  $sheet_xml .= $cols_xml . '<sheetData>' . $sheet_rows_xml . '</sheetData></worksheet>';

  $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets>
</workbook>';
  $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"    Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"        Target="styles.xml"/>
</Relationships>';
  $pkg_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
  $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"           ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"  ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml"      ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml"             ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

  $tmp = tempnam(sys_get_temp_dir(), 'hr_xlsx_');
  $zip = new ZipArchive();
  $zip->open($tmp, ZipArchive::OVERWRITE);
  $zip->addFromString('[Content_Types].xml',        $ct);
  $zip->addFromString('_rels/.rels',                $pkg_rels);
  $zip->addFromString('xl/workbook.xml',            $wb);
  $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
  $zip->addFromString('xl/worksheets/sheet1.xml',   $sheet_xml);
  $zip->addFromString('xl/sharedStrings.xml',       $ss_xml);
  $zip->addFromString('xl/styles.xml',              $styles_xml);
  $zip->close();

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
  header('Content-Length: ' . filesize($tmp));
  readfile($tmp); unlink($tmp);
}
}

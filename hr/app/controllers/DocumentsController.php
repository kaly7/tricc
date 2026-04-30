<?php
class DocumentsController
{
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

  private function getEmployeesForSelect(?array $perm = null): array
  {
    if ($perm !== null) {
      if (empty($perm['divisions'])) return [];
      $ph   = implode(',', array_fill(0, count($perm['divisions']), '?'));
      $stmt = $this->db->pdo()->prepare(
        "SELECT id, full_name, is_active FROM employees
         WHERE COALESCE(division_id,0) IN ($ph)
         ORDER BY is_active DESC, full_name ASC LIMIT 500"
      );
      $stmt->execute($perm['divisions']);
      return $stmt->fetchAll();
    }
    $stmt = $this->db->pdo()->query("SELECT id, full_name, is_active FROM employees ORDER BY is_active DESC, full_name ASC LIMIT 500");
    return $stmt->fetchAll();
  }

  private function getDivisionsForFilter(): array
  {
    $stmt = $this->db->pdo()->query("SELECT id, name FROM divisions WHERE is_active=1 ORDER BY name ASC");
    return $stmt->fetchAll();
  }

  private function getDocTypesForFilter(): array
  {
    $stmt = $this->db->pdo()->query("SELECT id, name FROM document_types WHERE is_active=1 ORDER BY name ASC");
    return $stmt->fetchAll();
  }

  private function getDocTypes(): array
  {
    $stmt = $this->db->pdo()->query("SELECT id, name FROM document_types WHERE is_active=1 ORDER BY name ASC");
    return $stmt->fetchAll();
  }

  private function saveUpload(?array $file): array
  {
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
      throw new RuntimeException('Nem választottál fájlt.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Fájl feltöltési hiba (PHP): ' . (int)$file['error']);
    }

    $max = 25 * 1024 * 1024;
    if (($file['size'] ?? 0) > $max) {
      throw new RuntimeException('A fájl túl nagy (max 25MB).');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
      throw new RuntimeException('A feltöltött fájl ideiglenes helye érvénytelen.');
    }

    $orig = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '') $ext = 'bin';

    $dir = APP_ROOT . '/public/uploads/docs';
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nem lehet létrehozni a feltöltési könyvtárat: ' . $dir);
      }
    }
    if (!is_writable($dir)) {
      throw new RuntimeException('A feltöltési könyvtár nem írható: ' . $dir);
    }

    $name = 'd_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;

    if (!@move_uploaded_file($tmp, $dest)) {
      $err = error_get_last();
      $msg = $err['message'] ?? 'ismeretlen ok';
      throw new RuntimeException('Nem sikerült elmenteni a feltöltött fájlt: ' . $msg);
    }
    @chmod($dest, 0644);

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $dest) : null;
    if ($finfo) finfo_close($finfo);

    return [
      'file_path' => '/uploads/docs/' . $name,
      'original_name' => $orig ?: null,
      'mime' => $mime ?: null,
      'file_size' => (int)($file['size'] ?? 0),
    ];
  }

  public function index(): void
  {
    $user    = $this->requireLogin();
    $isAdmin = (($user['role'] ?? '') === 'admin');
    $perm    = $isAdmin ? null : HrPermission::load($this->db, (int)$user['id']);

    $employeeId = (int)($_GET['employee_id'] ?? 0);
    $divisionId = (int)($_GET['division_id'] ?? 0);
    $typeId     = (int)($_GET['type_id'] ?? 0);
    $q          = trim((string)($_GET['q'] ?? ''));

    $sort = (string)($_GET['sort'] ?? 'created');
    $dir  = strtolower((string)($_GET['dir'] ?? 'desc'));
    if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';

    $sort_whitelist = [
      'employee' => 'e.full_name',
      'division' => 'dv.name',
      'type'     => 't.name',
      'title'    => 'd.title',
      'expires'  => 'd.expires_at',
      'created'  => 'd.created_at',
    ];
    $sortKey = $sort !== '' ? $sort : 'created';
    if (!isset($sort_whitelist[$sortKey])) $sortKey = 'created';
    $orderSql = $sort_whitelist[$sortKey] . ' ' . strtoupper($dir) . ', d.id DESC';

    $whereParts = [];
    $params = [];

    if ($employeeId > 0) { $whereParts[] = "d.employee_id = :eid"; $params['eid'] = $employeeId; }
    if ($divisionId > 0) { $whereParts[] = "e.division_id = :did"; $params['did'] = $divisionId; }
    if ($typeId > 0)     { $whereParts[] = "d.document_type_id = :tid"; $params['tid'] = $typeId; }

    // Nem-admin: csak az engedélyezett divíziók dokumentumai
    if ($perm !== null) {
      if (empty($perm['divisions'])) {
        $whereParts[] = "1=0";
      } else {
        $ph = implode(',', array_fill(0, count($perm['divisions']), '?'));
        $whereParts[] = "COALESCE(e.division_id,0) IN ($ph)";
        foreach ($perm['divisions'] as $did) $params[] = $did;
      }
    }

    if ($q !== '') {
      $whereParts[] = "(e.full_name LIKE :q OR dv.name LIKE :q OR t.name LIKE :q OR d.title LIKE :q OR d.original_name LIKE :q)";
      $params['q'] = '%' . $q . '%';
    }

    $where = '';
    if (!empty($whereParts)) $where = 'WHERE ' . implode(' AND ', $whereParts);

    $stmt = $this->db->pdo()->prepare("
      SELECT d.*,
             e.full_name,
             dv.name AS division_name,
             t.name AS type_name
      FROM employee_documents d
      JOIN employees e ON e.id = d.employee_id
      LEFT JOIN divisions dv ON dv.id = e.division_id
      JOIN document_types t ON t.id = d.document_type_id
      $where
      ORDER BY $orderSql
      LIMIT 1000
    ");
    $stmt->execute($params);
    $docs = $stmt->fetchAll();

    $this->view->render('layout/header', ['title' => 'Dokumentumok', 'user' => $user]);
    $this->view->render('documents/index', [
      'docs' => $docs,
      'is_admin' => $isAdmin,
      'employees' => $this->getEmployeesForSelect($perm),
      'divisions' => $this->getDivisionsForFilter(),
      'types' => $this->getDocTypesForFilter(),
      'employee_id' => $employeeId,
      'division_id' => $divisionId,
      'type_id' => $typeId,
      'q' => $q,
      'sort' => $sortKey,
      'dir' => $dir,
      'success' => $this->flash->get('success'),
      'error' => $this->flash->get('error'),
      'csrf' => $this->csrf->token(),
    ]);
    $this->view->render('layout/footer');
  }

  public function delete(): void
  {
    $user    = $this->requireLogin();
    $isAdmin = (($user['role'] ?? '') === 'admin');
    $perm    = $isAdmin ? null : HrPermission::load($this->db, (int)$user['id']);

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /documents');
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      $this->flash->set('error', 'Hiányzó dokumentum azonosító.');
      header('Location: /documents');
      exit;
    }

    // Load the document row first (needed for file deletion and redirect)
    $stmt = $this->db->pdo()->prepare("
      SELECT d.id, d.employee_id, d.file_path, d.original_name, e.division_id
      FROM employee_documents d
      JOIN employees e ON e.id = d.employee_id
      WHERE d.id = :id LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $doc = $stmt->fetch();

    if (!$doc) {
      $this->flash->set('error', 'A dokumentum nem található.');
      header('Location: /documents');
      exit;
    }

    // Nem-admin: divízió ellenőrzés
    if ($perm !== null && !in_array((int)($doc['division_id'] ?? 0), $perm['divisions'], true)) {
      http_response_code(403);
      $this->flash->set('error', 'Nincs jogosultságod ehhez a dokumentumhoz.');
      header('Location: /documents');
      exit;
    }

    try {
      $this->db->pdo()->beginTransaction();

      $del = $this->db->pdo()->prepare("DELETE FROM employee_documents WHERE id = :id");
      $del->execute(['id' => $id]);

      $this->db->pdo()->commit();

      // Remove file (best effort)
      $filePath = (string)($doc['file_path'] ?? '');
      if ($filePath !== '' && str_starts_with($filePath, '/uploads/docs/')) {
        $full = APP_ROOT . '/public' . $filePath;
        if (is_file($full)) {
          @unlink($full);
        }
      }

      $label = $doc['original_name'] ?: basename((string)$doc['file_path']);
      $this->flash->set('success', 'Dokumentum törölve: ' . (string)$label);
      HrPermission::audit($this->db, (int)$user['id'], $user['name'], 'doc_delete', (int)$doc['employee_id'], null, $label);

      // keep employee filter if possible
      $eid = (int)($doc['employee_id'] ?? 0);
      header('Location: /documents' . ($eid > 0 ? ('?employee_id=' . $eid) : ''));
      exit;

    } catch (PDOException $e) {
      if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
      $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      header('Location: /documents');
      exit;
    }
  }

  public function showUpload(): void
  {
    $user    = $this->requireLogin();
    $isAdmin = (($user['role'] ?? '') === 'admin');
    $perm    = $isAdmin ? null : HrPermission::load($this->db, (int)$user['id']);
    $employeeId = (int)($_GET['employee_id'] ?? 0);

    $this->view->render('layout/header', ['title' => 'Dokumentum feltöltés', 'user' => $user]);
    $this->view->render('documents/upload', [
      'error'       => $this->flash->get('error'),
      'success'     => $this->flash->get('success'),
      'csrf'        => $this->csrf->token(),
      'types'       => $this->getDocTypes(),
      'employees'   => $this->getEmployeesForSelect($perm),
      'employee_id' => $employeeId,
    ]);
    $this->view->render('layout/footer');
  }

  public function upload(): void
  {
    $user    = $this->requireLogin();
    $isAdmin = (($user['role'] ?? '') === 'admin');
    $perm    = $isAdmin ? null : HrPermission::load($this->db, (int)$user['id']);

    if (!$this->csrf->verify($_POST['_csrf'] ?? null)) {
      $this->flash->set('error', 'Érvénytelen kérés (CSRF).');
      header('Location: /documents_upload');
      exit;
    }

    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $typeId = (int)($_POST['document_type_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? '')) ?: null;

    $hasExpiry = (int)($_POST['has_expiry'] ?? 0) === 1;
    $expiresAt = $hasExpiry ? trim((string)($_POST['expires_at'] ?? '')) : '';
    if (!$hasExpiry) $expiresAt = null;

    if ($employeeId <= 0) {
      $this->flash->set('error', 'Válassz dolgozót.');
      header('Location: /documents_upload');
      exit;
    }

    // Nem-admin: ellenőrzés, hogy a kiválasztott dolgozó az engedélyezett divízióban van-e
    if ($perm !== null) {
      $empDiv = $this->db->pdo()->prepare("SELECT division_id FROM employees WHERE id=? LIMIT 1");
      $empDiv->execute([$employeeId]);
      $empDiv = $empDiv->fetch();
      if (!$empDiv || !in_array((int)($empDiv['division_id'] ?? 0), $perm['divisions'], true)) {
        $this->flash->set('error', 'Nincs jogosultságod ehhez a dolgozóhoz.');
        header('Location: /documents_upload');
        exit;
      }
    }

    if ($typeId <= 0) {
      $this->flash->set('error', 'Válassz dokumentumtípust.');
      header('Location: /documents_upload?employee_id=' . $employeeId);
      exit;
    }
    if ($expiresAt !== null && $expiresAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
      $this->flash->set('error', 'A lejárat dátum formátuma: ÉÉÉÉ-HH-NN.');
      header('Location: /documents_upload?employee_id=' . $employeeId);
      exit;
    }

    try {
      $up = $this->saveUpload($_FILES['file'] ?? null);

      $stmt = $this->db->pdo()->prepare("
        INSERT INTO employee_documents
          (employee_id, document_type_id, title, file_path, original_name, mime, file_size, expires_at)
        VALUES
          (:employee_id, :document_type_id, :title, :file_path, :original_name, :mime, :file_size, :expires_at)
      ");
      $stmt->execute([
        'employee_id' => $employeeId,
        'document_type_id' => $typeId,
        'title' => $title,
        'file_path' => $up['file_path'],
        'original_name' => $up['original_name'],
        'mime' => $up['mime'],
        'file_size' => $up['file_size'],
        'expires_at' => $expiresAt ?: null,
      ]);

      $this->flash->set('success', 'Dokumentum feltöltve.');
      HrPermission::audit($this->db, (int)$user['id'], $user['name'], 'doc_upload', $employeeId, null, null, $up['original_name']);
      header('Location: /documents?employee_id=' . $employeeId);
      exit;

    } catch (RuntimeException $e) {
      $this->flash->set('error', $e->getMessage());
      header('Location: /documents_upload?employee_id=' . $employeeId);
      exit;
    } catch (PDOException $e) {
      $this->flash->set('error', 'DB hiba: ' . $e->getMessage());
      header('Location: /documents_upload?employee_id=' . $employeeId);
      exit;
    }
  }
}

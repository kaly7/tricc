<?php
declare(strict_types=1);
require_once __DIR__ . '/_admin_bootstrap.php';

$msg = '';
$err = '';

// Sticky form values (for create_user form)
$form_username = '';
$form_email = '';
$form_full_name = '';



// HR DB connection (for mapping users to HR employees)
$hrPdo = null;
$hrEmployees = [];
try {
  if (isset($config['hr'])) {
    $hrPdo = new PDO($config['hr']['dsn'], $config['hr']['user'], $config['hr']['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $hrEmployees = $hrPdo->query("SELECT id, full_name, is_active FROM employees ORDER BY full_name")->fetchAll();
  }
} catch (Throwable $e) {
  // ignore; HR mapping UI will show error later
}
function password_rule_errors(string $pass): array {
  $errors = [];
  if (mb_strlen($pass) < 6) {
    $errors[] = 'A jelszó legalább 6 karakter legyen.';
  }

  $hasLower = (bool)preg_match('/[a-z]/', $pass);
  $hasUpper = (bool)preg_match('/[A-Z]/', $pass);
  $hasDigit = (bool)preg_match('/[0-9]/', $pass);
  $hasSpec  = (bool)preg_match('/[^a-zA-Z0-9]/', $pass);

  $types = 0;
  foreach ([$hasLower,$hasUpper,$hasDigit,$hasSpec] as $b) { if ($b) $types++; }

  if ($types < 3) {
    $missing = [];
    if (!$hasLower) $missing[] = 'kisbetű';
    if (!$hasUpper) $missing[] = 'nagybetű';
    if (!$hasDigit) $missing[] = 'szám';
    if (!$hasSpec)  $missing[] = 'speciális karakter';

    $errors[] = 'A jelszóban a következők közül legalább 3 féle szerepeljen: kisbetű, nagybetű, szám, speciális karakter. (Hiányzik: ' . implode(', ', $missing) . ')';
  }

  return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'create_user') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $full = trim((string)($_POST['full_name'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    // Sticky values (password excluded intentionally)
    $form_username = $username;
    $form_email = $email;
    $form_full_name = $full;

    if ($username === '' || $full === '' || $pass === '') {
      $err = 'Minden mező kötelező (email nem kötelező).';
    } else {
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Hibás email formátum.';
      } else {
        $pwErrs = password_rule_errors($pass);
        if ($pwErrs) {
          $err = implode(' ', $pwErrs);
        } else {
          $hash = password_hash($pass, PASSWORD_DEFAULT);
          try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, full_name, password_hash, is_active) VALUES (?,?,?,?,1)");
            $stmt->execute([$username, ($email === '' ? null : $email), $full, $hash]);
            $msg = 'Felhasználó létrehozva.';

            // Clear sticky values after success
            $form_username = '';
            $form_email = '';
            $form_full_name = '';
          } catch (Throwable $e) {
            $err = 'Hiba: ' . $e->getMessage();
          }
        }
      }
    }
  }

  if ($action === 'set_password') {
    $userIdP = (int)($_POST['user_id'] ?? 0);
    $pass = (string)($_POST['password'] ?? '');
    if ($userIdP <= 0 || $pass === '') {
      $err = 'Hiányzó adatok.';
    } else {
      $pwErrs = password_rule_errors($pass);
      if ($pwErrs) {
        $err = implode(' ', $pwErrs);
      } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $userIdP]);
        $msg = 'Jelszó frissítve.';
      }
    }
  }

  if ($action === 'set_email') {
    $userIdP = (int)($_POST['user_id'] ?? 0);
    $email = trim((string)($_POST['email'] ?? ''));
    if ($userIdP <= 0) {
      $err = 'Hiányzó adatok.';
    } else {
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Hibás email formátum.';
      } else {
        try {
          $pdo->prepare("UPDATE users SET email=? WHERE id=?")->execute([($email === '' ? null : $email), $userIdP]);
          $msg = 'Email frissítve.';
        } catch (Throwable $e) {
          $err = 'Hiba: ' . $e->getMessage();
        }
      }
    }
  }
if ($action === 'set_hr_employee') {
  $userIdP = (int)($_POST['user_id'] ?? 0);
  $empRaw = trim((string)($_POST['hr_employee_id'] ?? ''));
  $empId = ($empRaw === '') ? null : (int)$empRaw;

  if ($userIdP <= 0) {
    $err = 'Hiányzó user id.';
  } else {
    try {
      $pdo->prepare("UPDATE users SET hr_employee_id=? WHERE id=?")->execute([$empId, $userIdP]);
      $msg = 'HR munkatárs hozzárendelés frissítve.';
    } catch (Throwable $e) {
      $err = 'Hiba (HR hozzárendelés): ' . $e->getMessage();
    }
  }
}


if ($action === 'toggle_active') {
    $userIdP = (int)($_POST['user_id'] ?? 0);
    if ($userIdP > 0) {
      try {
        $pdo->prepare("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id=?")->execute([$userIdP]);
        $msg = 'Állapot frissítve.';
      } catch (Throwable $e) {
        $err = 'Hiba (aktiválás/inaktiválás): ' . $e->getMessage();
      }
    } else {
      $err = 'Hiányzó user id.';
    }
  }

  if ($action === 'delete_user') {
    $userIdP = (int)($_POST['user_id'] ?? 0);
    if ($userIdP > 0) {
      try {
        // Clean permissions first (if table exists)
        try {
          $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$userIdP]);
        } catch (Throwable $e) {
          // ignore if table name differs or not present
        }

        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userIdP]);
        $msg = 'Felhasználó törölve.';
      } catch (Throwable $e) {
        $err = 'Hiba (törlés): ' . $e->getMessage();
      }
    } else {
      $err = 'Hiányzó user id.';
    }
  }

  // PRG: always redirect after POST to avoid double-submit and to ensure fresh list
  $_SESSION['_flash_msg'] = $msg;
  $_SESSION['_flash_err'] = $err;
  $_SESSION['_flash_form'] = [
    'username' => $form_username,
    'email' => $form_email,
    'full_name' => $form_full_name,
  ];
  header('Location: /admin/users.php');
  exit;
}

// Restore flash after redirect
if (!empty($_SESSION['_flash_msg'])) { $msg = (string)$_SESSION['_flash_msg']; unset($_SESSION['_flash_msg']); }
if (!empty($_SESSION['_flash_err'])) { $err = (string)$_SESSION['_flash_err']; unset($_SESSION['_flash_err']); }
if (!empty($_SESSION['_flash_form']) && is_array($_SESSION['_flash_form'])) {
  $form_username = (string)($_SESSION['_flash_form']['username'] ?? '');
  $form_email = (string)($_SESSION['_flash_form']['email'] ?? '');
  $form_full_name = (string)($_SESSION['_flash_form']['full_name'] ?? '');
  unset($_SESSION['_flash_form']);
}

// Rendezés (session-ben tároljuk, hogy POST redirect után is megmaradjon)
$allowedSort = ['id' => 'id', 'username' => 'username', 'full_name' => 'full_name'];
if (isset($_GET['sort']) && array_key_exists($_GET['sort'], $allowedSort)) {
  $_SESSION['_users_sort'] = $_GET['sort'];
}
if (isset($_GET['dir']) && in_array($_GET['dir'], ['asc', 'desc'], true)) {
  $_SESSION['_users_dir'] = $_GET['dir'];
}
$sortCol = $allowedSort[$_SESSION['_users_sort'] ?? 'id'] ?? 'id';
$sortDir = (($_SESSION['_users_dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

$users = $pdo->query("SELECT id, username, email, full_name, is_active, last_login_at, hr_employee_id FROM users ORDER BY {$sortCol} {$sortDir}")->fetchAll();



$hrEmpMap = [];
if ($hrEmployees) {
  foreach ($hrEmployees as $e) { $hrEmpMap[(int)$e['id']] = $e; }
}
require __DIR__ . '/../../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 m-0">Felhasználók</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-primary" href="/admin/import_hr_users.php">HR Import</a>
    <a class="btn btn-sm btn-outline-secondary" href="/admin/import_log.php">Import napló</a>
    <a class="btn btn-sm btn-outline-info" href="/admin/sip.php">SIP Admin</a>
    <a class="btn btn-sm btn-outline-warning" href="/admin/easter_eggs.php">🥚 Easter Eggs</a>
    <a class="btn btn-sm btn-outline-secondary" href="/apps.php">Rendszerek</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <h2 class="h6">Új felhasználó</h2>
    <form method="post" class="row g-2">
      <input type="hidden" name="action" value="create_user">
      <div class="col-12 col-md-3">
        <label class="form-label">Username</label>
        <input class="form-control" name="username" required value="<?= h($form_username) ?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Email (opcionális)</label>
        <input class="form-control" name="email" placeholder="valaki@domain.hu" value="<?= h($form_email) ?>">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Teljes név</label>
        <input class="form-control" name="full_name" required value="<?= h($form_full_name) ?>">
      </div>
      <div class="col-12 col-md-1">
        <label class="form-label">Jelszó</label>
        <input class="form-control" type="password" name="password" required>
      </div>
      <div class="col-12 col-md-1 d-flex align-items-end">
        <button class="btn btn-primary w-100" type="submit">+</button>
      </div>

      <div class="col-12">
        <div class="form-text">
          Jelszó szabályok: min. 6 karakter, és legalább 3 féle legyen a következők közül: kisbetű, nagybetű, szám, speciális karakter.
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <h2 class="h6">Lista</h2>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <?php
            $currentSort = $_SESSION['_users_sort'] ?? 'id';
            $currentDir  = $_SESSION['_users_dir']  ?? 'desc';
            function sortTh(string $col, string $label, string $cs, string $cd): string {
              $nextDir = ($cs === $col && $cd === 'asc') ? 'desc' : 'asc';
              $arrow = ($cs === $col) ? ($cd === 'asc' ? ' ↑' : ' ↓') : '';
              return '<th><a href="?sort=' . $col . '&amp;dir=' . $nextDir . '" class="text-decoration-none text-reset fw-semibold">' . htmlspecialchars($label) . $arrow . '</a></th>';
            }
          ?>
          <tr>
            <th>ID</th>
            <?= sortTh('username', 'Username', $currentSort, $currentDir) ?>
            <th>Email</th>
            <?= sortTh('full_name', 'Név', $currentSort, $currentDir) ?>
            <th>HR munkatárs</th>
            <th>Aktív</th>
            <th>Utolsó login</th>
            <th>Műveletek</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): $isActive = ((int)$u['is_active'] === 1); ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= h((string)$u['username']) ?></td>
            <td><?= h((string)($u['email'] ?? '')) ?></td>
            <td><?= h((string)$u['full_name']) ?></td>
<td>
  <?php if (!$hrEmployees): ?>
    <span class="text-secondary small">HR nincs beállítva</span>
  <?php else: ?>
    <form method="post" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="action" value="set_hr_employee">
      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
      <select class="form-select form-select-sm" name="hr_employee_id" style="min-width:220px">
        <option value="">— nincs —</option>
        <?php foreach ($hrEmployees as $e): ?>
          <?php
            $eid = (int)$e['id'];
            $sel = ((int)($u['hr_employee_id'] ?? 0) === $eid) ? 'selected' : '';
            $label = (string)$e['full_name'];
            if ((int)($e['is_active'] ?? 0) !== 1) $label .= ' (inaktív)';
          ?>
          <option value="<?= $eid ?>" <?= $sel ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-primary" type="submit">Mentés</button>
    </form>
  <?php endif; ?>
</td>
            <td><?= $isActive ? '<span class="badge bg-success">Igen</span>' : '<span class="badge bg-secondary">Nem</span>' ?></td>
            <td><?= h((string)($u['last_login_at'] ?? '')) ?></td>
            <td>
              <a class="btn btn-sm btn-outline-primary" href="/admin/permissions.php?user_id=<?= (int)$u['id'] ?>">Jogok</a>

              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit">
                  <?= $isActive ? 'Inaktivál' : 'Aktivál' ?>
                </button>
              </form>

              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="set_email">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input class="form-control form-control-sm d-inline-block" style="width:200px" name="email" value="<?= h((string)($u['email'] ?? '')) ?>" placeholder="Email">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Email</button>
              </form>

              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="set_password">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input class="form-control form-control-sm d-inline-block" style="width:160px" type="password" name="password" placeholder="Új jelszó" required>
                <button class="btn btn-sm btn-outline-secondary" type="submit">Ment</button>
              </form>

              <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törlöd ezt a felhasználót?');">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Töröl</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../app/views/layout/footer.php'; ?>

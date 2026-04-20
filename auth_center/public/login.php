<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth_bootstrap.php';

$title = 'Belépés';
$loggedIn = false;

// redirect alias (assetmgr etc.)
if (isset($_GET['redirect']) && !isset($_GET['return'])) {
  $_GET['return'] = $_GET['redirect'];
}

$return = safe_path((string)($_GET['return'] ?? ''), '/apps.php');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim((string)($_POST['username'] ?? ''));
  $p = (string)($_POST['password'] ?? '');
  $return = safe_path((string)($_POST['return'] ?? ''), '/apps.php');

  
if (CentralAuth::login($config, $u, $p)) {
  // Ensure hr_employee_id is present in session user payload
  try {
    $uid = 0;
    if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['id'])) {
      $uid = (int)$_SESSION['user']['id'];
    } elseif (!empty($_SESSION['user_id'])) {
      $uid = (int)$_SESSION['user_id'];
    } elseif (!empty($_SESSION['uid'])) {
      $uid = (int)$_SESSION['uid'];
    }
    if ($uid > 0) {
      $pdo = auth_pdo($config);
      $q = $pdo->prepare("SELECT hr_employee_id FROM users WHERE id=?");
      $q->execute([$uid]);
      $hrEmpId = $q->fetchColumn();
      if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) $_SESSION['user'] = [];
      $_SESSION['user']['hr_employee_id'] = ($hrEmpId === null ? null : (int)$hrEmpId);
      $_SESSION['hr_employee_id'] = ($_SESSION['user']['hr_employee_id'] ?? null);
    }
  } catch (Throwable $e) { /* ignore */ }

  header('Location: ' . $return);
  exit;
}
  $error = 'Hibás felhasználónév vagy jelszó.';
}

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-md-6 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h4 mb-3">Belépés</h1>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
          <input type="hidden" name="return" value="<?= h($return) ?>">

          <div class="mb-3">
            <label class="form-label">Felhasználó</label>
            <input class="form-control" name="username" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Jelszó</label>
            <input class="form-control" type="password" name="password" required>
          </div>

          <button class="btn btn-primary w-100" type="submit">Belépés</button>
        </form>

        <div class="text-secondary small mt-3">Ha nem tudsz belépni, szólj az adminnak.</div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

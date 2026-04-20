<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth;
use App\Middleware;
use App\Db;
use App\Csrf;
use App\Helpers;

Auth::start();
Middleware::requireAuth();
Auth::requireRole(1);

$pdo = Db::pdo();

// Paraméterek
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$return = $_GET['return'] ?? 'list';
if (!in_array($return, ['list','calendar'], true)) {
    $return = 'list';
}
$backUrl = ($return === 'calendar') ? '/ts_calendar.php' : '/ts_worker_days.php';

// Dolgozók
$workers = $pdo->query("
  SELECT id, full_name, position
  FROM workers
  WHERE is_active = 1
  ORDER BY full_name
")->fetchAll();

// Státusz típusok
$statusTypes = $pdo->query("
  SELECT id, name, color
  FROM worker_status_types
  ORDER BY name
")->fetchAll();

$today = date('Y-m-d');
$data = [
    'worker_id'       => null,
    'status_type_id'  => null,
    'status_date'     => $today,
    'status_date_to'  => $today,
];

$err = null;

// Szerkesztés mód: betöltjük (GET)
if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare("
      SELECT *
      FROM worker_day_statuses
      WHERE id = ?
    ");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        exit('A dolgozói státusz nem található.');
    }

    if (empty($row['status_date_to'])) {
        $row['status_date_to'] = $row['status_date'];
    }

    $data['worker_id']      = $row['worker_id'];
    $data['status_type_id'] = $row['status_type_id'];
    $data['status_date']    = $row['status_date'];
    $data['status_date_to'] = $row['status_date_to'];
}

// Mentés (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }

    $id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $return  = $_POST['return'] ?? $return;
    if (!in_array($return, ['list','calendar'], true)) {
        $return = 'list';
    }
    $backUrl = ($return === 'calendar') ? '/ts_calendar.php' : '/ts_worker_days.php';

    $worker_id      = isset($_POST['worker_id']) ? (int)$_POST['worker_id'] : 0;
    $status_type_id = isset($_POST['status_type_id']) ? (int)$_POST['status_type_id'] : 0;
    $status_date    = trim($_POST['status_date'] ?? '');
    $status_date_to = trim($_POST['status_date_to'] ?? '');

    if ($status_date !== '' && $status_date_to === '') {
        $status_date_to = $status_date;
    }

    if ($worker_id <= 0) {
        $err = 'A dolgozó kiválasztása kötelező.';
    } elseif ($status_type_id <= 0) {
        $err = 'A státusz típus kiválasztása kötelező.';
    } elseif ($status_date === '') {
        $err = 'A kezdő dátum megadása kötelező.';
    } elseif ($status_date_to === '') {
        $err = 'A záró dátum megadása kötelező.';
    } elseif (strtotime($status_date_to) < strtotime($status_date)) {
        $err = 'A záró dátum nem lehet korábbi, mint a kezdő dátum.';
    } else {
        if ($id > 0) {
            $st = $pdo->prepare("
              UPDATE worker_day_statuses
              SET worker_id = ?, status_type_id = ?, status_date = ?, status_date_to = ?
              WHERE id = ?
            ");
            $st->execute([
                $worker_id,
                $status_type_id,
                $status_date,
                $status_date_to,
                $id,
            ]);
        } else {
            $st = $pdo->prepare("
              INSERT INTO worker_day_statuses (worker_id, status_type_id, status_date, status_date_to)
              VALUES (?,?,?,?)
            ");
            $st->execute([
                $worker_id,
                $status_type_id,
                $status_date,
                $status_date_to,
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        Helpers::flash('ok', 'Dolgozói státusz elmentve.');
        header('Location: '.$backUrl);
        exit;
    }

    // hiba esetén visszatöltjük a formot
    $data['worker_id']      = $worker_id;
    $data['status_type_id'] = $status_type_id;
    $data['status_date']    = $status_date;
    $data['status_date_to'] = $status_date_to;
}
?>
<div class="card">
  <div class="card-body">
    <h1 class="h5 mb-3">
      <?php if ($id > 0): ?>
        Dolgozói státusz szerkesztése
      <?php else: ?>
        Új dolgozói státusz
      <?php endif; ?>
    </h1>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= Helpers::e($err) ?></div>
    <?php endif; ?>

    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="return" value="<?= Helpers::e($return) ?>">

      <div class="row g-3">
        <div class="col-md-6">
          <label for="worker_id" class="form-label">Dolgozó</label>
          <select name="worker_id" id="worker_id" class="form-select" required>
            <option value="">— Válassz dolgozót —</option>
            <?php foreach ($workers as $w): ?>
              <option value="<?= (int)$w['id'] ?>"
                <?= (int)$data['worker_id'] === (int)$w['id'] ? 'selected' : '' ?>>
                <?= Helpers::e($w['full_name']) ?>
                <?php if ($w['position']): ?>
                  (<?= Helpers::e($w['position']) ?>)
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label for="status_type_id" class="form-label">Státusz típus</label>
          <select name="status_type_id" id="status_type_id" class="form-select" required>
            <option value="">— Válassz státusz típust —</option>
            <?php foreach ($statusTypes as $stt): ?>
              <option value="<?= (int)$stt['id'] ?>"
                <?= (int)$data['status_type_id'] === (int)$stt['id'] ? 'selected' : '' ?>>
                <?= Helpers::e($stt['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label for="status_date" class="form-label">Dátum -tól</label>
          <input type="date" name="status_date" id="status_date" class="form-control"
                 value="<?= Helpers::e((string)$data['status_date']) ?>" required>
        </div>

        <div class="col-md-3">
          <label for="status_date_to" class="form-label">Dátum -ig</label>
          <input type="date" name="status_date_to" id="status_date_to" class="form-control"
                 value="<?= Helpers::e((string)$data['status_date_to']) ?>" required>
        </div>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Mentés</button>
        <a href="<?= Helpers::e($backUrl) ?>" class="btn btn-secondary">Mégse</a>
      </div>
    </form>
  </div>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php';
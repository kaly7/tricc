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
$currentUser = Auth::user();
$currentUserId = $currentUser['id'] ?? null;

// Paraméterek
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$copyId  = isset($_GET['copy_id']) ? (int)$_GET['copy_id'] : 0;
$return  = $_GET['return'] ?? 'list';
if (!in_array($return, ['list', 'calendar'], true)) {
    $return = 'list';
}
$backUrl = $return === 'calendar' ? '/ts_calendar.php' : '/ts_events.php';

$statusLabels = [
    'planned'     => 'Tervezett',
    'in_progress' => 'Folyamatban',
    'done'        => 'Kész',
    'cancelled'   => 'Törölve',
];

// Szótárak
$workTypes = $pdo->query("
  SELECT id, name, color
  FROM work_types
  WHERE is_active = 1
  ORDER BY name
")->fetchAll();

$projects = $pdo->query("
  SELECT id, number, name
  FROM projects
  ORDER BY number, name
")->fetchAll();

$workers = $pdo->query("
  SELECT id, full_name, position
  FROM workers
  WHERE is_active = 1
  ORDER BY full_name
")->fetchAll();

// Alap adatok (most már date_to is)
$today = date('Y-m-d');
$data = [
    'work_date'             => $today,
    'date_to'               => $today,
    'time_from'             => '',
    'time_to'               => '',
    'work_type_id'          => null,
    'title'                 => '',
    'location'              => '',
    'project_id'            => null,
    'status'                => 'planned',
    'requires_notification' => 0,
    'notes'                 => '',
];

$selectedWorkers = [];
$err = null;

// Ha szerkesztünk: betöltjük az eseményt
if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM work_events WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        exit('A munkavégzési esemény nem található.');
    }

    // Ha még nincs kitöltve a date_to (régi rekord), legyen = work_date
    if (empty($row['date_to'])) {
        $row['date_to'] = $row['work_date'];
    }

    $data = array_merge($data, $row);

    // dolgozók az eseményhez
    $st = $pdo->prepare("
      SELECT worker_id
      FROM work_event_workers
      WHERE work_event_id = ?
    ");
    $st->execute([$id]);
    $selectedWorkers = array_map('intval', array_column($st->fetchAll(), 'worker_id'));
}

// Ha NEM szerkesztünk, hanem MÁSOLUNK (copy_id) és GET kérés:
if ($id === 0 && $copyId > 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare("SELECT * FROM work_events WHERE id = ?");
    $st->execute([$copyId]);
    $src = $st->fetch();
    if ($src) {
        $dateFrom = $src['work_date'];
        $dateTo   = $src['date_to'] ?: $src['work_date'];

        $data['work_date']             = $dateFrom;
        $data['date_to']               = $dateTo;
        $data['time_from']             = $src['time_from'];
        $data['time_to']               = $src['time_to'];
        $data['work_type_id']          = $src['work_type_id'];
        $data['title']                 = $src['title'];
        $data['location']              = $src['location'];
        $data['project_id']            = $src['project_id'];
        $data['status']                = $src['status'];
        $data['requires_notification'] = $src['requires_notification'];
        $data['notes']                 = $src['notes'];

        // dolgozók másolása
        $st = $pdo->prepare("
          SELECT worker_id
          FROM work_event_workers
          WHERE work_event_id = ?
        ");
        $st->execute([$copyId]);
        $selectedWorkers = array_map('intval', array_column($st->fetchAll(), 'worker_id'));
    }
}

// Mentés
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }

    $id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $return  = $_POST['return'] ?? $return;
    if (!in_array($return, ['list', 'calendar'], true)) {
        $return = 'list';
    }
    $backUrl = $return === 'calendar' ? '/ts_calendar.php' : '/ts_events.php';

    $work_date   = trim($_POST['work_date'] ?? '');
    $date_to     = trim($_POST['date_to'] ?? '');
    $time_from   = trim($_POST['time_from'] ?? '');
    $time_to     = trim($_POST['time_to'] ?? '');
    $work_type_id= isset($_POST['work_type_id']) && $_POST['work_type_id'] !== '' ? (int)$_POST['work_type_id'] : null;
    $title       = trim($_POST['title'] ?? '');
    $location    = trim($_POST['location'] ?? '');
    $project_id  = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
    $status      = $_POST['status'] ?? 'planned';
    $requires_notification = isset($_POST['requires_notification']) ? 1 : 0;
    $notes       = trim($_POST['notes'] ?? '');
    $worker_ids  = isset($_POST['worker_ids']) && is_array($_POST['worker_ids'])
                   ? array_map('intval', $_POST['worker_ids']) : [];

    // Dátum -ig üresen hagyva: = -tól
    if ($work_date !== '' && $date_to === '') {
        $date_to = $work_date;
    }

    // Minimális validálás
    if ($work_date === '') {
        $err = 'A dátum (-tól) megadása kötelező.';
    } elseif ($date_to === '') {
        $err = 'A dátum (-ig) megadása kötelező.';
    } elseif (strtotime($date_to) < strtotime($work_date)) {
        $err = 'A záró dátum nem lehet korábbi, mint a kezdő dátum.';
    } elseif (!$work_type_id) {
        $err = 'A munkavégzés típus kiválasztása kötelező.';
    } elseif ($title === '') {
        $err = 'A cím megadása kötelező.';
    } elseif (!array_key_exists($status, $statusLabels)) {
        $err = 'Érvénytelen státusz.';
    } else {
        if ($id > 0) {
            // Update
            $st = $pdo->prepare("
              UPDATE work_events
              SET work_date = ?, date_to = ?, time_from = ?, time_to = ?, work_type_id = ?,
                  title = ?, location = ?, project_id = ?, status = ?,
                  requires_notification = ?, notes = ?
              WHERE id = ?
            ");
            $st->execute([
                $work_date,
                $date_to,
                $time_from !== '' ? $time_from : null,
                $time_to   !== '' ? $time_to   : null,
                $work_type_id,
                $title,
                $location !== '' ? $location : null,
                $project_id,
                $status,
                $requires_notification,
                $notes !== '' ? $notes : null,
                $id,
            ]);
        } else {
            // Insert (új vagy másolat mentése újként)
            $st = $pdo->prepare("
              INSERT INTO work_events
                (work_date, date_to, time_from, time_to, work_type_id,
                 title, location, project_id, status,
                 requires_notification, notes, created_by_user_id)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $st->execute([
                $work_date,
                $date_to,
                $time_from !== '' ? $time_from : null,
                $time_to   !== '' ? $time_to   : null,
                $work_type_id,
                $title,
                $location !== '' ? $location : null,
                $project_id,
                $status,
                $requires_notification,
                $notes !== '' ? $notes : null,
                $currentUserId,
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        // Dolgozók mentése
        $st = $pdo->prepare("DELETE FROM work_event_workers WHERE work_event_id = ?");
        $st->execute([$id]);

        if (!empty($worker_ids)) {
            $st = $pdo->prepare("
              INSERT INTO work_event_workers (work_event_id, worker_id)
              VALUES (?,?)
            ");
            foreach ($worker_ids as $wid) {
                if ($wid > 0) {
                    $st->execute([$id, $wid]);
                }
            }
        }

        Helpers::flash('ok', 'Munkavégzési esemény elmentve.');
        header('Location: '.$backUrl);
        exit;
    }

    // hiba esetén töltsük vissza a formot
    $data = [
        'work_date'             => $work_date,
        'date_to'               => $date_to,
        'time_from'             => $time_from,
        'time_to'               => $time_to,
        'work_type_id'          => $work_type_id,
        'title'                 => $title,
        'location'              => $location,
        'project_id'            => $project_id,
        'status'                => $status,
        'requires_notification' => $requires_notification,
        'notes'                 => $notes,
    ];
    $selectedWorkers = $worker_ids;
}
?>
<div class="card">
  <div class="card-body">
    <h1 class="h5 mb-3">
      <?php if ($id > 0): ?>
        Munkavégzés szerkesztése
      <?php elseif ($copyId > 0 && $_SERVER['REQUEST_METHOD'] === 'GET'): ?>
        Munkavégzés másolása
      <?php else: ?>
        Új munkavégzés
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
        <div class="col-md-3">
          <label for="work_date" class="form-label">Dátum -tól</label>
          <input type="date" name="work_date" id="work_date" class="form-control"
                 value="<?= Helpers::e((string)$data['work_date']) ?>" required>
        </div>

        <div class="col-md-3">
          <label for="date_to" class="form-label">Dátum -ig</label>
          <input type="date" name="date_to" id="date_to" class="form-control"
                 value="<?= Helpers::e((string)$data['date_to']) ?>" required>
        </div>

        <div class="col-md-2">
          <label for="time_from" class="form-label">Idő -tól</label>
          <input type="time" name="time_from" id="time_from" class="form-control"
                 value="<?= Helpers::e((string)$data['time_from']) ?>">
        </div>

        <div class="col-md-2">
          <label for="time_to" class="form-label">Idő -ig</label>
          <input type="time" name="time_to" id="time_to" class="form-control"
                 value="<?= Helpers::e((string)$data['time_to']) ?>">
        </div>

        <div class="col-md-5">
          <label for="work_type_id" class="form-label">Munkavégzés típusa</label>
          <select name="work_type_id" id="work_type_id" class="form-select" required>
            <option value="">— Válassz típust —</option>
            <?php foreach ($workTypes as $t): ?>
              <option value="<?= (int)$t['id'] ?>"
                <?= (int)$data['work_type_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                <?= Helpers::e($t['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-7">
          <label for="title" class="form-label">Cím / rövid leírás</label>
          <input type="text" name="title" id="title" class="form-control"
                 value="<?= Helpers::e((string)$data['title']) ?>" required>
        </div>

        <div class="col-md-6">
          <label for="project_id" class="form-label">Projekt</label>
          <select name="project_id" id="project_id" class="form-select">
            <option value="">— Nincs hozzárendelve —</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?= (int)$p['id'] ?>"
                <?= (int)$data['project_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                <?= Helpers::e($p['number']) ?> – <?= Helpers::e($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label for="location" class="form-label">Helyszín</label>
          <input type="text" name="location" id="location" class="form-control"
                 value="<?= Helpers::e((string)$data['location']) ?>">
        </div>

        <div class="col-md-3">
          <label for="status" class="form-label">Státusz</label>
          <select name="status" id="status" class="form-select">
            <?php foreach ($statusLabels as $k => $lbl): ?>
              <option value="<?= Helpers::e($k) ?>"
                <?= ($data['status'] ?? 'planned') === $k ? 'selected' : '' ?>>
                <?= Helpers::e($lbl) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3 d-flex align-items-center">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="requires_notification"
                   name="requires_notification"
                   <?= !empty($data['requires_notification']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="requires_notification">
              Bejelentés szükséges
            </label>
          </div>
        </div>

        <div class="col-md-12">
          <label for="notes" class="form-label">Megjegyzés</label>
          <textarea name="notes" id="notes" rows="3" class="form-control"><?= Helpers::e((string)$data['notes']) ?></textarea>
        </div>

        <div class="col-md-12">
          <label class="form-label">Dolgozók (akik részt vesznek)</label>
          <?php if (!$workers): ?>
            <div class="text-muted small">Még nincs rögzítve kolléga.</div>
          <?php else: ?>
            <div class="border rounded p-2" style="max-height: 220px; overflow-y: auto;">
              <?php foreach ($workers as $w): ?>
                <div class="form-check">
                  <input class="form-check-input"
                         type="checkbox"
                         name="worker_ids[]"
                         id="w_<?= (int)$w['id'] ?>"
                         value="<?= (int)$w['id'] ?>"
                    <?= in_array((int)$w['id'], $selectedWorkers, true) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="w_<?= (int)$w['id'] ?>">
                    <?= Helpers::e($w['full_name']) ?>
                    <?php if ($w['position']): ?>
                      <span class="text-muted small">(<?= Helpers::e($w['position']) ?>)</span>
                    <?php endif; ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
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
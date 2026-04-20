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

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row  = null;
$err  = null;

// Alapértelmezett értékek új rekordhoz
$data = [
  'name'                         => '',
  'color'                        => '#007bff',
  'requires_notification'        => 0,
  'default_notification_lead_minutes' => null,
  'note'                         => '',
  'is_active'                    => 1,
];

// Ha szerkesztünk, töltsük be
if ($id > 0) {
  $st = $pdo->prepare('SELECT * FROM work_types WHERE id = ?');
  $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) {
    http_response_code(404);
    exit('A munkavégzés típus nem található.');
  }
  $data = $row;
}

// Mentés POST-ból
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Csrf::check($_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    exit('CSRF hiba');
  }

  $name  = trim($_POST['name'] ?? '');
  $color = trim($_POST['color'] ?? '');
  $requires_notification = isset($_POST['requires_notification']) ? 1 : 0;
  $default_lead = trim($_POST['default_notification_lead_minutes'] ?? '');
  $note  = trim($_POST['note'] ?? '');
  $is_active = isset($_POST['is_active']) ? 1 : 0;

  $default_lead_val = $default_lead === '' ? null : (int)$default_lead;

  if ($name === '') {
    $err = 'A név megadása kötelező.';
  } elseif (!preg_match('~^#[0-9A-Fa-f]{6}$~', $color)) {
    $err = 'A színkódnak #RRGGBB formátumúnak kell lennie.';
  } else {
    if ($id > 0) {
      // Update
      $st = $pdo->prepare('
        UPDATE work_types
        SET name = ?, color = ?, requires_notification = ?, 
            default_notification_lead_minutes = ?, note = ?, is_active = ?
        WHERE id = ?
      ');
      $st->execute([
        $name,
        $color,
        $requires_notification,
        $default_lead_val,
        $note,
        $is_active,
        $id,
      ]);
      Helpers::flash('ok', 'Munkavégzés típus frissítve.');
    } else {
      // Insert
      $st = $pdo->prepare('
        INSERT INTO work_types (name, color, requires_notification, default_notification_lead_minutes, note, is_active)
        VALUES (?,?,?,?,?,?)
      ');
      $st->execute([
        $name,
        $color,
        $requires_notification,
        $default_lead_val,
        $note,
        $is_active,
      ]);
      Helpers::flash('ok', 'Munkavégzés típus létrehozva.');
    }

    header('Location: /ts_work_types.php');
    exit;
  }

  // ha hiba van, töltsük vissza a formadatokat
  $data = [
    'name'  => $name,
    'color' => $color,
    'requires_notification' => $requires_notification,
    'default_notification_lead_minutes' => $default_lead_val,
    'note'  => $note,
    'is_active' => $is_active,
  ];
}

?>
<div class="card">
  <div class="card-body">
    <h1 class="h5 mb-3">
      <?= $id > 0 ? 'Munkavégzés típus szerkesztése' : 'Új munkavégzés típus' ?>
    </h1>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= Helpers::e($err) ?></div>
    <?php endif; ?>

    <form method="post">
      <?= Csrf::field() ?>

      <div class="mb-3">
        <label for="name" class="form-label">Név</label>
        <input type="text" name="name" id="name" class="form-control"
               value="<?= Helpers::e((string)$data['name']) ?>" required>
      </div>

      <div class="mb-3">
        <label for="color" class="form-label">Színkód (#RRGGBB)</label>
        <div class="d-flex gap-2">
          <input type="text" name="color" id="color" class="form-control"
                 value="<?= Helpers::e((string)$data['color']) ?>" maxlength="7">
          <input type="color" class="form-control form-control-color"
                 value="<?= Helpers::e((string)$data['color']) ?>"
                 onchange="document.getElementById('color').value=this.value;">
        </div>
      </div>

      <div class="mb-3 form-check">
        <input class="form-check-input" type="checkbox" id="requires_notification" name="requires_notification"
               <?= !empty($data['requires_notification']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="requires_notification">
          Bejelentési kötelezettséggel jár
        </label>
      </div>

      <div class="mb-3">
        <label for="default_notification_lead_minutes" class="form-label">
          Alapértelmezett figyelmeztetési idő (percben, opcionális)
        </label>
        <input type="number" name="default_notification_lead_minutes" id="default_notification_lead_minutes"
               class="form-control"
               value="<?= $data['default_notification_lead_minutes'] !== null ? (int)$data['default_notification_lead_minutes'] : '' ?>"
               min="0" step="1">
      </div>

      <div class="mb-3">
        <label for="note" class="form-label">Megjegyzés</label>
        <textarea name="note" id="note" rows="3" class="form-control"><?= Helpers::e((string)$data['note']) ?></textarea>
      </div>

      <div class="mb-3 form-check">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
               <?= !empty($data['is_active']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="is_active">
          Aktív
        </label>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Mentés</button>
        <a href="/ts_work_types.php" class="btn btn-secondary">Mégse</a>
      </div>
    </form>
  </div>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php';
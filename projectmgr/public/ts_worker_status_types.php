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
Auth::requireRole(1); // csak admin

$pdo = Db::pdo();

$err = null;
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Törlés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare("DELETE FROM worker_status_types WHERE id = ?");
        $st->execute([$id]);
        Helpers::flash('ok', 'Státusztípus törölve.');
    }
    header('Location: /ts_worker_status_types.php');
    exit;
}

// Mentés (create/update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }

    $id        = (int)($_POST['id'] ?? 0);
    $name      = trim($_POST['name'] ?? '');
    $color     = trim($_POST['color'] ?? '#0d6efd');
    $sort      = (int)($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $err = 'A megnevezés megadása kötelező.';
    } else {
        if ($id > 0) {
            $st = $pdo->prepare("
              UPDATE worker_status_types
              SET name = ?, color = ?, sort_order = ?, is_active = ?
              WHERE id = ?
            ");
            $st->execute([$name, $color, $sort, $is_active, $id]);
            Helpers::flash('ok', 'Státusztípus frissítve.');
        } else {
            $st = $pdo->prepare("
              INSERT INTO worker_status_types (name, color, sort_order, is_active)
              VALUES (?,?,?,?)
            ");
            $st->execute([$name, $color, $sort, $is_active]);
            Helpers::flash('ok', 'Státusztípus létrehozva.');
        }
        header('Location: /ts_worker_status_types.php');
        exit;
    }
}

// Ha szerkesztünk, töltsük be az adatokat
$editRow = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM worker_status_types WHERE id = ?");
    $st->execute([$editId]);
    $editRow = $st->fetch();
    if (!$editRow) {
        $editId = 0;
    }
}

// Lista
$rows = $pdo->query("
  SELECT *
  FROM worker_status_types
  ORDER BY sort_order, name
")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Dolgozói státusz típusok</h1>
</div>

<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-3"><?= $editId ? 'Státusztípus szerkesztése' : 'Új státusztípus' ?></h2>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= Helpers::e($err) ?></div>
    <?php endif; ?>

    <form method="post" class="row g-2">
      <?= Csrf::field() ?>
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="id" value="<?= (int)$editId ?>">

      <div class="col-md-4">
        <label class="form-label" for="name">Megnevezés</label>
        <input type="text" name="name" id="name" class="form-control"
               value="<?= Helpers::e($editRow['name'] ?? '') ?>" required>
      </div>

      <div class="col-md-2">
        <label class="form-label" for="color">Szín</label>
        <input type="color" name="color" id="color"
               class="form-control form-control-color"
               value="<?= Helpers::e($editRow['color'] ?? '#0d6efd') ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label" for="sort_order">Sorrend</label>
        <input type="number" name="sort_order" id="sort_order" class="form-control"
               value="<?= Helpers::e((string)($editRow['sort_order'] ?? 0)) ?>">
      </div>

      <div class="col-md-2 d-flex align-items-center">
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                 <?= ($editRow['is_active'] ?? 1) ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_active">Aktív</label>
        </div>
      </div>

      <div class="col-md-12 mt-2">
        <button type="submit" class="btn btn-primary btn-sm">Mentés</button>
        <?php if ($editId): ?>
          <a href="/ts_worker_status_types.php" class="btn btn-secondary btn-sm">Mégse</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Megnevezés</th>
            <th>Szín</th>
            <th>Sorrend</th>
            <th>Aktív</th>
            <th style="width:130px;">Műveletek</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="5" class="text-center text-muted py-3">
              Még nincs státusztípus.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= Helpers::e($r['name']) ?></td>
              <td>
                <span class="badge"
                      style="background-color: <?= Helpers::e((string)$r['color']) ?>;">
                  &nbsp;&nbsp;
                </span>
                <code class="small"><?= Helpers::e((string)$r['color']) ?></code>
              </td>
              <td><?= (int)$r['sort_order'] ?></td>
              <td><?= $r['is_active'] ? 'Igen' : 'Nem' ?></td>
              <td>
                <a href="/ts_worker_status_types.php?id=<?= (int)$r['id'] ?>"
                   class="btn btn-sm btn-primary">Szerk.</a>
                <form method="post" action="/ts_worker_status_types.php"
                      class="d-inline"
                      onsubmit="return confirm('Biztosan törlöd ezt a státusztípust?');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="_action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit"
                          class="btn btn-sm btn-outline-danger">
                    Törlés
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
require dirname(__DIR__).'/views/_layout_bottom.php';
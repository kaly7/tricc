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
$err  = null;

$data = [
    'name'        => '',
    'description' => '',
    'is_active'   => 1,
];

if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM qualifications WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();

    if (!$row) {
        http_response_code(404);
        exit('A szakképesítés nem található.');
    }

    $data = $row;
}

// Mentés
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }

    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $err = 'A név megadása kötelező.';
    } else {
        try {
            if ($id > 0) {
                $st = $pdo->prepare('
                    UPDATE qualifications
                    SET name = ?, description = ?, is_active = ?
                    WHERE id = ?
                ');
                $st->execute([
                    $name,
                    $description !== '' ? $description : null,
                    $is_active,
                    $id,
                ]);
                Helpers::flash('ok', 'Szakképesítés frissítve.');
            } else {
                $st = $pdo->prepare('
                    INSERT INTO qualifications (name, description, is_active)
                    VALUES (?, ?, ?)
                ');
                $st->execute([
                    $name,
                    $description !== '' ? $description : null,
                    $is_active,
                ]);
                Helpers::flash('ok', 'Szakképesítés létrehozva.');
            }

            header('Location: /ts_qualifications.php');
            exit;
        } catch (PDOException $e) {
            // pl. duplikált név esetén UNIQUE constraint
            if ($e->getCode() === '23000') {
                $err = 'Már létezik ilyen nevű szakképesítés.';
            } else {
                throw $e;
            }
        }
    }

    // Hiba esetén visszatöltjük a formadatot
    $data = [
        'name'        => $name,
        'description' => $description,
        'is_active'   => $is_active,
    ];
}
?>
<div class="card">
  <div class="card-body">
    <h1 class="h5 mb-3">
      <?= $id > 0 ? 'Szakképesítés szerkesztése' : 'Új szakképesítés' ?>
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
        <label for="description" class="form-label">Leírás</label>
        <textarea name="description" id="description" rows="3" class="form-control"><?= Helpers::e((string)$data['description']) ?></textarea>
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
        <a href="/ts_qualifications.php" class="btn btn-secondary">Mégse</a>
      </div>
    </form>
  </div>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php';
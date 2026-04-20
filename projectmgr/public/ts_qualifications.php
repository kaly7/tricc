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

// Törlés (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare('DELETE FROM qualifications WHERE id = ?');
        $st->execute([$id]);
        Helpers::flash('ok', 'Szakképesítés törölve.');
    }

    header('Location: /ts_qualifications.php');
    exit;
}

// Lista lekérdezése
$rows = $pdo->query('SELECT * FROM qualifications ORDER BY is_active DESC, name ASC')->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Szakképesítések</h1>
  <a class="btn btn-success" href="/ts_qualification_edit.php">Új szakképesítés</a>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Név</th>
            <th>Leírás</th>
            <th>Aktív</th>
            <th style="width: 130px;">Műveletek</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Még nincs rögzített szakképesítés.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= Helpers::e($r['name']) ?></td>
              <td><?= nl2br(Helpers::e((string)$r['description'])) ?></td>
              <td><?= $r['is_active'] ? 'Igen' : 'Nem' ?></td>
              <td>
                <a class="btn btn-sm btn-primary" href="/ts_qualification_edit.php?id=<?= (int)$r['id'] ?>">Szerk.</a>
                <form method="post" action="/ts_qualifications.php" class="d-inline"
                      onsubmit="return confirm('Biztosan törlöd ezt a szakképesítést?');">
                  <input type="hidden" name="_action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <?= Csrf::field() ?>
                  <button type="submit" class="btn btn-sm btn-outline-danger">Törlés</button>
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

<?php require dirname(__DIR__).'/views/_layout_bottom.php';
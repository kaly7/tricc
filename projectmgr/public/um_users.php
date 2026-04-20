<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start();
Middleware::requireAuth();
Auth::requireRole(1);

$pdo = Db::pdo();
$rows = $pdo->query('SELECT u.id,u.name,u.email,u.is_active,r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id DESC')->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Felhasználók (UM – egyszerű)</h1>
  <a class="btn btn-success" href="/um_user_create.php">Új felhasználó</a>
</div>
<div class="card p-0">
  <table class="table table-striped m-0">
    <thead><tr><th>ID</th><th>Név</th><th>Email</th><th>Szerep</th><th>Aktív</th><th></th></tr></thead>
    <tbody>
      <?php foreach($rows as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= htmlspecialchars($u['name']) ?></td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td><?= htmlspecialchars($u['role_name']) ?></td>
          <td><?= ((int)$u['is_active']===1) ? '✔' : '—' ?></td>
          <td>
            <a class="btn btn-sm btn-primary" href="/um_user_edit.php?id=<?= (int)$u['id'] ?>">Szerkesztés</a>
            <form method="post" action="/um_user_delete.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd ezt a felhasználót?');">
              <?= \App\Csrf::field() ?>
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn-sm btn-danger">Törlés</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';

<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers;

Auth::start(); Middleware::requireAuth(); Auth::requireRole(1);

$pdo = Db::pdo();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $action = $_POST['action'] ?? '';
  if ($action==='add') {
    $path = trim($_POST['path'] ?? '');
    $sort = (int)($_POST['sort'] ?? 0);
    if ($path !== '') {
      $st = $pdo->prepare('INSERT INTO dir_templates (path,sort) VALUES (?,?) ON DUPLICATE KEY UPDATE sort=VALUES(sort)');
      $st->execute([$path,$sort]);
      Helpers::flash('ok','Sablon mappa hozzáadva/frissítve');
    }
  } elseif ($action==='del') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      $st=$pdo->prepare('DELETE FROM dir_templates WHERE id=?');
      $st->execute([$id]);
      Helpers::flash('ok','Sablon mappa törölve');
    }
  }
  header('Location: /pm_dir_template.php'); exit;
}

$rows = $pdo->query('SELECT * FROM dir_templates ORDER BY sort, path')->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Könyvtárséma — globális sablon</h1>
  <a class="btn btn-secondary" href="/pm_projects.php">Vissza</a>
</div>

<div class="card p-3 mb-3">
  <form method="post" class="row g-2 align-items-end">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="action" value="add">
    <div class="col-md-8">
      <label class="form-label">Relatív elérési út (pl. 01_Dokumentacio/Alap)</label>
      <input name="path" class="form-control" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Sorrend</label>
      <input name="sort" type="number" class="form-control" value="0">
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary">Hozzáad / Frissít</button>
    </div>
  </form>
</div>

<div class="card p-0">
  <table class="table table-striped m-0">
    <thead><tr><th>#</th><th>Útvonal</th><th>Sorrend</th><th></th></tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['path']) ?></td>
          <td><?= (int)$r['sort'] ?></td>
          <td>
            <form method="post" class="d-inline" onsubmit="return confirm('Törlöd ezt a sort?');">
              <?= \App\Csrf::field() ?>
              <input type="hidden" name="action" value="del">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Törlés</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';

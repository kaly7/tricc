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
$project_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$project_id) { http_response_code(400); exit('Hibás projekt ID'); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $action = $_POST['action'] ?? '';
  if ($action === 'add') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $role = ($_POST['role'] ?? 'member');
    if ($uid>0) {
      $st = $pdo->prepare('INSERT INTO project_members (project_id,user_id,role) VALUES (?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role)');
      $st->execute([$project_id,$uid,$role]);
      Helpers::flash('ok','Hozzárendelve');
    }
  } elseif ($action === 'remove') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid>0) {
      $st = $pdo->prepare('DELETE FROM project_members WHERE project_id=? AND user_id=?');
      $st->execute([$project_id,$uid]);
      Helpers::flash('ok','Eltávolítva');
    }
  }
  header('Location: /pm_project_assign.php?id='.$project_id); exit;
}

$proj = $pdo->prepare('SELECT * FROM projects WHERE id=?');
$proj->execute([$project_id]);
$project = $proj->fetch(PDO::FETCH_ASSOC);
if (!$project) { http_response_code(404); exit('Projekt nem található'); }

$users = $pdo->query('SELECT id,name,email FROM users WHERE is_active=1 ORDER BY name')->fetchAll();
$members = $pdo->prepare('SELECT pm.user_id, pm.role, u.name, u.email FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=? ORDER BY u.name');
$members->execute([$project_id]);
$mem = $members->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Felhasználók rendelése: <?= htmlspecialchars($project['number'].' — '.$project['name']) ?></h1>
  <a class="btn btn-secondary" href="/pm_project_edit.php?id=<?= (int)$project_id ?>">Vissza a projekthez</a>
</div>
<div class="row g-3">
  <div class="col-md-6">
    <div class="card p-3">
      <h2 class="h6 mb-3">Hozzárendelés</h2>
      <form method="post">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="action" value="add">
        <div class="row g-2">
          <div class="col-8">
            <select name="user_id" class="form-select" required>
              <option value="">— Válassz felhasználót —</option>
              <?php foreach($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name'].' <'.$u['email'].'>') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-4">
            <select name="role" class="form-select">
              <?php foreach (['manager'=>'manager','member'=>'member','viewer'=>'viewer'] as $k=>$v): ?>
                <option value="<?=$k?>"><?=$v?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mt-2">
          <button class="btn btn-primary btn-sm">Hozzárendel</button>
        </div>
      </form>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card p-3">
      <h2 class="h6 mb-3">Jelenlegi tagok</h2>
      <table class="table table-sm">
        <thead><tr><th>Név</th><th>Szerep</th><th></th></tr></thead>
        <tbody>
        <?php foreach($mem as $m): ?>
          <tr>
            <td><?= htmlspecialchars($m['name'].' <'.$m['email'].'>') ?></td>
            <td><?= htmlspecialchars($m['role']) ?></td>
            <td>
              <form method="post" class="d-inline" onsubmit="return confirm('Eltávolítod?');">
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Eltávolít</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';

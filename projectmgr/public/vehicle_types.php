<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$user = Auth::user();
$isAdmin = isset($user['role_id']) && (int)$user['role_id'] === 1;
if (!$isAdmin) { http_response_code(403); exit('Nincs jogosultság.'); }

$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!\App\Csrf::check($_POST['csrf_token'] ?? null)) {
	http_response_code(400);
	exit('CSRF hiba.');
    }  
  $name = trim((string)($_POST['name'] ?? ''));
  $sort = (int)($_POST['sort'] ?? 0);
  if ($name==='') $err='A név kötelező.';
  if (!$err) {
    $st = $pdo->prepare("INSERT INTO vehicle_types (name, sort, is_active) VALUES (?, ?, 1)");
    $st->execute([$name, $sort]);
    Helpers::flash('success','Fajta hozzáadva.');
    header('Location: /vehicle_types.php'); exit;
  }
}

$rows = $pdo->query("SELECT * FROM vehicle_types ORDER BY is_active DESC, sort, name")->fetchAll(PDO::FETCH_ASSOC);
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Jármű fajták</h1>
  <a class="btn btn-outline-secondary" href="/vehicles.php">Vissza</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card p-3">
      <h2 class="h6">Új fajta</h2>
      <form method="post" class="row g-2">
        <?= \App\Csrf::field() ?>
        <div class="col-8">
          <input class="form-control" name="name" placeholder="pl. targonca" required>
        </div>
        <div class="col-4">
          <input class="form-control" name="sort" type="number" value="0" title="Sorrend">
        </div>
        <div class="col-12 d-grid">
          <button class="btn btn-primary">Hozzáadás</button>
        </div>
      </form>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card p-0">
      <table class="table table-striped m-0 align-middle">
        <thead><tr><th>Név</th><th>Sorrend</th><th>Aktív</th></tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?= h($r['name']) ?></td>
              <td><?= (int)$r['sort'] ?></td>
              <td><?= ((int)$r['is_active']===1)?'<span class="badge bg-success">igen</span>':'<span class="badge bg-secondary">nem</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>

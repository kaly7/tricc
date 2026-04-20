<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db; use App\Csrf;

Auth::start(); Middleware::requireAuth();
$u = Auth::user();
$isAdmin = isset($u['role_id']) && (int)$u['role_id']===1;
if (!$isAdmin) { http_response_code(403); exit('Nincs jogosultság.'); }

$pdo = Db::pdo();
$table = 'vehicle_vignette_types';
$title = 'Autópálya matrica típusok';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!Csrf::check($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('CSRF hiba'); }
  $action = $_POST['action'] ?? '';
  if ($action==='create') {
    $name = trim((string)($_POST['name'] ?? ''));
    
    if ($name!=='') {
      $pdo->prepare("INSERT INTO {$table} (name) VALUES (?)")->execute([$name]);
      $_SESSION['flash_success'] = 'Mentve.';
    }
  } elseif ($action==='toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE {$table} SET is_active = 1-is_active WHERE id=?")->execute([$id]);
  } elseif ($action==='delete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
  }
  header('Location: /vehicle_vignette_types.php');
  exit;
}

$rows = $pdo->query("SELECT * FROM {$table} ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5"><?= htmlspecialchars($title) ?></h1>
  <a class="btn btn-outline-secondary" href="/vehicles.php">Vissza</a>
</div>

<div class="card p-3 mb-3">
  <form method="post" class="row g-2">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <div class="col-md-5">
      <label class="form-label">Név</label>
      <input class="form-control" name="name" required>
    </div>
    
    <div class="col-12">
      <button class="btn btn-primary">Hozzáadás</button>
    </div>
  </form>
</div>

<div class="card p-0">
  <table class="table table-striped m-0 align-middle">
    <thead><tr>
      <th>Név</th>
      
      <th>Aktív</th>
      <th>Művelet</th>
    </tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?></td>
          
          <td><?= ((int)$r['is_active']===1) ? '<span class="badge bg-success">igen</span>' : '<span class="badge bg-secondary">nem</span>' ?></td>
          <td class="text-nowrap">
            <form method="post" class="d-inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-primary">Aktív</button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Biztos törlöd?');">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Törlés</button>
            </form>
          </td>
        </tr>
      <?php endforeach; if(!$rows): ?>
        <tr><td colspan="4" class="text-muted">Nincs adat.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>

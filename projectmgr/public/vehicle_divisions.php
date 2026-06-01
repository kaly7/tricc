<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';

use App\Auth; use App\Middleware; use App\Db; use App\Csrf;

Auth::start(); Middleware::requireAuth();
$u = Auth::user();
$isAdmin = isset($u['role_id']) && (int)$u['role_id']===1;
if (!$isAdmin) { http_response_code(403); exit('Nincs jogosultság.'); }

$pdo = Db::pdo();
$table = 'vehicle_divisions';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!Csrf::check($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('CSRF hiba'); }
  $action = (string)($_POST['action'] ?? '');

  if ($action==='create') {
    $name = trim((string)($_POST['name'] ?? ''));
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($name!=='') {
      $pdo->prepare("INSERT INTO {$table} (name, sort_order, is_active) VALUES (?,?,1)")->execute([$name, $sort]);
      $_SESSION['flash_success'] = 'Mentve.';
    }
  } elseif ($action==='toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE {$table} SET is_active=1-is_active WHERE id=?")->execute([$id]);
  } elseif ($action==='update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($id>0 && $name!=='') {
      $pdo->prepare("UPDATE {$table} SET name=?, sort_order=? WHERE id=?")->execute([$name, $sort, $id]);
      $_SESSION['flash_success'] = 'Módosítva.';
    }
  } elseif ($action==='delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      try {
        $pdo->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
        $_SESSION['flash_success'] = 'Törölve.';
      } catch (Throwable $e) {
        $_SESSION['flash_danger'] = 'Nem törölhető (valószínűleg járműhöz van rendelve). Előbb állítsd át a járműveknél.';
      }
    }
  }

  header('Location: /vehicle_divisions.php?module=vehicles');
  exit;
}

$rows = $pdo->query("SELECT * FROM {$table} ORDER BY is_active DESC, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Divíziók</h1>
  <a class="btn btn-outline-secondary" href="/vehicles.php?module=vehicles">Vissza</a>
</div>

<div class="card p-3 mb-3">
  <form method="post" class="row g-2">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <div class="col-md-7">
      <label class="form-label">Név</label>
      <input class="form-control" name="name" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">Sorrend</label>
      <input class="form-control" type="number" name="sort_order" value="0">
    </div>
    <div class="col-md-2 d-grid align-items-end">
      <button class="btn btn-primary mt-4">Hozzáadás</button>
    </div>
  </form>
</div>

<div class="card p-0">
  <table class="table table-striped m-0 align-middle">
    <thead><tr>
      <th>Név</th>
      <th style="width:110px;">Sorrend</th>
      <th style="width:90px;">Aktív</th>
      <th style="width:340px;">Művelet</th>
    </tr></thead>
    <tbody>
    <?php foreach($rows as $r): ?>
      <tr>
        <td>
          <form method="post" class="d-flex gap-2 align-items-center">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input class="form-control form-control-sm" name="name" value="<?= h($r['name']) ?>">
        </td>
        <td>
            <input class="form-control form-control-sm" type="number" name="sort_order" value="<?= (int)$r['sort_order'] ?>">
        </td>
        <td>
            <?= ((int)$r['is_active']===1) ? '<span class="badge bg-success">igen</span>' : '<span class="badge bg-secondary">nem</span>' ?>
        </td>
        <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-success">Mentés</button>
          </form>
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

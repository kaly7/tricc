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
$table = 'tire_storage_locations';
$title = 'Gumi tárolási helyek';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!Csrf::check($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('CSRF hiba'); }
  $action = (string)($_POST['action'] ?? '');

  if ($action==='create') {
    $name = trim((string)($_POST['name'] ?? ''));
    $details = trim((string)($_POST['details'] ?? ''));
    $details = ($details==='') ? null : $details;
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($name!=='') {
      $pdo->prepare("INSERT INTO {$table} (name, details, sort_order, is_active) VALUES (?,?,?,1)")
          ->execute([$name, $details, $sort]);
      $_SESSION['flash_success'] = 'Mentve.';
    }
  } elseif ($action==='toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE {$table} SET is_active=1-is_active WHERE id=?")->execute([$id]);
  } elseif ($action==='update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $details = trim((string)($_POST['details'] ?? ''));
    $details = ($details==='') ? null : $details;
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($id>0 && $name!=='') {
      $pdo->prepare("UPDATE {$table} SET name=?, details=?, sort_order=? WHERE id=?")
          ->execute([$name, $details, $sort, $id]);
      $_SESSION['flash_success'] = 'Módosítva.';
    }
  } elseif ($action==='delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      try {
        $pdo->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
        $_SESSION['flash_success'] = 'Törölve.';
      } catch (Throwable $e) {
        $_SESSION['flash_danger'] = 'Nem törölhető (valószínűleg már szerepel levett gumiknál).';
      }
    }
  }

  header('Location: /tire_storage_locations.php');
  exit;
}

$rows = $pdo->query("SELECT * FROM {$table} ORDER BY is_active DESC, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?= htmlspecialchars($title) ?></h1>
  <a class="btn btn-outline-secondary" href="/vehicles.php">Vissza</a>
</div>

<div class="card p-3 mb-3">
  <form method="post" class="row g-2">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <div class="col-md-4">
      <label class="form-label">Név</label>
      <input class="form-control" name="name" required>
    </div>
    <div class="col-md-5">
      <label class="form-label">Megjegyzés / hely</label>
      <input class="form-control" name="details" placeholder="pl. Raktár 1 / Polc B">
    </div>
    <div class="col-md-2">
      <label class="form-label">Sorrend</label>
      <input class="form-control" type="number" name="sort_order" value="0">
    </div>
    <div class="col-md-1 d-grid align-items-end">
      <button class="btn btn-primary mt-4">+</button>
    </div>
  </form>
</div>

<div class="card p-0">
  <table class="table table-striped m-0 align-middle">
    <thead><tr>
      <th>Név</th>
      <th>Megjegyzés</th>
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
            <input class="form-control form-control-sm" name="details" value="<?= h($r['details'] ?? '') ?>">
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
      <tr><td colspan="5" class="text-muted">Nincs adat.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>

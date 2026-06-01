<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
require_admin();
$pdo = db_pm();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $action = $_POST['action'] ?? '';

  if ($action === 'add') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name !== '') {
      try {
        $pdo->prepare("INSERT INTO vehicle_divisions (name, is_active, sort_order) VALUES (?, 1, 0)")
            ->execute([$name]);
        flash_set('ok', 'Divízió hozzáadva.');
      } catch (Throwable $e) {
        flash_set('err', 'Hiba: ' . $e->getMessage());
      }
    }

  } elseif ($action === 'rename') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($id > 0 && $name !== '') {
      $pdo->prepare("UPDATE vehicle_divisions SET name=?, updated_at=NOW() WHERE id=?")->execute([$name, $id]);
      flash_set('ok', 'Átnevezve.');
    }

  } elseif ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("UPDATE vehicle_divisions SET is_active = 1 - is_active, updated_at=NOW() WHERE id=?")->execute([$id]);
      flash_set('ok', 'Állapot módosítva.');
    }
  }

  redirect('admin_divisions.php');
}

$divisions = $pdo->query("SELECT id, name, is_active, sort_order, created_at FROM vehicle_divisions ORDER BY is_active DESC, name")->fetchAll();

// Jármű darabszám divíziónként
$counts = [];
foreach ($pdo->query("SELECT division_id, COUNT(*) as cnt FROM vehicles WHERE archived=0 GROUP BY division_id")->fetchAll() as $r) {
  $counts[(int)$r['division_id']] = (int)$r['cnt'];
}

$title = 'Divíziók kezelése';
$page  = 'admin_divisions';
require '_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Divíziók kezelése</h4>
  <a href="<?= e(base_url('admin_vehicles.php')) ?>" class="btn btn-outline-secondary btn-sm">← Vissza</a>
</div>

<div class="row g-4">
  <!-- Lista -->
  <div class="col-md-8">
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr><th>Megnevezés</th><th>Járművek</th><th>Állapot</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($divisions as $d): ?>
            <tr class="<?= !(int)$d['is_active'] ? 'opacity-50' : '' ?>">
              <td>
                <form method="post" class="d-flex gap-2 align-items-center">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="rename">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <input type="text" name="name" value="<?= e($d['name']) ?>" class="form-control form-control-sm" style="max-width:220px">
                  <button type="submit" class="btn btn-sm btn-outline-primary">Ment</button>
                </form>
              </td>
              <td class="text-muted small"><?= $counts[(int)$d['id']] ?? 0 ?> db</td>
              <td>
                <span class="badge <?= (int)$d['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                  <?= (int)$d['is_active'] ? 'Aktív' : 'Inaktív' ?>
                </span>
              </td>
              <td>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="btn btn-sm <?= (int)$d['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                    <?= (int)$d['is_active'] ? 'Letilt' : 'Aktivál' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Új divízió -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-header fw-semibold">Új divízió</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add">
          <div class="mb-3">
            <label class="form-label">Megnevezés</label>
            <input type="text" name="name" class="form-control" required placeholder="pl. Győr">
          </div>
          <button type="submit" class="btn btn-primary w-100">Hozzáad</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require '_footer.php'; ?>

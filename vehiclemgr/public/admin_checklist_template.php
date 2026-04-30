<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
require_admin();
$u   = current_user();
$pdo = db();

$vehicleId = (int)($_GET['vehicle_id'] ?? 0);
$v = $vehicleId ? get_vehicle($vehicleId) : null;
if (!$v) { flash_set('err', 'Jármű nem található.'); redirect('admin_vehicles.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'add') {
    $text = trim((string)($_POST['item_text'] ?? ''));
    if ($text === '') { flash_set('err', 'A tétel szövege nem lehet üres.'); redirect('admin_checklist_template.php?vehicle_id=' . $vehicleId); }
    $maxOrder = 0;
    try {
      $st = $pdo->prepare("SELECT MAX(item_order) FROM checklist_templates WHERE vehicle_id=?");
      $st->execute([$vehicleId]);
      $maxOrder = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
    $pdo->prepare("INSERT INTO checklist_templates (vehicle_id, item_order, item_text) VALUES (?,?,?)")
        ->execute([$vehicleId, $maxOrder + 1, $text]);
    flash_set('ok', 'Tétel hozzáadva.'); redirect('admin_checklist_template.php?vehicle_id=' . $vehicleId);
  }

  if ($action === 'delete') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $pdo->prepare("DELETE FROM checklist_templates WHERE id=? AND vehicle_id=?")->execute([$itemId, $vehicleId]);
    flash_set('ok', 'Tétel törölve.'); redirect('admin_checklist_template.php?vehicle_id=' . $vehicleId);
  }

  if ($action === 'toggle') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $pdo->prepare("UPDATE checklist_templates SET is_active = 1 - is_active WHERE id=? AND vehicle_id=?")->execute([$itemId, $vehicleId]);
    redirect('admin_checklist_template.php?vehicle_id=' . $vehicleId);
  }

  if ($action === 'reorder') {
    $order = (array)($_POST['order'] ?? []);
    foreach ($order as $pos => $itemId) {
      $pdo->prepare("UPDATE checklist_templates SET item_order=? WHERE id=? AND vehicle_id=?")->execute([(int)$pos + 1, (int)$itemId, $vehicleId]);
    }
    redirect('admin_checklist_template.php?vehicle_id=' . $vehicleId);
  }
}

$items = [];
try {
  $st = $pdo->prepare("SELECT * FROM checklist_templates WHERE vehicle_id=? ORDER BY item_order, id");
  $st->execute([$vehicleId]);
  $items = $st->fetchAll();
} catch (Throwable $e) {}

$title = 'Checklist sablon – ' . vehicle_label($v);
$page  = 'admin_vehicles';
require '_header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= e(base_url('admin_vehicles.php')) ?>" class="btn btn-outline-secondary btn-sm">← Vissza</a>
  <h5 class="mb-0">Checklist sablon</h5>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <span class="plate"><?= e($v['license_plate'] ?? '–') ?></span>
    <span class="ms-2 fw-bold"><?= e($v['make'] . ' ' . $v['model']) ?></span>
    <?php if (!empty($v['vehicle_identifier'])): ?>
      <span class="text-muted ms-2 small"><?= e($v['vehicle_identifier']) ?></span>
    <?php endif; ?>
  </div>
</div>

<!-- Tételek -->
<?php if (empty($items)): ?>
  <div class="alert alert-warning">Még nincs checklist tétel ehhez a járműhöz.</div>
<?php else: ?>
  <div class="list-group mb-3" id="sortableList">
  <?php foreach ($items as $item): ?>
    <div class="list-group-item d-flex align-items-center gap-2" data-id="<?= (int)$item['id'] ?>">
      <span class="text-muted me-1" style="cursor:grab">☰</span>
      <span class="flex-fill <?= !$item['is_active'] ? 'text-muted text-decoration-line-through' : '' ?>"><?= e($item['item_text']) ?></span>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
        <button type="submit" class="btn btn-outline-secondary btn-sm" title="<?= $item['is_active'] ? 'Letiltás' : 'Aktiválás' ?>">
          <?= $item['is_active'] ? '👁' : '👁‍🗨' ?>
        </button>
      </form>
      <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törlöd?')">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
        <button type="submit" class="btn btn-outline-danger btn-sm">🗑</button>
      </form>
    </div>
  <?php endforeach; ?>
  </div>

  <!-- Sorrend mentése drag&drop után -->
  <form method="post" id="reorderForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="reorder">
    <div id="orderInputs"></div>
    <button type="submit" class="btn btn-outline-secondary btn-sm mb-3" id="saveOrderBtn" style="display:none">💾 Sorrend mentése</button>
  </form>
<?php endif; ?>

<!-- Új tétel hozzáadása -->
<div class="card">
  <div class="card-header">Új tétel hozzáadása</div>
  <div class="card-body">
    <form method="post" class="d-flex gap-2">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add">
      <input type="text" name="item_text" class="form-control" placeholder="Ellenőrzési pont szövege..." required>
      <button type="submit" class="btn btn-primary text-nowrap">+ Hozzáad</button>
    </form>
  </div>
</div>

<script>
// Drag&drop sorrend - egyszerű implementáció
(function() {
  const list = document.getElementById('sortableList');
  if (!list) return;
  let dragging = null;

  list.querySelectorAll('[data-id]').forEach(function(item) {
    item.draggable = true;
    item.addEventListener('dragstart', function() { dragging = item; item.style.opacity = '.5'; });
    item.addEventListener('dragend',   function() { dragging = null; item.style.opacity = ''; updateOrderInputs(); });
    item.addEventListener('dragover',  function(e) { e.preventDefault(); const after = getDragAfter(list, e.clientY); if (after) list.insertBefore(dragging, after); else list.appendChild(dragging); });
  });

  function getDragAfter(container, y) {
    const items = [...container.querySelectorAll('[data-id]:not(.dragging)')];
    return items.reduce(function(closest, child) {
      const box = child.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;
      return (offset < 0 && offset > closest.offset) ? { offset, element: child } : closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
  }

  function updateOrderInputs() {
    const inputs = document.getElementById('orderInputs');
    inputs.innerHTML = '';
    list.querySelectorAll('[data-id]').forEach(function(item, i) {
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'order[]';
      inp.value = item.dataset.id;
      inputs.appendChild(inp);
    });
    document.getElementById('saveOrderBtn').style.display = '';
  }
})();
</script>

<?php require '_footer.php'; ?>

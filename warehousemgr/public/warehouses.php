<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Raktárak kezelőoldala.
 * Belső és külsős partner raktárak létrehozása, módosítása és aktiválása innen történik.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Raktárak';
$loggedIn = true;
$pdo = warehouse_pdo($config);

if (!warehouse_module_admin($config)) {
    http_response_code(403);
    echo '403 - Ehhez az oldalhoz warehousemgr admin jogosultság szükséges.';
    exit;
}

// A raktárkezelésen belül ugyanitt történik az új raktár létrehozása és a módosítás is.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_warehouse') {
        $name = trim((string)($_POST['name'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $warehouseType = warehouse_type_normalize((string)($_POST['warehouse_type'] ?? 'internal'));
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        $partnerId = $partnerId > 0 ? $partnerId : null;
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $parentId = $parentId > 0 ? $parentId : null;

        if ($name === '' || $code === '') {
            flash_set('err', 'A név és a kód kötelező.');
            header('Location: /warehouses.php');
            exit;
        }

        if ($warehouseType === 'external_partner') {
            $parentId = null;
            if ($partnerId === null || $partnerId <= 0) {
                flash_set('err', 'Külső partner raktárnál partner kiválasztása kötelező.');
                header('Location: /warehouses.php');
                exit;
            }
        } else {
            $partnerId = null;
        }

        try {
            $st = $pdo->prepare('INSERT INTO warehouses (parent_id, code, name, description, warehouse_type, partner_id, is_active, created_by, updated_by) VALUES (?,?,?,?,?,?,1,?,?)');
            $st->execute([$parentId, $code, $name, ($description === '' ? null : $description), $warehouseType, $partnerId, current_auth_user_id(), current_auth_user_id()]);
            $warehouseId = (int)$pdo->lastInsertId();
            warehouse_audit($config, 'warehouse.create', 'warehouse', $warehouseId, [
                'name' => $name,
                'code' => $code,
                'parent_id' => $parentId,
                'warehouse_type' => $warehouseType,
                'partner_id' => $partnerId,
            ]);
            flash_set('msg', 'Raktár létrehozva. Most rendeld hozzá a felhasználókat.');
            header('Location: /warehouse_access.php?id=' . $warehouseId);
            exit;
        } catch (Throwable $e) {
            flash_set('err', 'Hiba létrehozás közben: ' . $e->getMessage());
            header('Location: /warehouses.php');
            exit;
        }
    }

    if ($action === 'toggle_active') {
        $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $pdo->prepare('UPDATE warehouses SET is_active = IF(is_active=1,0,1), updated_by=? WHERE id=?')
                ->execute([current_auth_user_id(), $warehouseId]);
            $current = warehouse_find($config, $warehouseId);
            warehouse_audit($config, 'warehouse.toggle_active', 'warehouse', $warehouseId, [
                'new_is_active' => (int)($current['is_active'] ?? 0),
                'name' => (string)($current['name'] ?? ''),
                'code' => (string)($current['code'] ?? ''),
            ]);
            flash_set('msg', 'Raktár állapota frissítve.');
        }
        header('Location: /warehouses.php');
        exit;
    }

    if ($action === 'delete_warehouse') {
        $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            try {
                $result = warehouse_delete_recursive($config, $warehouseId);
                flash_set('msg', 'Raktár törölve. Érintett raktárak száma: ' . (int)$result['deleted_count'] . '. Archívum: ' . (string)($result['archive_relative_path'] ?? '—'));
            } catch (Throwable $e) {
                flash_set('err', 'Törlési hiba: ' . $e->getMessage());
            }
        }
        header('Location: /warehouses.php');
        exit;
    }
}

$msg = flash_get('msg');
$err = flash_get('err');
$warehouses = warehouse_all($config);

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0">Raktárak</h1>
    <div class="text-secondary small">Első lépés: raktárak és alraktárak felvétele, majd jogosultság kiosztása auth_center felhasználóknak.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/partners.php">Partnerek</a>
    <a class="btn btn-sm btn-outline-secondary" href="/audit_log.php">Admin napló</a>
    <a class="btn btn-sm btn-outline-secondary" href="/warehouse_archives.php">Archívumok</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
      <div class="fw-semibold">Új raktár / alraktár</div>
      <div class="text-secondary small">Belső vagy külső partner raktár létrehozása. A rész alapból csukva indul.</div>
    </div>
    <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#warehouse-form-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
      <span class="wm-panel-toggle-label">Megnyitás</span>
    </button>
  </div>
  <div id="warehouse-form-panel" class="collapse" data-wm-panel="1" data-panel-key="warehouses-form" data-force-open="0">
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="create_warehouse">
        <div class="col-12">
          <label class="form-label">Név</label>
          <input class="form-control" name="name" required>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Kód</label>
          <input class="form-control" name="code" required placeholder="pl. KOZPONTI">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label">Típus</label>
          <select class="form-select" name="warehouse_type" id="warehouse-type-select">
            <option value="internal">Belső raktár</option>
            <option value="external_partner">Külső partner raktár</option>
          </select>
        </div>
        <div class="col-12 col-md-6" id="warehouse-parent-wrap">
          <label class="form-label">Szülő raktár</label>
          <select class="form-select" name="parent_id">
            <?= warehouse_parent_options($config) ?>
          </select>
        </div>
        <div class="col-12 col-md-6 d-none" id="warehouse-partner-wrap">
          <label class="form-label">Kapcsolt partner</label>
          <select class="form-select" name="partner_id" id="warehouse-partner-select">
            <?= warehouse_partner_options($config) ?>
          </select>
          <div class="form-text">Külső partner raktár esetén kötelező.</div>
        </div>
        <div class="col-12">
          <label class="form-label">Leírás</label>
          <textarea class="form-control" name="description" rows="3"></textarea>
        </div>
        <div class="col-12 d-flex justify-content-end">
          <button class="btn btn-primary" type="submit">Raktár létrehozása</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h2 class="h6 mb-0">Raktár lista</h2>
      <div class="text-secondary small">Minden létrehozás, állapotváltás és törlés naplózásra kerül. Csak üres raktár törölhető, a törlés előtt automatikus archív mentés készül.</div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Név</th>
            <th>Kód</th>
            <th>Szülő</th>
            <th>Típus</th>
            <th>Partner</th>
            <th>Alraktár</th>
            <th>Jogosult user</th>
            <th>Aktív</th>
            <th class="text-end">Művelet</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($warehouses as $w): ?>
          <tr>
            <td>
              <div class="fw-bold"><?= h((string)$w['name']) ?></div>
              <?php if (!empty($w['description'])): ?><div class="text-secondary small"><?= h((string)$w['description']) ?></div><?php endif; ?>
            </td>
            <td><?= h((string)$w['code']) ?></td>
            <td><?= h((string)($w['parent_name'] ?? '—')) ?></td>
            <td><span class="badge <?= warehouse_type_normalize((string)($w['warehouse_type'] ?? 'internal')) === 'external_partner' ? 'bg-warning text-dark' : 'bg-info text-dark' ?>"><?= h(warehouse_type_label((string)($w['warehouse_type'] ?? 'internal'))) ?></span></td>
            <td>
              <?php if (!empty($w['partner_name'])): ?>
                <div class="fw-semibold"><?= h((string)$w['partner_name']) ?></div>
                <?php if (!empty($w['partner_receiver_name'])): ?><div class="text-secondary small">Átvevő: <?= h((string)$w['partner_receiver_name']) ?></div><?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= (int)($w['child_count'] ?? 0) ?></td>
            <td><?= (int)$w['access_count'] ?></td>
            <td><?= ((int)$w['is_active'] === 1) ? '<span class="badge bg-success">Igen</span>' : '<span class="badge bg-secondary">Nem</span>' ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="/warehouse_access.php?id=<?= (int)$w['id'] ?>">Jogosultságok</a>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="warehouse_id" value="<?= (int)$w['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= ((int)$w['is_active'] === 1) ? 'Inaktivál' : 'Aktivál' ?></button>
              </form>
              <?php $warehouseDeleteConfirm = "Biztosan törölni szeretnéd ezt a raktárat: \"" . (string)$w['name'] . "\"?\n\nA törlés előtt teljes archív mentés készül a kapcsolódó adatokból.\nAz összes alraktár és hozzáférés is törlődni fog, ha nincs bennük készlet."; ?>
              <form method="post" class="d-inline" onsubmit="return confirm(<?= json_encode($warehouseDeleteConfirm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);">
                <input type="hidden" name="action" value="delete_warehouse">
                <input type="hidden" name="warehouse_id" value="<?= (int)$w['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Törlés</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$warehouses): ?>
          <tr><td colspan="9" class="text-secondary">Még nincs raktár.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(() => {
  const typeSelect = document.getElementById('warehouse-type-select');
  const parentWrap = document.getElementById('warehouse-parent-wrap');
  const partnerWrap = document.getElementById('warehouse-partner-wrap');
  const partnerSelect = document.getElementById('warehouse-partner-select');
  if (!typeSelect || !parentWrap || !partnerWrap || !partnerSelect) return;

  const syncWarehouseType = () => {
    const external = typeSelect.value === 'external_partner';
    parentWrap.classList.toggle('d-none', external);
    partnerWrap.classList.toggle('d-none', !external);
    partnerSelect.required = external;
    if (!external) {
      partnerSelect.value = '';
    }
  };

  typeSelect.addEventListener('change', syncWarehouseType);
  syncWarehouseType();
})();
</script>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

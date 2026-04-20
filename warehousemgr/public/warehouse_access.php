<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Raktár jogosultságkezelő oldal.
 * Felhasználó -> raktár -> szerepkör hozzárendelések adminisztrációja.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Raktár jogosultságok';
$loggedIn = true;
$pdo = warehouse_pdo($config);

if (!warehouse_module_admin($config)) {
    http_response_code(403);
    echo '403 - Ehhez az oldalhoz warehousemgr admin jogosultság szükséges.';
    exit;
}

$warehouseId = (int)($_GET['id'] ?? $_POST['warehouse_id'] ?? 0);
$warehouse = $warehouseId > 0 ? warehouse_find($config, $warehouseId) : null;
if (!$warehouse) {
    http_response_code(404);
    echo 'Nincs ilyen raktár.';
    exit;
}

// Jogosultságmódosítások: új hozzáférés felvitele vagy meglévő szerepkör törlése.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_access') {
        $authUserId = (int)($_POST['auth_user_id'] ?? 0);
        $roleKey = (string)($_POST['role_key'] ?? 'viewer');
        if ($authUserId <= 0 || !in_array($roleKey, ['admin', 'user', 'viewer'], true)) {
            flash_set('err', 'Hibás felhasználó vagy szerepkör.');
        } else {
            try {
                $st = $pdo->prepare("INSERT INTO warehouse_user_access (warehouse_id, auth_user_id, role_key, created_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_key=VALUES(role_key), created_by=VALUES(created_by)");
                $st->execute([$warehouseId, $authUserId, $roleKey, current_auth_user_id()]);
                warehouse_audit($config, 'warehouse.access.upsert', 'warehouse', $warehouseId, [
                    'auth_user_id' => $authUserId,
                    'role_key' => $roleKey,
                    'warehouse_name' => (string)$warehouse['name'],
                    'warehouse_code' => (string)$warehouse['code'],
                ]);
                flash_set('msg', 'Jogosultság mentve.');
            } catch (Throwable $e) {
                flash_set('err', 'Mentési hiba: ' . $e->getMessage());
            }
        }
        header('Location: /warehouse_access.php?id=' . $warehouseId);
        exit;
    }

    if ($action === 'delete_access') {
        $accessId = (int)($_POST['access_id'] ?? 0);
        if ($accessId > 0) {
            $detailSt = $pdo->prepare("SELECT auth_user_id, role_key FROM warehouse_user_access WHERE id=? AND warehouse_id=? LIMIT 1");
            $detailSt->execute([$accessId, $warehouseId]);
            $existingAccess = $detailSt->fetch() ?: [];

            $st = $pdo->prepare("DELETE FROM warehouse_user_access WHERE id=? AND warehouse_id=?");
            $st->execute([$accessId, $warehouseId]);
            warehouse_audit($config, 'warehouse.access.delete', 'warehouse', $warehouseId, [
                'access_id' => $accessId,
                'auth_user_id' => (int)($existingAccess['auth_user_id'] ?? 0),
                'role_key' => (string)($existingAccess['role_key'] ?? ''),
                'warehouse_name' => (string)$warehouse['name'],
                'warehouse_code' => (string)$warehouse['code'],
            ]);
            flash_set('msg', 'Jogosultság törölve.');
        }
        header('Location: /warehouse_access.php?id=' . $warehouseId);
        exit;
    }
}

$msg = flash_get('msg');
$err = flash_get('err');
$authUsers = warehouse_resolved_auth_users($config);
$accessList = warehouse_access_list($config, $warehouseId);

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0">Raktár jogosultságok</h1>
    <div class="text-secondary small">
      <strong><?= h((string)$warehouse['name']) ?></strong>
      <?php if (!empty($warehouse['parent_name'])): ?> · szülő: <?= h((string)$warehouse['parent_name']) ?><?php endif; ?>
      · kód: <?= h((string)$warehouse['code']) ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/audit_log.php">Admin napló</a>
    <a class="btn btn-sm btn-outline-secondary" href="/warehouses.php">Vissza</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6">Felhasználó hozzárendelése</h2>
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="add_access">
          <input type="hidden" name="warehouse_id" value="<?= (int)$warehouseId ?>">
          <div class="col-12">
            <label class="form-label">Auth / HR felhasználó</label>
            <select class="form-select" name="auth_user_id" required>
              <option value="">— válassz —</option>
              <?php foreach ($authUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h((string)$u['resolved_name']) ?> (<?= h((string)$u['username']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">A megjelenített név a HR modulból jön, ha az auth userhez hozzá van rendelve HR munkatárs.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Helyi szerepkör</label>
            <select class="form-select" name="role_key">
              <option value="admin">Admin</option>
              <option value="user">Kezelő</option>
              <option value="viewer">Megtekintő</option>
            </select>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary" type="submit">Hozzárendelés</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6">Jelenlegi hozzáférések</h2>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th>Név</th>
                <th>Username</th>
                <th>Email</th>
                <th>Szerepkör</th>
                <th class="text-end">Művelet</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($accessList as $a): ?>
              <tr>
                <td><?= h((string)$a['resolved_name']) ?></td>
                <td><?= h((string)$a['username']) ?></td>
                <td><?= h((string)$a['email']) ?></td>
                <td><span class="badge bg-secondary"><?= h(warehouse_role_label((string)$a['role_key'])) ?></span></td>
                <td class="text-end">
                  <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törlöd ezt a raktárjogot?');">
                    <input type="hidden" name="action" value="delete_access">
                    <input type="hidden" name="warehouse_id" value="<?= (int)$warehouseId ?>">
                    <input type="hidden" name="access_id" value="<?= (int)$a['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Törlés</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$accessList): ?>
              <tr><td colspan="5" class="text-secondary">Még nincs hozzárendelt felhasználó.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

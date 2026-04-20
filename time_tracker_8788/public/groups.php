<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
$editId = !empty($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = $editId ? tracker_group_find($config, $editId) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'delete') {
            tracker_delete_group($config, (int)$_POST['id']);
            tracker_flash_set('success', 'Csoport törölve.');
        } else {
            tracker_save_group($config, $_POST, !empty($_POST['id']) ? (int)$_POST['id'] : null);
            tracker_flash_set('success', 'Csoport mentve.');
        }
    } catch (Throwable $e) { tracker_flash_set('error', $e->getMessage()); }
    tracker_redirect('/groups.php');
}
$title='Munkaidő / Csoportok'; require __DIR__ . '/../app/views/layout/header.php';
$success = tracker_flash_get('success'); $error = tracker_flash_get('error'); if($success) echo '<div class="alert alert-success">'.h($success).'</div>'; if($error) echo '<div class="alert alert-danger">'.h($error).'</div>';
$groups = tracker_groups_all($config);
?>
<div class="row g-4"><div class="col-lg-5"><div class="card shadow-sm"><div class="card-header"><div class="fw-semibold"><?= $edit ? 'Csoport szerkesztése' : 'Új csoport' ?></div></div><div class="card-body"><form method="post" class="vstack gap-3"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>"><div><label class="form-label">Név</label><input type="text" name="name" class="form-control" required value="<?= h((string)($edit['name'] ?? '')) ?>"></div><div><label class="form-label">Leírás</label><textarea name="description" class="form-control" rows="3"><?= h((string)($edit['description'] ?? '')) ?></textarea></div><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= !isset($edit['is_active']) || !empty($edit['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Aktív</label></div><div class="d-flex gap-2"><button class="btn btn-primary">Mentés</button><?php if ($edit): ?><a class="btn btn-outline-secondary" href="/groups.php">Mégse</a><?php endif; ?></div></form></div></div></div><div class="col-lg-7"><div class="card shadow-sm"><div class="card-header"><div class="fw-semibold">Csoportok</div></div><div class="card-body"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Név</th><th>Leírás</th><th>Állapot</th><th></th></tr></thead><tbody><?php foreach ($groups as $g): ?><tr><td><?= h($g['name']) ?></td><td><?= h((string)$g['description']) ?></td><td><span class="badge <?= !empty($g['is_active']) ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= !empty($g['is_active']) ? 'Aktív' : 'Inaktív' ?></span></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/groups.php?edit=<?= (int)$g['id'] ?>">Szerkeszt</a> <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törlöd a csoportot?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="btn btn-sm btn-outline-danger">Törlés</button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div></div></div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
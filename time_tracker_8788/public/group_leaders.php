<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'delete') {
            tracker_delete_group_leader($config, (int)$_POST['id']);
            tracker_flash_set('success', 'Csoportvezető törölve.');
        } else {
            tracker_save_group_leader($config, (int)$_POST['group_id'], (int)$_POST['user_id']);
            tracker_flash_set('success', 'Csoportvezető mentve.');
        }
    } catch (Throwable $e) { tracker_flash_set('error', $e->getMessage()); }
    tracker_redirect('/group_leaders.php');
}
$title='Munkaidő / Csoportvezetők'; require __DIR__ . '/../app/views/layout/header.php';
$success = tracker_flash_get('success'); $error = tracker_flash_get('error'); if($success) echo '<div class="alert alert-success">'.h($success).'</div>'; if($error) echo '<div class="alert alert-danger">'.h($error).'</div>';
$groups = tracker_groups_all($config,true); $users = tracker_group_user_options($config); $leaders = tracker_group_leaders($config); 
?>
<div class="row g-4"><div class="col-lg-5"><div class="card shadow-sm"><div class="card-header"><div class="fw-semibold">Csoportvezető hozzárendelése</div></div><div class="card-body"><form method="post" class="vstack gap-3"><div><label class="form-label">Csoport</label><select name="group_id" class="form-select"><?php foreach($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Felhasználó</label><select name="user_id" class="form-select"><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h((string)($u['full_name'] ?: $u['username'])) ?></option><?php endforeach; ?></select></div><button class="btn btn-primary">Hozzárendelés</button></form></div></div></div><div class="col-lg-7"><div class="card shadow-sm"><div class="card-header"><div class="fw-semibold">Csoportvezetők</div></div><div class="card-body"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Csoport</th><th>Felhasználó</th><th></th></tr></thead><tbody><?php foreach($leaders as $l): ?><tr><td><?= h($l['group_name']) ?></td><td><?= h($l['user_name']) ?></td><td class="text-end"><form method="post" class="d-inline" onsubmit="return confirm('Törlöd a hozzárendelést?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm btn-outline-danger">Törlés</button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div></div></div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
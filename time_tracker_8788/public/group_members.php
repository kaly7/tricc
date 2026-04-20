<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'delete') {
            tracker_delete_group_member($config, (int)$_POST['id']);
            tracker_flash_set('success', 'Csoporttag törölve.');
        } else {
            tracker_save_group_member($config, (int)$_POST['group_id'], (int)$_POST['employee_id']);
            tracker_flash_set('success', 'Csoporttag mentve.');
        }
    } catch (Throwable $e) { tracker_flash_set('error', $e->getMessage()); }
    tracker_redirect('/group_members.php');
}
$title='Munkaidő / Csoporttagok'; require __DIR__ . '/../app/views/layout/header.php';
$success = tracker_flash_get('success'); $error = tracker_flash_get('error'); if($success) echo '<div class="alert alert-success">'.h($success).'</div>'; if($error) echo '<div class="alert alert-danger">'.h($error).'</div>';
$groups = tracker_groups_all($config,true); $employees = tracker_employee_options($config); $members = tracker_group_members($config); $groupMap=[]; foreach($groups as $g){$groupMap[(int)$g['id']]=$g['name'];} $empMap=[]; foreach($employees as $e){$empMap[(int)$e['employee_id']]=$e['label'];}
?>
<div class="row g-4"><div class="col-lg-5"><div class="card shadow-sm"><div class="card-header"><div class="fw-semibold">Csoporttag hozzáadása</div></div><div class="card-body"><form method="post" class="vstack gap-3"><div><label class="form-label">Csoport</label><select name="group_id" class="form-select"><?php foreach($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Dolgozó</label><select name="employee_id" class="form-select"><?php foreach($employees as $e): ?><option value="<?= (int)$e['employee_id'] ?>"><?= h($e['label']) ?></option><?php endforeach; ?></select></div><button class="btn btn-primary">Hozzáadás</button></form></div></div></div><div class="col-lg-7"><div class="card shadow-sm"><div class="card-header"><div class="fw-semibold">Csoporttagok</div></div><div class="card-body"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Csoport</th><th>Dolgozó</th><th></th></tr></thead><tbody><?php foreach($members as $m): ?><tr><td><?= h($groupMap[(int)$m['group_id']] ?? $m['group_name']) ?></td><td><?= h($empMap[(int)$m['employee_id']] ?? ('#'.(int)$m['employee_id'])) ?></td><td class="text-end"><form method="post" class="d-inline" onsubmit="return confirm('Törlöd a hozzárendelést?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-danger">Törlés</button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div></div></div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
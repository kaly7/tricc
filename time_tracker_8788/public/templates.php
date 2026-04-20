<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
$templates = tracker_templates($config, null, false);
$entryTypes = tracker_entry_types($config);
$absenceTypes = tracker_absence_types($config, false);
$editId = !empty($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editTemplate = $editId > 0 ? tracker_template_find($config, $editId) : null;
if ($editId > 0 && !$editTemplate) {
    tracker_flash_set('error', 'A kiválasztott sablon nem található.');
    tracker_redirect('/templates.php');
}
$title = 'Sablonok';
require __DIR__ . '/../app/views/layout/header.php';
$success = tracker_flash_get('success');
$error = tracker_flash_get('error');
if ($success !== '') echo '<div class="alert alert-success">' . h($success) . '</div>';
if ($error !== '') echo '<div class="alert alert-danger">' . h($error) . '</div>';

$templateTypeValue = (string)($editTemplate['template_type'] ?? 'work');
$entryKindValue = (string)($editTemplate['entry_kind'] ?? 'work');
$absenceTypeValue = (int)($editTemplate['absence_type_id'] ?? 0);
$startTimeValue = substr((string)($editTemplate['start_time'] ?? '08:00:00'), 0, 5) ?: '08:00';
$endTimeValue = substr((string)($editTemplate['end_time'] ?? '16:30:00'), 0, 5) ?: '16:30';
$breakMinutesValue = (int)($editTemplate['break_minutes'] ?? 30);
$noteValue = (string)($editTemplate['note'] ?? '');
$sortOrderValue = (int)($editTemplate['sort_order'] ?? 100);
$isActiveValue = !isset($editTemplate['is_active']) || !empty($editTemplate['is_active']);
?>
<div class="d-flex justify-content-between align-items-center mb-4"><h1 class="h3 mb-0">Sablonok</h1><div class="text-muted">Gyakori munkaidő és távollét minták gyors kitöltéshez.</div></div>
<div class="row g-4 align-items-start">
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header"><div class="fw-semibold"><?= $editTemplate ? 'Sablon szerkesztése' : 'Új sablon' ?></div></div>
      <div class="card-body">
        <form method="post" action="/save_template.php" class="vstack gap-3" id="templateAdminForm">
          <input type="hidden" name="id" value="<?= (int)($editTemplate['id'] ?? 0) ?>">
          <div><label class="form-label">Név</label><input type="text" name="name" class="form-control" required value="<?= h((string)($editTemplate['name'] ?? '')) ?>"></div>
          <div><label class="form-label">Sablon típusa</label><select name="template_type" id="template_type" class="form-select"><option value="work"<?= $templateTypeValue === 'work' ? ' selected' : '' ?>>Munkaidő</option><option value="absence"<?= $templateTypeValue === 'absence' ? ' selected' : '' ?>>Egész napos távollét</option></select></div>
          <div class="template-mode template-mode-work<?= $templateTypeValue === 'absence' ? ' d-none' : '' ?>">
            <div class="mb-3"><label class="form-label">Munkaidő típusa</label><select name="entry_kind" class="form-select"><?php foreach ($entryTypes as $type): ?><option value="<?= h($type['code']) ?>"<?= $entryKindValue === (string)$type['code'] ? ' selected' : '' ?>><?= h($type['label']) ?></option><?php endforeach; ?></select></div>
            <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Kezdés</label><input type="time" name="start_time" step="600" class="form-control" value="<?= h($startTimeValue) ?>"></div><div class="col-6"><label class="form-label">Vége</label><input type="time" name="end_time" step="600" class="form-control" value="<?= h($endTimeValue) ?>"></div></div>
            <div><label class="form-label">Szünet (perc)</label><input type="number" min="0" step="1" name="break_minutes" class="form-control" value="<?= (int)$breakMinutesValue ?>"></div>
          </div>
          <div class="template-mode template-mode-absence<?= $templateTypeValue === 'absence' ? '' : ' d-none' ?>">
            <div><label class="form-label">Távollét típusa</label><select name="absence_type_id" class="form-select"><?php foreach ($absenceTypes as $type): ?><option value="<?= (int)$type['id'] ?>"<?= $absenceTypeValue === (int)$type['id'] ? ' selected' : '' ?>><?= h($type['label']) ?></option><?php endforeach; ?></select></div>
          </div>
          <div><label class="form-label">Megjegyzés</label><textarea name="note" rows="2" class="form-control"><?= h($noteValue) ?></textarea></div>
          <div class="row g-2"><div class="col-6"><label class="form-label">Sorrend</label><input type="number" name="sort_order" class="form-control" value="<?= (int)$sortOrderValue ?>"></div><div class="col-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" id="tpl_active"<?= $isActiveValue ? ' checked' : '' ?>><label class="form-check-label" for="tpl_active">Aktív</label></div></div></div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary"><?= $editTemplate ? 'Mentés' : 'Létrehozás' ?></button>
            <?php if ($editTemplate): ?><a class="btn btn-outline-secondary" href="/templates.php">Mégse</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-header"><div class="fw-semibold">Sablon lista</div></div>
      <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Név</th><th>Típus</th><th>Részletek</th><th>Megjegyzés</th><th></th></tr></thead><tbody>
        <?php foreach ($templates as $tpl): ?>
          <tr>
            <td><?= h($tpl['name']) ?><?= empty($tpl['is_active']) ? ' <span class="badge text-bg-light border">inaktív</span>' : '' ?></td>
            <td><?= $tpl['template_type'] === 'absence' ? 'Távollét' : 'Munkaidő' ?></td>
            <td><?php if ($tpl['template_type'] === 'absence'): ?><?= h((string)($tpl['absence_type_label'] ?? 'Távollét')) ?><?php else: ?><?= h((string)($tpl['entry_type_label'] ?? $tpl['entry_kind'])) ?> · <?= h(substr((string)$tpl['start_time'],0,5)) ?>–<?= h(substr((string)$tpl['end_time'],0,5)) ?> · <?= (int)$tpl['break_minutes'] ?> p<?php endif; ?></td>
            <td><?= h((string)($tpl['note'] ?? '')) ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-secondary" href="/templates.php?edit=<?= (int)$tpl['id'] ?>">Szerkesztés</a>
              <form method="post" action="/delete_template.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd a sablont?');"><input type="hidden" name="id" value="<?= (int)$tpl['id'] ?>"><button class="btn btn-sm btn-outline-danger">Törlés</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
$title = 'Távollét típusok';
$types = tracker_absence_types($config, false);
$editType = !empty($_GET['edit']) ? tracker_absence_type_find($config, (int)$_GET['edit']) : null;
$success = tracker_flash_get('success');
$error = tracker_flash_get('error');
require __DIR__ . '/../app/views/layout/header.php';
if ($success !== '') echo '<div class="alert alert-success">' . h($success) . '</div>';
if ($error !== '') echo '<div class="alert alert-danger">' . h($error) . '</div>';
?>
<div class="d-flex justify-content-between align-items-center mb-4 gap-3">
  <div>
    <h1 class="h3 mb-1">Távollét típusok</h1>
    <div class="text-muted">Egész napos távollétek jelölései, badge-ei és színei.</div>
  </div>
</div>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card shadow-sm"><div class="card-header"><div class="fw-semibold">Típusok</div></div><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>#</th><th>Kód</th><th>Megnevezés</th><th>Badge</th><th>Szín</th><th>Aktív</th><th></th></tr></thead><tbody>
    <?php foreach ($types as $type): ?>
      <tr>
        <td><?= (int)$type['sort_order'] ?></td>
        <td><?= h((string)$type['code']) ?></td>
        <td><?= h((string)$type['label']) ?></td>
        <td><span class="calendar-badge" style="background:<?= h((string)$type['bg_color']) ?>;color:<?= h((string)($type['text_color'] ?: '#111827')) ?>;"><?= h((string)$type['badge_text']) ?></span></td>
        <td><span class="color-preview" style="background:<?= h((string)$type['bg_color']) ?>"></span></td>
        <td><?= !empty($type['is_active']) ? 'Igen' : 'Nem' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/absence_types.php?edit=<?= (int)$type['id'] ?>">Szerkesztés</a> <form method="post" action="/delete_absence_type.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd a típust?');"><input type="hidden" name="id" value="<?= (int)$type['id'] ?>"><button class="btn btn-sm btn-outline-danger">Törlés</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></div></div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm"><div class="card-header"><div class="fw-semibold"><?= $editType ? 'Típus szerkesztése' : 'Új típus' ?></div></div><div class="card-body">
      <form method="post" action="/save_absence_type.php" class="vstack gap-3">
        <input type="hidden" name="id" value="<?= (int)($editType['id'] ?? 0) ?>">
        <div class="row g-2"><div class="col-6"><label class="form-label">Kód</label><input type="text" name="code" class="form-control" value="<?= h((string)($editType['code'] ?? '')) ?>" required></div><div class="col-6"><label class="form-label">Sorrend</label><input type="number" name="sort_order" class="form-control" value="<?= (int)($editType['sort_order'] ?? 100) ?>"></div></div>
        <div><label class="form-label">Megnevezés</label><input type="text" name="label" class="form-control" value="<?= h((string)($editType['label'] ?? '')) ?>" required></div>
        <div><label class="form-label">Badge</label><input type="text" name="badge_text" maxlength="20" class="form-control" value="<?= h((string)($editType['badge_text'] ?? '')) ?>"></div>
        <div class="row g-2"><div class="col-6"><label class="form-label">Háttérszín</label><input type="color" name="bg_color" class="form-control form-control-color" value="<?= h((string)($editType['bg_color'] ?? '#dcfce7')) ?>"></div><div class="col-6"><label class="form-label">Szövegszín</label><input type="color" name="text_color" class="form-control form-control-color" value="<?= h((string)($editType['text_color'] ?? '#166534')) ?>"></div></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="absence_active"<?= !isset($editType['is_active']) || !empty($editType['is_active']) ? ' checked' : '' ?>><label class="form-check-label" for="absence_active">Aktív</label></div>
        <div class="d-flex gap-2"><button class="btn btn-primary">Mentés</button><?php if ($editType): ?><a class="btn btn-outline-secondary" href="/absence_types.php">Mégse</a><?php endif; ?></div>
      </form>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

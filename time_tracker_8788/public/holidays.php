<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
$title = 'Ünnepnapok';
$rows = tracker_holidays_all($config);
$editHoliday = !empty($_GET['edit']) ? tracker_holiday_find($config, (int)$_GET['edit']) : null;
$success = tracker_flash_get('success');
$error = tracker_flash_get('error');
require __DIR__ . '/../app/views/layout/header.php';
if ($success !== '') echo '<div class="alert alert-success">' . h($success) . '</div>';
if ($error !== '') echo '<div class="alert alert-danger">' . h($error) . '</div>';
?>
<div class="d-flex justify-content-between align-items-center mb-4 gap-3">
  <div>
    <h1 class="h3 mb-1">Ünnepnapok</h1>
    <div class="text-muted">Egyedi ünnepnapok és szabadnapok jelölése a naptárban.</div>
  </div>
</div>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card shadow-sm"><div class="card-header"><div class="fw-semibold">Felvitt ünnepnapok</div></div><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Dátum</th><th>Megnevezés</th><th>Badge</th><th>Szín</th><th>Aktív</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= h((string)$row['holiday_date']) ?></td>
        <td><?= h((string)$row['label']) ?></td>
        <td><span class="calendar-badge" style="background:<?= h((string)$row['bg_color']) ?>;color:<?= h((string)($row['text_color'] ?: '#111827')) ?>;"><?= h((string)$row['badge_text']) ?></span></td>
        <td><span class="color-preview" style="background:<?= h((string)$row['bg_color']) ?>"></span></td>
        <td><?= !empty($row['is_active']) ? 'Igen' : 'Nem' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/holidays.php?edit=<?= (int)$row['id'] ?>">Szerkesztés</a> <form method="post" action="/delete_holiday.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd az ünnepnapot?');"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-danger">Törlés</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></div></div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm"><div class="card-header"><div class="fw-semibold"><?= $editHoliday ? 'Ünnepnap szerkesztése' : 'Új ünnepnap' ?></div></div><div class="card-body">
      <form method="post" action="/save_holiday.php" class="vstack gap-3">
        <input type="hidden" name="id" value="<?= (int)($editHoliday['id'] ?? 0) ?>">
        <div><label class="form-label">Dátum</label><input type="date" name="holiday_date" class="form-control" value="<?= h((string)($editHoliday['holiday_date'] ?? '')) ?>" required></div>
        <div><label class="form-label">Megnevezés</label><input type="text" name="label" class="form-control" value="<?= h((string)($editHoliday['label'] ?? '')) ?>" required></div>
        <div><label class="form-label">Badge</label><input type="text" name="badge_text" maxlength="20" class="form-control" value="<?= h((string)($editHoliday['badge_text'] ?? 'ÜN')) ?>"></div>
        <div class="row g-2"><div class="col-6"><label class="form-label">Háttérszín</label><input type="color" name="bg_color" class="form-control form-control-color" value="<?= h((string)($editHoliday['bg_color'] ?? '#fee2e2')) ?>"></div><div class="col-6"><label class="form-label">Szövegszín</label><input type="color" name="text_color" class="form-control form-control-color" value="<?= h((string)($editHoliday['text_color'] ?? '#991b1b')) ?>"></div></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="holiday_active"<?= !isset($editHoliday['is_active']) || !empty($editHoliday['is_active']) ? ' checked' : '' ?>><label class="form-check-label" for="holiday_active">Aktív</label></div>
        <div class="d-flex gap-2"><button class="btn btn-primary">Mentés</button><?php if ($editHoliday): ?><a class="btn btn-outline-secondary" href="/holidays.php">Mégse</a><?php endif; ?></div>
      </form>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
$title = 'Járművek';
$rows = tracker_vehicle_options($config, false);
$editVehicle = !empty($_GET['edit']) ? tracker_vehicle_find($config, (int)$_GET['edit']) : null;
$success = tracker_flash_get('success');
$error = tracker_flash_get('error');
require __DIR__ . '/../app/views/layout/header.php';
if ($success !== '') echo '<div class="alert alert-success">' . h($success) . '</div>';
if ($error !== '') echo '<div class="alert alert-danger">' . h($error) . '</div>';
?>
<div class="d-flex justify-content-between align-items-center mb-4 gap-3">
  <div>
    <h1 class="h3 mb-1">Járművek</h1>
    <div class="text-muted">Rendszámok és az automatikus utazási idő számításához használt átlagsebesség.</div>
  </div>
</div>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card shadow-sm"><div class="card-header"><div class="fw-semibold">Felvitt járművek</div></div><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Rendszám</th><th>Megnevezés</th><th>Átlagseb. (km/h)</th><th>Aktív</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= h((string)$row['plate_number']) ?></td>
        <td><?= h((string)($row['label'] ?? '')) ?></td>
        <td><?= h(rtrim(rtrim(number_format((float)$row['avg_speed_kmh'], 1, '.', ''), '0'), '.')) ?></td>
        <td><?= !empty($row['is_active']) ? 'Igen' : 'Nem' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/vehicles.php?edit=<?= (int)$row['id'] ?>">Szerkesztés</a> <form method="post" action="/delete_vehicle.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd a járművet?');"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-danger">Törlés</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></div></div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm"><div class="card-header"><div class="fw-semibold"><?= $editVehicle ? 'Jármű szerkesztése' : 'Új jármű' ?></div></div><div class="card-body">
      <form method="post" action="/save_vehicle.php" class="vstack gap-3">
        <input type="hidden" name="id" value="<?= (int)($editVehicle['id'] ?? 0) ?>">
        <div><label class="form-label">Rendszám</label><input type="text" name="plate_number" class="form-control" value="<?= h((string)($editVehicle['plate_number'] ?? '')) ?>" required></div>
        <div><label class="form-label">Megnevezés</label><input type="text" name="label" class="form-control" value="<?= h((string)($editVehicle['label'] ?? '')) ?>"></div>
        <div><label class="form-label">Átlagsebesség (km/h)</label><input type="number" min="1" step="0.1" name="avg_speed_kmh" class="form-control" value="<?= h((string)($editVehicle['avg_speed_kmh'] ?? '60')) ?>" required></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="vehicle_active"<?= !isset($editVehicle['is_active']) || !empty($editVehicle['is_active']) ? ' checked' : '' ?>><label class="form-check-label" for="vehicle_active">Aktív</label></div>
        <div class="d-flex gap-2"><button class="btn btn-primary">Mentés</button><?php if ($editVehicle): ?><a class="btn btn-outline-secondary" href="/vehicles.php">Mégse</a><?php endif; ?></div>
      </form>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

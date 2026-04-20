<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) {
    http_response_code(403);
    exit('Nincs jogosultság.');
}
$title = 'Nap színezési szabályok';
$rules = tracker_day_color_rules($config);
$editRule = !empty($_GET['edit']) ? tracker_day_color_rule_find($config, (int)$_GET['edit']) : null;
$success = tracker_flash_get('success');
$error = tracker_flash_get('error');
require __DIR__ . '/../app/views/layout/header.php';
if ($success !== '') echo '<div class="alert alert-success">' . h($success) . '</div>';
if ($error !== '') echo '<div class="alert alert-danger">' . h($error) . '</div>';
?>
<div class="d-flex justify-content-between align-items-center mb-4 gap-3">
  <div>
    <h1 class="h3 mb-1">Nap színezési szabályok</h1>
    <div class="text-muted">Állítható, hogy adott napi percösszeg milyen színt kapjon a naptárban.</div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-header"><div class="fw-semibold">Szabályok</div></div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Perc-tól</th>
                <th>Perc-ig</th>
                <th>Szín</th>
                <th>Megnevezés</th>
                <th>Aktív</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rules as $rule): ?>
              <tr>
                <td><?= (int)$rule['sort_order'] ?></td>
                <td><?= (int)$rule['minutes_from'] ?></td>
                <td><?= (int)$rule['minutes_to'] ?></td>
                <td>
                  <span class="color-preview" style="background:<?= h((string)$rule['bg_color']) ?>"></span>
                  <span class="ms-2 small text-muted"><?= h((string)$rule['bg_color']) ?></span>
                </td>
                <td><?= h((string)$rule['label']) ?></td>
                <td><?= !empty($rule['is_active']) ? 'Igen' : 'Nem' ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-secondary" href="/color_rules.php?edit=<?= (int)$rule['id'] ?>">Szerkesztés</a>
                  <form method="post" action="/delete_color_rule.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd a szabályt?');">
                    <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Törlés</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$rules): ?>
              <tr><td colspan="7" class="text-muted">Nincs még szabály.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header"><div class="fw-semibold"><?= $editRule ? 'Szabály szerkesztése' : 'Új szabály' ?></div></div>
      <div class="card-body">
        <form method="post" action="/save_color_rule.php" class="vstack gap-3">
          <input type="hidden" name="id" value="<?= (int)($editRule['id'] ?? 0) ?>">
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Perc-tól</label>
              <input type="number" min="0" name="minutes_from" value="<?= (int)($editRule['minutes_from'] ?? 0) ?>" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Perc-ig</label>
              <input type="number" min="0" name="minutes_to" value="<?= (int)($editRule['minutes_to'] ?? 0) ?>" class="form-control" required>
            </div>
          </div>
          <div>
            <label class="form-label">Megnevezés</label>
            <input type="text" name="label" class="form-control" placeholder="Pl. Teljes nap" value="<?= h((string)($editRule['label'] ?? '')) ?>">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Háttérszín</label>
              <input type="color" name="bg_color" value="<?= h((string)($editRule['bg_color'] ?? '#dcfce7')) ?>" class="form-control form-control-color">
            </div>
            <div class="col-6">
              <label class="form-label">Szövegszín</label>
              <input type="color" name="text_color" value="<?= h((string)($editRule['text_color'] ?? '#166534')) ?>" class="form-control form-control-color">
            </div>
          </div>
          <div>
            <label class="form-label">Sorrend</label>
            <input type="number" name="sort_order" value="<?= (int)($editRule['sort_order'] ?? 100) ?>" class="form-control">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active"<?= !isset($editRule['is_active']) || !empty($editRule['is_active']) ? ' checked' : '' ?>>
            <label class="form-check-label" for="is_active">Aktív</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary"><?= $editRule ? 'Módosítás mentése' : 'Szabály mentése' ?></button>
            <?php if ($editRule): ?><a class="btn btn-outline-secondary" href="/color_rules.php">Mégse</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Dokumentumtípusok</h3>
</div>

<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title mb-3">Új dokumentumtípus</h5>
        <form method="post" action="/doctypes_create" class="row g-2">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
          <div class="col-8">
            <input class="form-control" type="text" name="name" placeholder="pl. Jogosítvány" required>
          </div>
          <div class="col-4 d-grid">
            <button class="btn btn-primary" type="submit">Hozzáad</button>
          </div>
        </form>
        <div class="form-text mt-2">Ezek jelennek meg a dokumentum feltöltésnél választható listában.</div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <form class="row g-2 mb-2" method="get" action="/doctypes">
      <div class="col-8">
        <input class="form-control form-control-sm" type="text" name="q" value="<?= h($q) ?>" placeholder="Keresés név szerint">
      </div>
      <div class="col-4 d-grid">
        <button class="btn btn-sm btn-outline-secondary" type="submit">Keresés</button>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Név</th>
            <th>Aktív</th>
            <th class="text-end">Művelet</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($types as $t): ?>
            <tr>
              <td><?= (int)$t['id'] ?></td>
              <td><?= h($t['name']) ?></td>
              <td>
                <?= (int)$t['is_active'] === 1
                  ? '<span class="badge bg-success">igen</span>'
                  : '<span class="badge bg-secondary">nem</span>' ?>
              </td>
              <td class="text-end">
                <form method="post" action="/doctypes_toggle" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <?= (int)$t['is_active'] === 1
                    ? '<button class="btn btn-sm btn-outline-danger" type="submit">Letilt</button>'
                    : '<button class="btn btn-sm btn-outline-success" type="submit">Engedélyez</button>' ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($types)): ?>
            <tr><td colspan="4" class="text-muted">Nincs találat.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

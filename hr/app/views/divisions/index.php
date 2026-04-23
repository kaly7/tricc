<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Divíziók</h3>
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
        <h5 class="card-title mb-3">Új divízió</h5>
        <form method="post" action="/divisions_create" class="row g-2">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
          <div class="col-8">
            <input class="form-control" type="text" name="name" placeholder="Divízió neve" required>
          </div>
          <div class="col-4 d-grid">
            <button class="btn btn-primary" type="submit">Hozzáad</button>
          </div>
        </form>
        <div class="form-text mt-2">Ezek fognak megjelenni a dolgozói űrlapon választható listában.</div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <form class="row g-2 mb-2" method="get" action="/divisions">
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
          <?php foreach ($divisions as $d): ?>
            <tr id="div-row-<?= (int)$d['id'] ?>">
              <td><?= (int)$d['id'] ?></td>
              <td>
                <span class="div-name-display"><?= h($d['name']) ?></span>
                <form method="post" action="/divisions_update" class="div-edit-form d-none row g-1 mt-1">
                  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <div class="col-8">
                    <input class="form-control form-control-sm" type="text" name="name" value="<?= h($d['name']) ?>" required>
                  </div>
                  <div class="col-4 d-flex gap-1">
                    <button class="btn btn-sm btn-success" type="submit">Ment</button>
                    <button class="btn btn-sm btn-outline-secondary div-edit-cancel" type="button">&#x2715;</button>
                  </div>
                </form>
              </td>
              <td>
                <?= (int)$d['is_active'] === 1
                  ? '<span class="badge bg-success">igen</span>'
                  : '<span class="badge bg-secondary">nem</span>' ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-primary div-edit-btn me-1" data-id="<?= (int)$d['id'] ?>">Szerkeszt</button>
                <form method="post" action="/divisions_toggle" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <?= (int)$d['is_active'] === 1
                    ? '<button class="btn btn-sm btn-outline-danger" type="submit">Letilt</button>'
                    : '<button class="btn btn-sm btn-outline-success" type="submit">Engedélyez</button>' ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($divisions)): ?>
            <tr><td colspan="4" class="text-muted">Nincs találat.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.div-edit-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var id = this.dataset.id;
    var row = document.getElementById('div-row-' + id);
    row.querySelector('.div-name-display').classList.add('d-none');
    row.querySelector('.div-edit-form').classList.remove('d-none');
    this.classList.add('d-none');
    row.querySelector('.div-edit-form input[name="name"]').focus();
  });
});

document.querySelectorAll('.div-edit-cancel').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var row = this.closest('tr');
    row.querySelector('.div-name-display').classList.remove('d-none');
    row.querySelector('.div-edit-form').classList.add('d-none');
    row.querySelector('.div-edit-btn').classList.remove('d-none');
  });
});
</script>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Felhasználók</h3>
  <a class="btn btn-sm btn-primary" href="/users_create">+ Új felhasználó</a>
</div>

<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get" action="/users">
  <div class="col-sm-6 col-md-4">
    <input class="form-control form-control-sm" type="text" name="q" value="<?= h($q) ?>" placeholder="Keresés név/email szerint">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary" type="submit">Keresés</button>
    <?php if (!empty($q)): ?>
      <a class="btn btn-sm btn-outline-secondary" href="/users">Törlés</a>
    <?php endif; ?>
  </div>
</form>

<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
  <thead>
    <tr>
      <th>ID</th>
      <th>Név</th>
      <th>Email</th>
      <th>Szerepkör</th>
      <th>Aktív</th>
      <th>Utolsó belépés</th>
      <th class="text-end">Művelet</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= (int)$u['id'] ?></td>
        <td><?= h($u['name']) ?></td>
        <td><?= h($u['email']) ?></td>
        <td>
          <?= $u['role'] === 'admin'
            ? '<span class="badge bg-danger">admin</span>'
            : '<span class="badge bg-secondary">user</span>' ?>
        </td>
        <td>
          <?= (int)$u['is_active'] === 1
            ? '<span class="badge bg-success">igen</span>'
            : '<span class="badge bg-secondary">nem</span>' ?>
        </td>
        <td><?= h($u['last_login_at'] ?? '') ?></td>
        <td class="text-end">
          <a class="btn btn-sm btn-outline-secondary" href="/users_edit?id=<?= (int)$u['id'] ?>">Szerkeszt</a>
          <form method="post" action="/users_toggle" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <?= (int)$u['is_active'] === 1
              ? '<button class="btn btn-sm btn-outline-danger" type="submit">Letilt</button>'
              : '<button class="btn btn-sm btn-outline-success" type="submit">Engedélyez</button>' ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>

    <?php if (empty($users)): ?>
      <tr><td colspan="7" class="text-muted">Nincs találat.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

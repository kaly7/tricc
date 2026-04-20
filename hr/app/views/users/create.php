<?php $old = $old ?? []; ?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Új felhasználó</h3>
  <a class="btn btn-sm btn-outline-secondary" href="/users">Vissza</a>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="/users_create" class="card">
  <div class="card-body">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Név</label>
        <input class="form-control" type="text" name="name" value="<?= h($old['name'] ?? '') ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email" value="<?= h($old['email'] ?? '') ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Szerepkör</label>
        <select class="form-select" name="role">
          <option value="user" <?= (($old['role'] ?? 'user') === 'user') ? 'selected' : '' ?>>user</option>
          <option value="admin" <?= (($old['role'] ?? '') === 'admin') ? 'selected' : '' ?>>admin</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Aktív</label>
        <select class="form-select" name="is_active">
          <option value="1" <?= ((int)($old['is_active'] ?? 1) === 1) ? 'selected' : '' ?>>igen</option>
          <option value="0" <?= ((int)($old['is_active'] ?? 1) === 0) ? 'selected' : '' ?>>nem</option>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label">Jelszó</label>
        <input class="form-control" type="password" name="password" required>
        <div class="form-text">Min. 8 karakter.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Jelszó újra</label>
        <input class="form-control" type="password" name="password2" required>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Létrehozás</button>
      <a class="btn btn-outline-secondary" href="/users">Mégse</a>
    </div>
  </div>
</form>

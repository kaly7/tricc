<?php $u = $editUser; ?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Felhasználó szerkesztése</h3>
  <a class="btn btn-sm btn-outline-secondary" href="/users">Vissza</a>
</div>

<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="/users_edit" class="card">
  <div class="card-body">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Név</label>
        <input class="form-control" type="text" name="name" value="<?= h($u['name']) ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email" value="<?= h($u['email']) ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Szerepkör</label>
        <select class="form-select" name="role">
          <option value="user" <?= ($u['role'] === 'user') ? 'selected' : '' ?>>user</option>
          <option value="admin" <?= ($u['role'] === 'admin') ? 'selected' : '' ?>>admin</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Aktív</label>
        <select class="form-select" name="is_active">
          <option value="1" <?= ((int)$u['is_active'] === 1) ? 'selected' : '' ?>>igen</option>
          <option value="0" <?= ((int)$u['is_active'] === 0) ? 'selected' : '' ?>>nem</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Utolsó belépés</label>
        <input class="form-control" type="text" value="<?= h($u['last_login_at'] ?? '') ?>" disabled>
      </div>

      <div class="col-md-6">
        <label class="form-label">Új jelszó (opcionális)</label>
        <input class="form-control" type="password" name="password">
        <div class="form-text">Ha üresen hagyod, nem változik.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Új jelszó újra</label>
        <input class="form-control" type="password" name="password2">
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Mentés</button>
      <a class="btn btn-outline-secondary" href="/users">Vissza</a>
    </div>
  </div>
</form>

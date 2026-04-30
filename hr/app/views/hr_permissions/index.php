<?php
// $hrUsers  = [{id, username, full_name, is_active}, ...]
// $perms    = [user_id => true]
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">HR jogosultságok</h4>
  <div class="d-flex gap-2">
    <a href="/hr_audit_log" class="btn btn-sm btn-outline-secondary">Audit napló</a>
  </div>
</div>

<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Felhasználó</th>
          <th>Username</th>
          <th>Állapot</th>
          <th>HR jogosultság</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($hrUsers)): ?>
          <tr><td colspan="5" class="text-muted text-center py-3">Nincs HR-hozzáféréssel rendelkező felhasználó.</td></tr>
        <?php endif; ?>
        <?php foreach ($hrUsers as $u): ?>
          <tr>
            <td><?= h($u['full_name']) ?></td>
            <td class="text-muted small"><?= h($u['username']) ?></td>
            <td>
              <?php if ((int)$u['is_active']): ?>
                <span class="badge bg-success">Aktív</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inaktív</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($perms[(int)$u['id']])): ?>
                <span class="badge bg-primary">Beállítva</span>
              <?php else: ?>
                <span class="badge bg-light text-muted border">Nincs beállítva</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="/hr_permissions_edit?user_id=<?= (int)$u['id'] ?>"
                 class="btn btn-sm btn-outline-primary">Szerkesztés</a>
              <a href="/hr_audit_log?user_id=<?= (int)$u['id'] ?>"
                 class="btn btn-sm btn-outline-secondary">Napló</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

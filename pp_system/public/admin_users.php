<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect(); if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';
$u = current_user();
$rows = db()->query('SELECT u.id, u.email, u.name, u.is_active, u.role_id, r.name role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY r.id, u.name')->fetchAll();
$roles = db()->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Felhasználók</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f8f9fa; display:flex; flex-direction:column; min-height:100vh; }
main { flex:1; }
footer { text-align:center; padding:1rem 0; font-size:.9rem; color:#666; }
footer::before { content:""; display:block; height:2px; background:linear-gradient(to right,transparent,#555,transparent); margin:0 0 1rem; }
</style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-light" href="admin_users.php">Felhasználók</a>
      <a class="btn btn-sm btn-outline-light" href="admin_dicts.php">Törzsek</a>
      <a class="btn btn-sm btn-outline-light" href="admin_emails.php">E-mail sablonok</a>
      <span class="navbar-text text-white-50 small"><?=h($u['name'])?> (<?=h($u['role'])?>)</span>
      <a class="btn btn-sm btn-outline-light" href="change_password.php">Jelszó</a>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav>

<main class="container my-3">

  <?php if(($_GET['msg'] ?? '') === 'updated'): ?>
    <div class="alert alert-success py-2">Felhasználó adatai frissítve.</div>
  <?php elseif(($_GET['msg'] ?? '') === 'passwd_changed'): ?>
    <div class="alert alert-success py-2">Jelszó sikeresen megváltoztatva.</div>
  <?php elseif(($_GET['err'] ?? '') === 'short'): ?>
    <div class="alert alert-danger py-2">A jelszó túl rövid (min. 8 karakter).</div>
  <?php elseif(($_GET['err'] ?? '') === 'bad'): ?>
    <div class="alert alert-danger py-2">Hibás kérés.</div>
  <?php elseif(($_GET['err'] ?? '') === 'self'): ?>
    <div class="alert alert-warning py-2">Saját fiók nem inaktiválható vagy törölhető.</div>
  <?php elseif(($_GET['err'] ?? '') === 'ref'): ?>
    <div class="alert alert-danger py-2">A felhasználó nem törölhető, mert vannak hozzá kapcsolt adatok. Használd inkább az inaktiválást.</div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- Új felhasználó -->
    <div class="col-md-4">
      <div class="card">
        <div class="card-header fw-semibold">Új felhasználó</div>
        <div class="card-body">
          <form method="post" action="actions/admin_user_create.php" class="row g-2">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <div class="col-12">
              <label class="form-label">Név</label>
              <input class="form-control" name="name" required>
            </div>
            <div class="col-12">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="email" required>
            </div>
            <div class="col-12">
              <label class="form-label">Jelszó</label>
              <input type="text" class="form-control" name="password" required>
            </div>
            <div class="col-12">
              <label class="form-label">Szerepkör</label>
              <select class="form-select" name="role_id">
                <?php foreach ($roles as $role): ?>
                  <option value="<?=$role['id']?>"><?=h($role['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <button class="btn btn-success w-100">Létrehozás</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Lista -->
    <div class="col-md-8">
      <div class="card">
        <div class="card-header fw-semibold">Felhasználók</div>
        <div class="card-body p-0">
          <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Név</th>
                <th>Email</th>
                <th>Szerepkör</th>
                <th>Aktív</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td class="align-middle"><?=h($r['name'])?></td>
                <td class="align-middle"><?=h($r['email'])?></td>
                <td class="align-middle"><span class="badge text-bg-secondary"><?=h($r['role_name'])?></span></td>
                <td class="align-middle"><?=$r['is_active'] ? '<span class="text-success">igen</span>' : '<span class="text-muted">nem</span>'?></td>
                <td class="align-middle text-end">
                  <?php if ($r['id'] != $u['id']): ?>
                    <button class="btn btn-sm btn-outline-primary"
                            onclick="openEdit(<?=htmlspecialchars(json_encode($r))?>)">
                      Szerkesztés
                    </button>
                  <?php else: ?>
                    <span class="text-muted small">Saját fiók</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</main>

<!-- Szerkesztő modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Felhasználó szerkesztése</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="actions/admin_user_update.php" id="editForm" class="row g-3">
          <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="id" id="edit_id">
          <div class="col-12">
            <label class="form-label">Név</label>
            <input class="form-control" name="name" id="edit_name" required>
          </div>
          <div class="col-12">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" id="edit_email" required>
          </div>
          <div class="col-12">
            <label class="form-label">Szerepkör</label>
            <select class="form-select" name="role_id" id="edit_role">
              <?php foreach ($roles as $role): ?>
                <option value="<?=$role['id']?>"><?=h($role['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Új jelszó <span class="text-muted small">(üresen hagyva nem változik)</span></label>
            <input type="password" class="form-control" name="password" id="edit_password" autocomplete="new-password">
          </div>
          <div class="col-12">
            <div class="text-muted small mb-1">Aktív: <span id="edit_active_label"></span></div>
          </div>
        </form>
      </div>
      <div class="modal-footer justify-content-between">
        <div class="d-flex gap-2">
          <form method="post" action="actions/admin_user_toggle_active.php">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="id" id="toggle_id">
            <button type="submit" class="btn btn-sm btn-outline-warning" id="toggleActiveBtn"></button>
          </form>
          <form method="post" action="actions/admin_user_delete.php" onsubmit="return confirm('Biztosan törlöd?')">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="id" id="delete_id">
            <button type="submit" class="btn btn-sm btn-outline-danger">Törlés</button>
          </form>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
          <button type="submit" form="editForm" class="btn btn-primary">Mentés</button>
        </div>
      </div>
    </div>
  </div>
</div>

<footer>© Perfect Phone Munka Nyilvántartó</footer>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
function openEdit(r) {
  document.getElementById('edit_id').value    = r.id;
  document.getElementById('edit_name').value  = r.name;
  document.getElementById('edit_email').value = r.email;
  document.getElementById('edit_role').value  = r.role_id;
  document.getElementById('edit_password').value = '';
  document.getElementById('toggle_id').value  = r.id;
  document.getElementById('delete_id').value  = r.id;
  document.getElementById('edit_active_label').textContent = r.is_active == 1 ? 'igen' : 'nem';
  document.getElementById('toggleActiveBtn').textContent   = r.is_active == 1 ? 'Inaktivál' : 'Aktivál';
  new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>
</body></html>

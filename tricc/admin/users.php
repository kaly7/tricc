<?php
require '_db.php';
tricc_auth();

$db = tricc_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($uid) {
        $isSelf = false; // az admin panel nincs a users táblában

        if ($action === 'toggle_active') {
            $cur = $db->prepare("SELECT is_active FROM users WHERE id=?");
            $cur->execute([$uid]);
            $new = (int)!($cur->fetchColumn());
            $db->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$new, $uid]);
            flash($new ? 'Felhasználó engedélyezve.' : 'Felhasználó letiltva.');

        } elseif ($action === 'toggle_admin') {
            $cur = $db->prepare("SELECT is_admin FROM users WHERE id=?");
            $cur->execute([$uid]);
            $new = (int)!($cur->fetchColumn());
            $db->prepare("UPDATE users SET is_admin=? WHERE id=?")->execute([$new, $uid]);
            flash($new ? 'Admin jog megadva.' : 'Admin jog elvéve.');

        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM push_tokens  WHERE user_id=?")->execute([$uid]);
            $db->prepare("DELETE FROM room_members WHERE user_id=?")->execute([$uid]);
            $db->prepare("DELETE FROM messages     WHERE sender_id=?")->execute([$uid]);
            $db->prepare("DELETE FROM users        WHERE id=?")->execute([$uid]);
            flash('Felhasználó törölve.');

        } elseif ($action === 'edit') {
            $name  = trim($_POST['name']  ?? '');
            $email = trim($_POST['email'] ?? '');
            $pass  = $_POST['password'] ?? '';
            if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Érvénytelen név vagy email cím.', 'danger');
            } else {
                if ($pass) {
                    $db->prepare("UPDATE users SET name=?, email=?, password=? WHERE id=?")
                       ->execute([$name, $email, password_hash($pass, PASSWORD_BCRYPT), $uid]);
                } else {
                    $db->prepare("UPDATE users SET name=?, email=? WHERE id=?")
                       ->execute([$name, $email, $uid]);
                }
                flash('Felhasználó módosítva.');
            }
        }
    }
    header('Location: users.php'); exit;
}

$users = $db->query("
    SELECT u.id, u.name, u.email, u.is_admin, u.is_active, u.created_at,
           (SELECT COUNT(*) FROM push_tokens pt WHERE pt.user_id = u.id) AS has_push
    FROM users u ORDER BY u.id DESC
")->fetchAll();

$title = 'Felhasználók'; $active_page = 'users';
require '_layout.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="bi bi-people"></i> Felhasználók (<?= count($users) ?>)</h5>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Név</th>
          <th>Email</th>
          <th>Push</th>
          <th>Regisztráció</th>
          <th>Státusz</th>
          <th>Műveletek</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td class="text-muted small"><?= $u['id'] ?></td>
          <td>
            <?= htmlspecialchars($u['name']) ?>
            <?php if ($u['is_admin']): ?>
              <span class="badge bg-primary ms-1">Admin</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= htmlspecialchars($u['email']) ?></td>
          <td>
            <?php if ($u['has_push']): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-bell-fill"></i> Van</span>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= date('Y.m.d', strtotime($u['created_at'])) ?></td>
          <td>
            <?php if ($u['is_active']): ?>
              <span class="badge bg-success">Aktív</span>
            <?php else: ?>
              <span class="badge bg-secondary">Tiltott</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="btn-group btn-group-sm">
              <!-- Szerkesztés -->
              <button type="button" class="btn btn-outline-secondary"
                      onclick="openEdit(<?= $u['id'] ?>, <?= htmlspecialchars(json_encode($u['name'])) ?>, <?= htmlspecialchars(json_encode($u['email'])) ?>)"
                      title="Szerkesztés">
                <i class="bi bi-pencil"></i>
              </button>
              <!-- Tiltás / Engedélyezés -->
              <form method="post" class="d-inline">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <input type="hidden" name="action" value="toggle_active">
                <button type="submit" class="btn btn-outline-<?= $u['is_active'] ? 'warning' : 'success' ?>"
                        title="<?= $u['is_active'] ? 'Letiltás' : 'Engedélyezés' ?>">
                  <i class="bi bi-<?= $u['is_active'] ? 'ban' : 'check-circle' ?>"></i>
                </button>
              </form>
              <!-- Admin jog -->
              <form method="post" class="d-inline">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <input type="hidden" name="action" value="toggle_admin">
                <button type="submit" class="btn btn-outline-<?= $u['is_admin'] ? 'secondary' : 'primary' ?>"
                        title="<?= $u['is_admin'] ? 'Admin jog elvétele' : 'Admin jog adása' ?>">
                  <i class="bi bi-person-<?= $u['is_admin'] ? 'dash' : 'check' ?>"></i>
                </button>
              </form>
              <!-- Törlés -->
              <button type="button" class="btn btn-outline-danger"
                      onclick="confirmDelete(<?= $u['id'] ?>, <?= htmlspecialchars(json_encode($u['name'])) ?>)"
                      title="Törlés">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Szerkesztés modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil"></i> Felhasználó szerkesztése</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Név</label>
            <input type="text" name="name" id="editName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" name="email" id="editEmail" class="form-control" required>
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold">Új jelszó <span class="text-muted fw-normal">(hagyd üresen ha nem változtatod)</span></label>
            <input type="password" name="password" class="form-control" placeholder="••••••">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
          <button type="submit" class="btn btn-primary">Mentés</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Törlés megerősítő modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="user_id" id="deleteUserId">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle"></i> Törlés</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body pt-1">
          <p class="mb-0">Biztosan törlöd <strong id="deleteUserName"></strong> felhasználót? Az üzenetei is törlődnek.</p>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Mégse</button>
          <button type="submit" class="btn btn-danger btn-sm">Törlöm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openEdit(id, name, email) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editName').value   = name;
    document.getElementById('editEmail').value  = email;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function confirmDelete(id, name) {
    document.getElementById('deleteUserId').value  = id;
    document.getElementById('deleteUserName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</div>
</body>
</html>

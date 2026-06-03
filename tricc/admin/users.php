<?php
require '_db.php';
tricc_auth();

$db = tricc_db();

// Akciók
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($uid && $uid !== $_SESSION['tricc_admin']['id']) {
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
        }
    }
    header('Location: users.php'); exit;
}

$users = $db->query("
    SELECT u.id, u.name, u.email, u.avatar_url, u.is_admin, u.is_active, u.created_at,
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
          <td><?= htmlspecialchars($u['email']) ?></td>
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
            <?php if ($u['id'] !== $_SESSION['tricc_admin']['id']): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <input type="hidden" name="action" value="toggle_active">
              <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                      onclick="return confirm('Biztosan?')">
                <?= $u['is_active'] ? '<i class="bi bi-ban"></i> Tilt' : '<i class="bi bi-check-circle"></i> Enged' ?>
              </button>
            </form>
            <form method="post" class="d-inline ms-1">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <input type="hidden" name="action" value="toggle_admin">
              <button type="submit" class="btn btn-sm <?= $u['is_admin'] ? 'btn-outline-secondary' : 'btn-outline-primary' ?>"
                      onclick="return confirm('Biztosan?')">
                <?= $u['is_admin'] ? '<i class="bi bi-person-dash"></i> Admin elvesz' : '<i class="bi bi-person-check"></i> Admin ad' ?>
              </button>
            </form>
            <?php else: ?>
              <span class="text-muted small">(te vagy)</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</div>
</body>
</html>

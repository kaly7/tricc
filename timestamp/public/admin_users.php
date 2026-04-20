<?php
require_once __DIR__ . '/../functions.php';
require_login(); require_admin();

$error=null; $ok=null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] === 'admin' ? 'admin' : 'user';
        $pass = $_POST['password'] ?? '';
        if ($username && $email && $pass) {
            try {
                db()->prepare("INSERT INTO users (username, full_name, password_hash, email, role) VALUES (:u,:f,:p,:e,:r)")
                  ->execute([':u'=>$username, ':f'=>$full_name ?: null, ':p'=>password_hash($pass, PASSWORD_DEFAULT), ':e'=>$email, ':r'=>$role]);
                $ok = 'Felhasználó létrehozva.';
            } catch (PDOException $ex) {
                $error = 'Hiba: ' . h($ex->getMessage());
            }
        } else {
            $error = 'Minden kötelező mezőt tölts ki (felhasználónév, e-mail, jelszó).';
        }
    } elseif ($action === 'delete_user') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid === (int)current_user()['id']) {
            $error = 'Saját fiókot nem törölhetsz.';
        } else {
            // utolsó admin védelem
            $roleStmt = db()->prepare("SELECT role FROM users WHERE id=:id");
            $roleStmt->execute([':id'=>$uid]);
            $u = $roleStmt->fetch();
            if (!$u) {
                $error = 'Felhasználó nem található.';
            } else {
                if ($u['role'] === 'admin') {
                    $cnt = (int)db()->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c'];
                    if ($cnt <= 1) {
                        $error = 'Az utolsó admin nem törölhető.';
                    } else {
                        db()->prepare("DELETE FROM users WHERE id=:id")->execute([':id'=>$uid]);
                        $ok = 'Felhasználó törölve.';
                    }
                } else {
                    db()->prepare("DELETE FROM users WHERE id=:id")->execute([':id'=>$uid]);
                    $ok = 'Felhasználó törölve.';
                }
            }
        }
    }
}

$users = db()->query("SELECT id, username, full_name, email, role, created_at FROM users ORDER BY username")->fetchAll();

include __DIR__ . '/common_header.php';
?>
<div class="card">
  <h2>Felhasználók</h2>
  <?php if ($error): ?><div class="error"><?= $error ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="notice"><?= $ok ?></div><?php endif; ?>
  <div class="grid cols-2">
    <div>
      <h3>Új felhasználó</h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create">
        <label>Felhasználónév</label><input name="username" required>
        <label>Teljes név (opcionális)</label><input name="full_name" placeholder="Pl. Kovács Péter">
        <label>E-mail</label><input type="email" name="email" required>
        <label>Jelszó</label><input type="password" name="password" required>
        <label>Szerepkör</label>
        <select name="role">
          <option value="user">felhasználó</option>
          <option value="admin">admin</option>
        </select>
        <div class="mt10"><button>Létrehozás</button></div>
      </form>
    </div>
    <div>
      <h3>Lista</h3>
      <div class="table-container">
        <table class="table">
          <thead><tr><th>Felhasználónév</th><th>Teljes név</th><th>E-mail</th><th>Szerepkör</th><th>Regisztrált</th><th>Művelet</th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td data-label="Felhasználónév"><?= h($u['username']) ?></td>
                <td data-label="Teljes név"><?= h($u['full_name'] ?? '') ?></td>
                <td data-label="E-mail"><?= h($u['email']) ?></td>
                <td data-label="Szerepkör"><?= h($u['role']) ?></td>
                <td data-label="Regisztrált"><?= h($u['created_at']) ?></td>
                <td data-label="Művelet">
                  <form method="post" onsubmit="return confirm('Biztosan törlöd ezt a felhasználót?');" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="secondary">Törlés</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/common_footer.php'; ?>

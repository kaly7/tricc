<?php
require_once __DIR__ . '/../functions.php';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (isset($_POST['action']) && $_POST['action'] === 'create_admin') {
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($username && $email && $pass) {
            try {
                db()->prepare("INSERT INTO users (username, full_name, theme, password_hash, email, role) VALUES (:u,:f,'modern',:p,:e,'admin')")
                  ->execute([':u'=>$username, ':f'=>$full_name ?: null, ':p'=>password_hash($pass, PASSWORD_DEFAULT), ':e'=>$email]);
                $_SESSION['user'] = [
                    'id' => (int)db()->lastInsertId(),
                    'username' => $username,
                    'full_name' => $full_name ?: null,
                    'email' => $email,
                    'role' => 'admin',
                    'theme' => 'modern',
                ];
                $_SESSION['theme'] = 'modern';
                redirect('/projects.php');
            } catch (PDOException $ex) {
                $error = 'Nem sikerült létrehozni az admin felhasználót: ' . h($ex->getMessage());
            }
        } else {
            $error = 'Minden mező kötelező.';
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($username && $pass) {
            $stmt = db()->prepare("SELECT * FROM users WHERE username = :u");
            $stmt->execute([':u'=>$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($pass, $user['password_hash'])) {
                $_SESSION['user'] = [
                    'id' => (int)$user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'theme' => $user['theme'] ?? 'modern',
                ];
                $_SESSION['theme'] = $user['theme'] ?? 'modern';
                redirect('/calendar.php');
            } else {
                $error = 'Hibás felhasználónév vagy jelszó.';
            }
        } else {
            $error = 'Add meg a felhasználónevet és jelszót.';
        }
    }
}

include __DIR__ . '/common_header.php';
?>
<div class="card">
  <h2>Belépés</h2>
  <?php if ($error): ?><div class="error"><?= $error ?></div><?php endif; ?>
  <?php if (!first_admin_exists()): ?>
    <p class="notice">Nincsenek felhasználók. Hozd létre az első <strong>admin</strong> felhasználót.</p>
    <form method="post" class="grid cols-2">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="create_admin">
      <div><label>Felhasználónév</label><input name="username" required></div>
      <div><label>Teljes név (opcionális)</label><input name="full_name"></div>
      <div><label>E-mail</label><input type="email" name="email" required></div>
      <div><label>Jelszó</label><input type="password" name="password" required></div>
      <div style="grid-column:1/-1" class="mt10"><button>Létrehozás és belépés</button></div>
    </form>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <label>Felhasználónév</label><input name="username" required>
      <label>Jelszó</label><input type="password" name="password" required>
      <div class="mt10"><button>Belépés</button></div>
    </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/common_footer.php'; ?>

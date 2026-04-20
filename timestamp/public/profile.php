<?php
require_once __DIR__ . '/../functions.php';
require_login();
$u = current_user();
$error = null; $ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $newpass = $_POST['new_password'] ?? '';
    $theme = $_POST['theme'] ?? 'modern';
    $allowed = ['modern','light','dark','industrial','playful'];
    if (!in_array($theme, $allowed, true)) $theme = 'modern';

    if ($email === '') {
        $error = 'Az e-mail nem lehet üres.';
    } else {
        $params = [':email'=>$email, ':full_name'=>($full_name ?: null), ':theme'=>$theme, ':id'=>$u['id']];
        $sql = "UPDATE users SET email=:email, full_name=:full_name, theme=:theme";
        if ($newpass !== '') {
            $sql .= ", password_hash=:ph";
            $params[':ph'] = password_hash($newpass, PASSWORD_DEFAULT);
        }
        $sql .= " WHERE id=:id";
        db()->prepare($sql)->execute($params);

        $_SESSION['user']['email'] = $email;
        $_SESSION['user']['full_name'] = $full_name ?: null;
        $_SESSION['user']['theme'] = $theme;
        $_SESSION['theme'] = $theme;

        $ok = 'Profil frissítve.';
    }
}

// aktuális adatok
$stmt = db()->prepare("SELECT username, email, full_name, theme FROM users WHERE id=:id");
$stmt->execute([':id'=>$u['id']]);
$me = $stmt->fetch();

include __DIR__ . '/common_header.php';
?>
<div class="card">
  <h2>Profil</h2>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="notice"><?= h($ok) ?></div><?php endif; ?>
  <form method="post" class="grid cols-2">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div>
      <label>Felhasználónév</label>
      <input value="<?= h($me['username']) ?>" disabled>
    </div>
    <div>
      <label>Teljes név (opcionális)</label>
      <input name="full_name" value="<?= h($me['full_name'] ?? '') ?>" placeholder="Pl. Kovács Péter">
    </div>
    <div>
      <label>E-mail</label>
      <input type="email" name="email" value="<?= h($me['email']) ?>" required>
    </div>
    <div>
      <label>Új jelszó (ha változtatnál)</label>
      <input type="password" name="new_password" placeholder="Hagyd üresen, ha nem változik">
    </div>
    <div>
      <label>Téma</label>
      <select name="theme">
        <?php
          $themes = ['modern'=>'Modern','light'=>'Világos','dark'=>'Sötét','industrial'=>'Industrial','playful'=>'Vidám'];
          foreach ($themes as $k=>$label):
            $sel = ($me['theme'] ?? 'modern') === $k ? 'selected' : '';
            echo '<option value="'.h($k).'" '.$sel.'>'.h($label).'</option>';
          endforeach;
        ?>
      </select>
      <p class="small">A választás mentés után lép életbe.</p>
    </div>
    <div style="grid-column:1/-1" class="mt10"><button>Mentés</button></div>
  </form>
</div>
<?php include __DIR__ . '/common_footer.php'; ?>

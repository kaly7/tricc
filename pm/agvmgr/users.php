<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_login();

$db      = agv_db();
$msg     = '';
$err     = '';
$is_admin = !empty($_SESSION['agv_admin']);
$me       = $_SESSION['agv_user'];

// ── Saját jelszó változtatás ─────────────────────────────────────────────────
if (isset($_POST['change_pw'])) {
    $cur  = $_POST['cur_pw']   ?? '';
    $new1 = $_POST['new_pw']   ?? '';
    $new2 = $_POST['new_pw2']  ?? '';

    $row = $db->query("SELECT password FROM users WHERE username='" . $db->real_escape_string($me) . "' LIMIT 1")->fetch_assoc();
    if (!$row || !password_verify($cur, $row['password'])) {
        $err = 'A jelenlegi jelszó helytelen.';
    } elseif (strlen($new1) < 6) {
        $err = 'Az új jelszó legalább 6 karakter legyen.';
    } elseif ($new1 !== $new2) {
        $err = 'A két új jelszó nem egyezik.';
    } else {
        $hash = password_hash($new1, PASSWORD_BCRYPT);
        $st   = $db->prepare("UPDATE users SET password=? WHERE username=?");
        $st->bind_param('ss', $hash, $me);
        $st->execute(); $st->close();
        $msg = 'Jelszó sikeresen megváltoztatva.';
    }
}

// ── Admin: felhasználó hozzáadása ─────────────────────────────────────────────
if ($is_admin && isset($_POST['add_user'])) {
    $uname  = trim($_POST['new_username'] ?? '');
    $upass  = $_POST['new_password'] ?? '';
    $uadmin = isset($_POST['new_is_admin']) ? 1 : 0;

    if ($uname === '' || strlen($upass) < 6) {
        $err = 'Felhasználónév kötelező, jelszó min. 6 karakter.';
    } else {
        $hash = password_hash($upass, PASSWORD_BCRYPT);
        $st   = $db->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, ?)");
        $st->bind_param('ssi', $uname, $hash, $uadmin);
        if ($st->execute()) {
            $msg = "Felhasználó létrehozva: $uname";
        } else {
            $err = 'Felhasználónév már foglalt.';
        }
        $st->close();
    }
}

// ── Admin: jelszó reset ───────────────────────────────────────────────────────
if ($is_admin && isset($_POST['reset_pw'])) {
    $uid   = (int)$_POST['reset_uid'];
    $pass1 = $_POST['reset_pw1'] ?? '';
    $pass2 = $_POST['reset_pw2'] ?? '';

    if (strlen($pass1) < 6) {
        $err = 'A jelszó legalább 6 karakter legyen.';
    } elseif ($pass1 !== $pass2) {
        $err = 'A két jelszó nem egyezik.';
    } else {
        $hash = password_hash($pass1, PASSWORD_BCRYPT);
        $st   = $db->prepare("UPDATE users SET password=? WHERE id=?");
        $st->bind_param('si', $hash, $uid);
        $st->execute(); $st->close();
        $msg = 'Jelszó visszaállítva.';
    }
}

// ── Admin: admin jog váltás ───────────────────────────────────────────────────
if ($is_admin && isset($_GET['toggle_admin'])) {
    $uid = (int)$_GET['toggle_admin'];
    $row = $db->query("SELECT username, is_admin FROM users WHERE id=$uid")->fetch_assoc();
    if ($row && $row['username'] !== $me) {
        $new_val = $row['is_admin'] ? 0 : 1;
        $db->query("UPDATE users SET is_admin=$new_val WHERE id=$uid");
        $msg = $row['username'] . ' joga ' . ($new_val ? 'Admin' : 'Felhasználó') . ' lett.';
    } else {
        $err = 'Saját admin jogot nem veheted el.';
    }
}

// ── Admin: törlés ─────────────────────────────────────────────────────────────
if ($is_admin && isset($_GET['del_user'])) {
    $uid = (int)$_GET['del_user'];
    $row = $db->query("SELECT username FROM users WHERE id=$uid")->fetch_assoc();
    if ($row && $row['username'] !== $me) {
        $db->query("DELETE FROM users WHERE id=$uid");
        $msg = "Felhasználó törölve: {$row['username']}";
    } else {
        $err = 'Saját fiókot nem törölheted.';
    }
}

$users = $is_admin ? $db->query("SELECT * FROM users ORDER BY id")->fetch_all(MYSQLI_ASSOC) : [];

$page  = 'users';
$title = 'Felhasználók';
require __DIR__ . '/_header.php';
?>

<?php if ($msg): ?>
  <div class="agv-alert agv-alert-ok">✓ <?= e($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="agv-alert agv-alert-err">✗ <?= e($err) ?></div>
<?php endif; ?>

<?php if ($is_admin): ?>
<!-- ── Felhasználók listája ─────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold">Felhasználók</span>
    <span class="badge bg-secondary"><?= count($users) ?> db</span>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">#</th>
          <th>Felhasználónév</th>
          <th>Jog</th>
          <th>Létrehozva</th>
          <th>Jelszó reset</th>
          <th>Műveletek</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td class="ps-3 text-muted small"><?= $u['id'] ?></td>
          <td>
            <strong><?= e($u['username']) ?></strong>
            <?php if ($u['username'] === $me): ?>
              <span class="badge bg-primary ms-1" style="font-size:10px">én</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($u['is_admin']): ?>
              <span class="badge bg-danger">Admin</span>
            <?php else: ?>
              <span class="badge bg-secondary">Felhasználó</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= e($u['created']) ?></td>
          <td>
            <form method="post" class="d-flex gap-1" style="min-width:260px">
              <input type="hidden" name="reset_uid" value="<?= $u['id'] ?>">
              <input type="password" name="reset_pw1" class="form-control form-control-sm" placeholder="Új jelszó" required minlength="6" style="max-width:120px">
              <input type="password" name="reset_pw2" class="form-control form-control-sm" placeholder="Ismét" required minlength="6" style="max-width:120px">
              <button type="submit" name="reset_pw" class="btn btn-sm btn-outline-warning">Reset</button>
            </form>
          </td>
          <td>
            <div class="d-flex gap-1">
              <?php if ($u['username'] !== $me): ?>
                <a href="users.php?toggle_admin=<?= $u['id'] ?>"
                   class="btn btn-sm <?= $u['is_admin'] ? 'btn-outline-secondary' : 'btn-outline-danger' ?>"
                   onclick="return confirm('<?= $u['is_admin'] ? 'Admin jog elvétele?' : 'Admin jog adása?' ?>')">
                  <?= $u['is_admin'] ? 'Admin → User' : 'User → Admin' ?>
                </a>
                <a href="users.php?del_user=<?= $u['id'] ?>"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('Törlöd: <?= e($u['username']) ?>?')">Törlés</a>
              <?php else: ?>
                <span class="text-muted small">–</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Új felhasználó ──────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-header"><span class="fw-semibold">Új felhasználó</span></div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <div class="col-12 col-md-4">
        <label class="form-label">Felhasználónév</label>
        <input type="text" name="new_username" class="form-control" required autocomplete="off">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Jelszó <span class="text-muted small">(min. 6 kar.)</span></label>
        <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
      </div>
      <div class="col-12 col-md-3 d-flex align-items-end">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="new_is_admin" id="new_is_admin">
          <label class="form-check-label" for="new_is_admin">Admin jog</label>
        </div>
      </div>
      <div class="col-12 col-md-2 d-flex align-items-end">
        <button type="submit" name="add_user" class="btn btn-primary w-100">Létrehozás</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Saját jelszó változtatás ───────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-header"><span class="fw-semibold">Saját jelszó módosítása</span>
    <span class="text-muted small ms-2">(<?= e($me) ?>)</span>
  </div>
  <div class="card-body">
    <form method="post" class="row g-3" style="max-width:520px">
      <div class="col-12">
        <label class="form-label">Jelenlegi jelszó</label>
        <input type="password" name="cur_pw" class="form-control" required autocomplete="current-password">
      </div>
      <div class="col-12">
        <label class="form-label">Új jelszó <span class="text-muted small">(min. 6 karakter)</span></label>
        <input type="password" name="new_pw" class="form-control" required minlength="6" autocomplete="new-password">
      </div>
      <div class="col-12">
        <label class="form-label">Új jelszó mégegyszer</label>
        <input type="password" name="new_pw2" class="form-control" required minlength="6" autocomplete="new-password">
      </div>
      <div class="col-12">
        <button type="submit" name="change_pw" class="btn btn-primary">Jelszó módosítása</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

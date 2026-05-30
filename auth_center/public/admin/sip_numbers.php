<?php
declare(strict_types=1);
require_once __DIR__ . '/_admin_bootstrap.php';
require_once __DIR__ . '/_sip_helper.php';

$title    = 'SIP Admin – Számok';
$loggedIn = true;
$msg = $err = '';

// -----------------------------------------------------------------------
// POST actions
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = (string)($_POST['action'] ?? '');
    $numbers = sip_numbers_read();

    if ($action === 'add' || $action === 'edit') {
        $id     = (int)($_POST['id'] ?? 0);
        $sip_n  = trim($_POST['sip_number']   ?? '');
        $sip_pw = trim($_POST['sip_password']  ?? '');
        $app_u  = trim($_POST['app_username']  ?? '');
        $app_pw = trim($_POST['app_password']  ?? '');
        $label  = trim($_POST['label']         ?? '');
        $enabled= !empty($_POST['enabled']);

        if (!$sip_n || !$sip_pw || !$app_u || !$app_pw) {
            $err = 'Minden mező kitöltése kötelező.';
        } else {
            if ($action === 'add') {
                $numbers[] = [
                    'id'           => sip_next_id($numbers),
                    'label'        => $label,
                    'sip_number'   => $sip_n,
                    'sip_password' => $sip_pw,
                    'app_username' => $app_u,
                    'app_password' => $app_pw,
                    'enabled'      => true,
                ];
                $msg = "Szám hozzáadva: $sip_n → $app_u";
            } else {
                foreach ($numbers as &$n) {
                    if ((int)$n['id'] === $id) {
                        $n['label']        = $label;
                        $n['sip_number']   = $sip_n;
                        $n['sip_password'] = $sip_pw;
                        $n['app_username'] = $app_u;
                        $n['app_password'] = $app_pw;
                        $n['enabled']      = $enabled;
                        break;
                    }
                }
                unset($n);
                $msg = "Szám frissítve: $sip_n";
            }
            sip_numbers_write($numbers);
            $res = sip_apply($numbers);
            if (!$res['ok']) $err = 'Asterisk reload hiba: ' . $res['output'];
            else $msg .= ' — Asterisk reload OK';
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        foreach ($numbers as &$n) {
            if ((int)$n['id'] === $id) { $n['enabled'] = !($n['enabled'] ?? true); break; }
        }
        unset($n);
        sip_numbers_write($numbers);
        $res = sip_apply($numbers);
        $msg = 'Állapot megváltoztatva.' . ($res['ok'] ? ' Reload OK.' : ' Reload hiba: ' . $res['output']);
    }

    if ($action === 'delete') {
        $id      = (int)($_POST['id'] ?? 0);
        $numbers = array_values(array_filter($numbers, fn($n) => (int)$n['id'] !== $id));
        sip_numbers_write($numbers);
        $res = sip_apply($numbers);
        $msg = 'Szám törölve.' . ($res['ok'] ? ' Reload OK.' : ' Reload hiba: ' . $res['output']);
    }

    // Re-read after changes
    $numbers = sip_numbers_read();
} else {
    $numbers = sip_numbers_read();
}

// Edit mode
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_num = null;
if ($edit_id) {
    foreach ($numbers as $n) { if ((int)$n['id'] === $edit_id) { $edit_num = $n; break; } }
}

$next_app = sip_next_app_username($numbers);

require __DIR__ . '/../../app/views/layout/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&display=swap');
.sip-mono { font-family: 'JetBrains Mono', monospace; font-size: .82rem; }
.sip-nav .nav-link        { color: #6c757d; border-radius: 6px; padding: .35rem .9rem; font-size: .875rem; font-weight: 500; }
.sip-nav .nav-link:hover  { background: #f0f4ff; color: #0d6efd; }
.sip-nav .nav-link.active { background: #0d6efd; color: #fff; }
.sip-header  { border-left: 4px solid #0d6efd; padding-left: .75rem; }
.status-bar  { height:3px; background:linear-gradient(90deg,#0d6efd,#6610f2); border-radius:2px; margin-bottom:1.25rem; }
.fab { position:fixed; bottom:2rem; right:2rem; z-index:1050; width:52px; height:52px; border-radius:50%;
       font-size:1.4rem; line-height:1; box-shadow:0 4px 16px rgba(13,110,253,.35); }
.pw-wrap { position:relative; display:inline-flex; align-items:center; }
.pw-wrap input { padding-right:2.2rem; }
.pw-eye { position:absolute; right:.5rem; cursor:pointer; color:#6c757d; background:none; border:none; padding:0; font-size:.9rem; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <div class="status-bar" style="width:120px"></div>
    <h1 class="h4 m-0 sip-header">SIP Admin</h1>
  </div>
</div>

<ul class="nav sip-nav gap-1 mb-4">
  <li class="nav-item"><a class="nav-link" href="sip.php">Dashboard</a></li>
  <li class="nav-item"><a class="nav-link" href="sip_log.php">Napló</a></li>
  <li class="nav-item"><a class="nav-link active" href="sip_numbers.php">Számok</a></li>
</ul>

<?php if ($msg): ?><div class="alert alert-success alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button><?= h($err) ?></div><?php endif; ?>

<!-- Add / Edit form -->
<div class="card shadow-sm mb-4" id="form-card">
  <div class="card-header py-2 fw-semibold">
    <?= $edit_num ? 'Szám szerkesztése' : 'Új szám hozzáadása' ?>
  </div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="action" value="<?= $edit_num ? 'edit' : 'add' ?>">
      <?php if ($edit_num): ?><input type="hidden" name="id" value="<?= (int)$edit_num['id'] ?>"><?php endif; ?>

      <div class="col-12 col-md-3">
        <label class="form-label">SIP telefonszám <span class="text-danger">*</span></label>
        <input type="text" class="form-control sip-mono" name="sip_number"
               placeholder="pl. 92400005" required
               value="<?= h($edit_num['sip_number'] ?? '') ?>">
        <div class="form-text">Az upstream szerveren lévő szám</div>
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">SIP jelszó <span class="text-danger">*</span></label>
        <div class="pw-wrap w-100">
          <input type="password" class="form-control sip-mono w-100" name="sip_password" id="sip_pw"
                 required value="<?= h($edit_num['sip_password'] ?? '') ?>">
          <button type="button" class="pw-eye" onclick="togglePw('sip_pw',this)">👁</button>
        </div>
      </div>

      <div class="col-12 col-md-2">
        <label class="form-label">App felhasználónév <span class="text-danger">*</span></label>
        <input type="text" class="form-control sip-mono" name="app_username"
               required value="<?= h($edit_num['app_username'] ?? $next_app) ?>">
        <div class="form-text">pl. app1, app2 …</div>
      </div>

      <div class="col-12 col-md-2">
        <label class="form-label">App jelszó <span class="text-danger">*</span></label>
        <div class="pw-wrap w-100">
          <input type="password" class="form-control sip-mono w-100" name="app_password" id="app_pw"
                 required value="<?= h($edit_num['app_password'] ?? 'app1234') ?>">
          <button type="button" class="pw-eye" onclick="togglePw('app_pw',this)">👁</button>
        </div>
      </div>

      <div class="col-12 col-md-2">
        <label class="form-label">Megjegyzés</label>
        <input type="text" class="form-control" name="label"
               placeholder="pl. Recepció" value="<?= h($edit_num['label'] ?? '') ?>">
      </div>

      <?php if ($edit_num): ?>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="enabled" id="chk_enabled"
                 <?= ($edit_num['enabled'] ?? true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="chk_enabled">Engedélyezett</label>
        </div>
      </div>
      <?php endif; ?>

      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <?= $edit_num ? 'Mentés' : '+ Hozzáadás' ?>
        </button>
        <?php if ($edit_num): ?>
          <a href="sip_numbers.php" class="btn btn-outline-secondary">Mégse</a>
        <?php endif; ?>
        <span class="text-muted small align-self-center ms-2">Mentés után az Asterisk automatikusan újratölti a konfigurációt.</span>
      </div>
    </form>
  </div>
</div>

<!-- Numbers list -->
<div class="card shadow-sm">
  <div class="card-header py-2 d-flex align-items-center">
    <span class="fw-semibold">Regisztrált számok</span>
    <span class="badge bg-secondary ms-2"><?= count($numbers) ?></span>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3">#</th>
          <th>SIP szám</th>
          <th>App user</th>
          <th>SIP jelszó</th>
          <th>App jelszó</th>
          <th>Megjegyzés</th>
          <th>Állapot</th>
          <th>Műveletek</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$numbers): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Még nincs szám hozzáadva.</td></tr>
      <?php else: foreach ($numbers as $num): $en = (bool)($num['enabled'] ?? true); ?>
        <tr class="<?= $en ? '' : 'table-secondary text-muted' ?>">
          <td class="ps-3 sip-mono text-muted"><?= (int)$num['id'] ?></td>
          <td class="sip-mono fw-semibold"><?= h($num['sip_number']) ?></td>
          <td class="sip-mono"><?= h($num['app_username']) ?></td>
          <td>
            <div class="pw-wrap">
              <input type="password" class="form-control form-control-sm sip-mono" style="width:130px;border:none;background:transparent"
                     value="<?= h($num['sip_password']) ?>" id="sp_<?= (int)$num['id'] ?>" readonly>
              <button type="button" class="pw-eye" onclick="togglePw('sp_<?= (int)$num['id'] ?>',this)" style="position:static;margin-left:.25rem">👁</button>
            </div>
          </td>
          <td>
            <div class="pw-wrap">
              <input type="password" class="form-control form-control-sm sip-mono" style="width:110px;border:none;background:transparent"
                     value="<?= h($num['app_password']) ?>" id="ap_<?= (int)$num['id'] ?>" readonly>
              <button type="button" class="pw-eye" onclick="togglePw('ap_<?= (int)$num['id'] ?>',this)" style="position:static;margin-left:.25rem">👁</button>
            </div>
          </td>
          <td class="small text-muted"><?= h($num['label'] ?? '') ?></td>
          <td>
            <?= $en
              ? '<span class="badge bg-success">Aktív</span>'
              : '<span class="badge bg-secondary">Letiltva</span>' ?>
          </td>
          <td>
            <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$num['id'] ?>#form-card">Szerkeszt</a>

            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$num['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $en ? 'Letilt' : 'Engedélyez' ?></button>
            </form>

            <form method="post" class="d-inline" onsubmit="return confirmDelete('<?= h($num['sip_number']) ?>')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$num['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">Töröl</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!$edit_num): ?>
<button class="btn btn-primary fab" onclick="document.getElementById('form-card').scrollIntoView({behavior:'smooth'})" title="Új szám">+</button>
<?php endif; ?>

<script>
function togglePw(id, btn) {
  const el = document.getElementById(id);
  if (!el) return;
  el.type = el.type === 'password' ? 'text' : 'password';
  btn.textContent = el.type === 'password' ? '👁' : '🙈';
}
function confirmDelete(num) {
  return confirm('Biztosan törlöd a ' + num + ' számot?\nAz Asterisk konfiguráció azonnal frissül!');
}
</script>

<?php require __DIR__ . '/../../app/views/layout/footer.php'; ?>

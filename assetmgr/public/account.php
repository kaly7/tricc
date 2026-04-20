<?php
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/auth.php';
require_login();

$title = 'Fiók beállítások';
require_once __DIR__.'/_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $action = (string)($_POST['action'] ?? 'change_password');

  if ($action === 'save_toolbook_settings') {
    $u = current_user();
    if (($u['role'] ?? '') !== 'admin') {
      flash_set('err', 'Ehhez nincs jogosultságod.');
      redirect('account.php');
    }

    $email = trim((string)($_POST['toolbook_central_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      flash_set('err', 'A központi email cím formátuma hibás.');
      redirect('account.php');
    }

    try {
      app_setting_set('toolbook_central_email', $email, (int)($u['id'] ?? 0));
      flash_set('ok', $email === ''
        ? 'A szerszámkönyv archív email küldése kikapcsolva.'
        : 'A szerszámkönyv archív email címe elmentve.');
    } catch (Throwable $e) {
      flash_set('err', 'A beállítás mentése nem sikerült: ' . $e->getMessage());
    }
    redirect('account.php');
  }

  $cur = (string)($_POST['current_password'] ?? '');
  $nw1 = (string)($_POST['new_password'] ?? '');
  $nw2 = (string)($_POST['new_password2'] ?? '');
  $res = change_my_password($cur, $nw1, $nw2);
  if ($res['ok']) {
    flash_set('ok', $res['msg']);
  } else {
    flash_set('err', $res['msg']);
  }
  redirect('account.php');
}

?>

<?php
$isAdmin = ((current_user()['role'] ?? '') === 'admin');
$toolbookCentralEmail = trim((string)app_setting_get('toolbook_central_email', ''));
?>

<h1 class="h3 mb-3">Fiók</h1>

<div class="card">
  <div class="card-body">
    <h2 class="h5">Jelszó módosítása</h2>
    <p class="text-muted small mb-3">
      Itt az Auth Center felhasználód jelszavát tudod megváltoztatni. A módosítás minden modulra (pl. HR, PBX, stb.) érvényes lesz.
    </p>

    <form method="post" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

      <div class="mb-3">
        <label class="form-label">Jelenlegi jelszó</label>
        <input type="password" name="current_password" class="form-control" required>
      </div>

      <div class="mb-3">
  <label class="form-label">Új jelszó</label>
  <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6">
  <div class="form-text">
    Minimum 6 karakter, és legalább 3-at tartalmazzon ezek közül: kisbetű, nagybetű, szám, speciális karakter.
  </div>

  <div class="mt-2">
    <div class="progress" style="height: 8px;">
      <div id="pwBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
    </div>
    <div id="pwText" class="small mt-2 text-muted">Jelszó erősség: —</div>
    <ul class="small mt-2 mb-0" style="padding-left: 18px;">
      <li id="ruleLen" class="text-muted">Legalább 6 karakter</li>
      <li id="ruleLower" class="text-muted">Kisbetű</li>
      <li id="ruleUpper" class="text-muted">Nagybetű</li>
      <li id="ruleDigit" class="text-muted">Szám</li>
      <li id="ruleSpec" class="text-muted">Speciális karakter</li>
      <li id="rule3of4" class="text-muted">Legalább 3 a fenti 4-ből</li>
    </ul>
  </div>
</div>

      <div class="mb-3">
        <label class="form-label">Új jelszó ismét</label>
        <input type="password" id="new_password2" name="new_password2" class="form-control" required minlength="6">
      </div>

      <button class="btn btn-primary">Jelszó mentése</button>
    </form>
  </div>
</div>

<script>
(function() {
  const pw = document.getElementById('new_password');
  const pw2 = document.getElementById('new_password2');
  const bar = document.getElementById('pwBar');
  const txt = document.getElementById('pwText');
  const btn = document.querySelector('button.btn.btn-primary');

  const ruleLen   = document.getElementById('ruleLen');
  const ruleLower = document.getElementById('ruleLower');
  const ruleUpper = document.getElementById('ruleUpper');
  const ruleDigit = document.getElementById('ruleDigit');
  const ruleSpec  = document.getElementById('ruleSpec');
  const rule3of4  = document.getElementById('rule3of4');

  function setRule(el, ok) {
    if (!el) return;
    el.classList.remove('text-muted','text-danger','text-success');
    el.classList.add(ok ? 'text-success' : 'text-danger');
  }

  function scorePassword(s) {
    const hasLower = /[a-z]/.test(s);
    const hasUpper = /[A-Z]/.test(s);
    const hasDigit = /[0-9]/.test(s);
    const hasSpec  = /[^a-zA-Z0-9]/.test(s);
    const lenOk = s.length >= 6;
    const cats = [hasLower, hasUpper, hasDigit, hasSpec].filter(Boolean).length;
    const ok = lenOk && cats >= 3;
    return {hasLower,hasUpper,hasDigit,hasSpec,lenOk,cats,ok};
  }

  function update() {
    const s = pw ? pw.value : '';
    const r = scorePassword(s);

    setRule(ruleLen, r.lenOk);
    setRule(ruleLower, r.hasLower);
    setRule(ruleUpper, r.hasUpper);
    setRule(ruleDigit, r.hasDigit);
    setRule(ruleSpec, r.hasSpec);
    setRule(rule3of4, r.cats >= 3);

    // progress: categories (0..4) + length OK bonus
    let pct = Math.min(4, r.cats) * 20;
    if (r.lenOk) pct += 20;
    pct = Math.max(0, Math.min(100, pct));
    if (bar) bar.style.width = pct + '%';

    let label = '—';
    if (s.length === 0) {
      label = '—';
    } else if (r.ok) {
      label = 'Megfelel';
    } else if (r.lenOk && r.cats === 2) {
      label = 'Közepes (még 1 kategória kell)';
    } else {
      label = 'Gyenge';
    }

    if (txt) {
      txt.textContent = 'Jelszó erősség: ' + label;
      txt.classList.remove('text-muted','text-danger','text-success');
      txt.classList.add(r.ok ? 'text-success' : (s.length ? 'text-danger' : 'text-muted'));
    }

    // match check (optional) – don't block save if empty
    const matchOk = !pw2 || pw2.value === '' || pw2.value === s;

    if (btn) btn.disabled = !(r.ok && matchOk);
  }

  if (pw) pw.addEventListener('input', update);
  if (pw2) pw2.addEventListener('input', update);
  update();
})();
</script>


<?php if ($isAdmin): ?>
  <div class="card mt-4">
    <div class="card-body">
      <h2 class="h5">Szerszámkönyv archív email</h2>
      <p class="text-muted small mb-3">
        Itt adható meg az az archív email cím, amelyre a szerszámkönyv PDF a külön „PDF küldése archívumba” gombbal elküldhető.
        Üresen hagyva az archív küldés nem lesz elérhető.
      </p>

      <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_toolbook_settings">

        <div class="mb-3">
          <label class="form-label">Központi email cím</label>
          <input type="email" name="toolbook_central_email" class="form-control" value="<?= e($toolbookCentralEmail) ?>" placeholder="kozpont@ceg.hu">
          <div class="form-text">Ez a cím kapja meg a dolgozói szerszámkönyv PDF-et az archív küldés gomb használatakor.</div>
        </div>

        <button class="btn btn-primary">Beállítás mentése</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__.'/_footer.php'; ?>

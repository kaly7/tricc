<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth_bootstrap.php';

CentralAuth::requireLogin($config);

$title = 'Jelszó módosítása';
$loggedIn = true;

$msg = '';
$err = '';

/**
 * Same rules as AssetMgr:
 * - min 6 chars
 * - at least 3 of: lower, upper, digit, special
 */
function password_rules_ok(string $p): bool {
  if (mb_strlen($p) < 6) return false;
  $cats = 0;
  if (preg_match('/[a-z]/', $p)) $cats++;
  if (preg_match('/[A-Z]/', $p)) $cats++;
  if (preg_match('/[0-9]/', $p)) $cats++;
  if (preg_match('/[^a-zA-Z0-9]/', $p)) $cats++;
  return $cats >= 3;
}

function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['_csrf'];
}

function verify_csrf(): void {
  $t = (string)($_POST['_csrf'] ?? '');
  if ($t === '' || empty($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], $t)) {
    http_response_code(400);
    exit('CSRF');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();

  $cur = (string)($_POST['current_password'] ?? '');
  $nw1 = (string)($_POST['new_password'] ?? '');
  $nw2 = (string)($_POST['new_password2'] ?? '');

  $pdo = auth_pdo($config);

  $st = $pdo->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
  $st->execute([CentralAuth::userId()]);
  $row = $st->fetch();

  if (!$row || !password_verify($cur, (string)$row['password_hash'])) {
    $err = 'A jelenlegi jelszó hibás.';
  } elseif ($nw1 !== $nw2) {
    $err = 'A két új jelszó nem egyezik.';
  } elseif (!password_rules_ok($nw1)) {
    $err = 'Az új jelszó nem felel meg a szabályoknak.';
  } else {
    $hash = password_hash($nw1, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, CentralAuth::userId()]);
    $msg = 'Jelszó sikeresen módosítva.';
  }
}

require __DIR__ . '/../app/views/layout/header.php';
?>

<h1 class="h3 mb-3">Fiók</h1>

<div class="card">
  <div class="card-body">
    <h2 class="h5">Jelszó módosítása</h2>
    <p class="text-muted small mb-3">
      Itt az Auth Center felhasználód jelszavát tudod megváltoztatni. A módosítás minden modulra (pl. HR, PBX, stb.) érvényes lesz.
    </p>

    <?php if ($msg): ?>
      <div class="alert alert-success"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?= h($err) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

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
      <a class="btn btn-outline-secondary ms-2" href="/apps.php">Vissza</a>
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

    const matchOk = !pw2 || pw2.value === '' || pw2.value === s;
    if (btn) btn.disabled = !(r.ok && matchOk);
  }

  if (pw) pw.addEventListener('input', update);
  if (pw2) pw2.addEventListener('input', update);
  update();
})();
</script>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

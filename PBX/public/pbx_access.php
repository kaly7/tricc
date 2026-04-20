<?php
require __DIR__.'/../app/auth.php';
require_login();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare("
  SELECT p.*, m.name AS manufacturer_name, ci.model AS type_model
  FROM pbx_systems p
  LEFT JOIN catalog_items ci ON ci.id=p.catalog_item_id
  LEFT JOIN manufacturers m ON m.id=ci.manufacturer_id
  WHERE p.id=?
");
$st->execute([$id]);
$pbx = $st->fetch();
if (!$pbx) { http_response_code(404); exit('Nincs ilyen központ'); }

$devSt = $pdo->prepare("
  SELECT d.*, m.name AS manufacturer_name, ci.model AS type_model, ci.default_admin_url, ci.default_reboot_url
  FROM pbx_devices d
  LEFT JOIN catalog_items ci ON ci.id=d.catalog_item_id
  LEFT JOIN manufacturers m ON m.id=ci.manufacturer_id
  WHERE d.pbx_id=? AND d.is_archived=0 AND d.access_url IS NOT NULL AND d.access_url <> ''
  ORDER BY d.extension
");
$devSt->execute([$id]);
$devices = $devSt->fetchAll();

$role = (string)(current_user()['role'] ?? 'viewer');
$canSeeSecrets = in_array($role, ['admin','editor'], true);


function build_join_url(string $base, string $suffix): string {
  $base = trim($base);
  $suffix = trim($suffix);
  if ($base === '' || $suffix === '') return '';
  $baseHasSlash = str_ends_with($base, '/');
  $suffixHasSlash = str_starts_with($suffix, '/');
  if (!$baseHasSlash && !$suffixHasSlash) return $base . '/' . $suffix;
  if ($baseHasSlash && $suffixHasSlash) return $base . ltrim($suffix, '/');
  return $base . $suffix;
}


$title = 'Belépés';
$page  = 'Központok';
require __DIR__.'/_header.php';

$pbxLabel = trim(($pbx['manufacturer_name'] ?? '').' '.($pbx['type_model'] ?? ''));
$pbxUrl = (string)($pbx['access_url'] ?? '');
$pbxUser = (string)($pbx['access_user'] ?? '');
$pbxPass = (string)($pbx['access_pass'] ?? '');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h3 mb-1">Belépés</h1>
    <div class="text-muted small"><?= e($pbx['name']) ?><?= $pbxLabel ? ' · '.e($pbxLabel) : '' ?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_system_show.php?id='.$id)) ?>">Vissza</a>
    <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_systems.php')) ?>">Lista</a>
  </div>
</div>

<div class="row g-3">
  <!-- LEFT PANEL -->
  <div class="col-12 col-lg-4 col-xl-3">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
          <div>
            <div class="text-muted small mb-1">Központ</div>
            <div class="fw-semibold"><?= e($pbx['name']) ?></div>
            <?php if (!empty($pbx['location'])): ?>
              <div class="text-muted small"><?= e($pbx['location']) ?></div>
            <?php endif; ?>
          </div>
          <?php if (!empty($pbx['kind'])): ?>
            <span class="badge <?= ($pbx['kind']==='digital') ? 'bg-primary' : 'bg-secondary' ?>">
              <?= ($pbx['kind']==='digital') ? 'IP' : 'Analóg' ?>
            </span>
          <?php endif; ?>
        </div>

        <hr>

        <div class="text-muted small mb-2">Hozzáférés</div>

        <div class="mb-2">
          <div class="d-flex align-items-center justify-content-between">
            <div class="small text-muted">Felhasználó</div>
            <button type="button" class="btn btn-sm btn-outline-secondary js-copy" data-copy="<?= e($pbxUser) ?>" <?= $pbxUser===''?'disabled':'' ?> title="Másolás">📋</button>
          </div>
          <div class="font-monospace small bg-light border rounded px-2 py-1 mt-1">
            <?= e($pbxUser ?: '—') ?>
          </div>
        </div>

        <div class="mb-3">
          <div class="d-flex align-items-center justify-content-between">
            <div class="small text-muted">Jelszó</div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-sm btn-outline-secondary js-copy" data-copy="<?= e($pbxPass) ?>" <?= $pbxPass===''?'disabled':'' ?> title="Másolás">📋</button>
              <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-secret" data-target="pbxPassDisp" <?= ($pbxPass==='' || !$canSeeSecrets)?'disabled':'' ?> title="<?= $canSeeSecrets ? 'Megjelenítés' : 'Nincs jogosultság' ?>">
                <span class="js-eye">👁️</span>
              </button>
            </div>
          </div>
          <div id="pbxPassDisp" class="font-monospace small bg-light border rounded px-2 py-1 mt-1"
               data-secret="<?= e($pbxPass) ?>" data-visible="0">
            <?php if ($pbxPass===''): ?>
              —
            <?php else: ?>
              ••••••••
            <?php endif; ?>
          </div>
          <?php if (!$canSeeSecrets && $pbxPass!==''): ?>
            <div class="form-text">A jelszó megtekintéséhez nincs jogosultságod, de másolni tudod.</div>
          <?php endif; ?>
        </div>

        <div class="d-grid gap-2">
          <?php if ($pbxUrl): ?>
            <a class="btn btn-success" href="<?= e($pbxUrl) ?>" target="_blank" rel="noopener">Központ megnyitása új lapon</a>
          <?php else: ?>
            <button class="btn btn-outline-secondary" disabled>Nincs beállított URL</button>
          <?php endif; ?>
        </div>

        <hr>

        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="text-muted small">Eszközök (URL-lel)</div>
          <span class="badge bg-info text-dark"><?= (int)count($devices) ?></span>
        </div>

        <?php if (!$devices): ?>
          <div class="text-muted small">Nincs URL-lel rendelkező eszköz.</div>
        <?php else: ?>
          <div class="list-group list-group-flush" id="deviceList">
            <?php foreach ($devices as $d):
              $label = trim(($d['manufacturer_name'] ?? '').' '.($d['type_model'] ?? ''));
              $durl = (string)($d['access_url'] ?? '');
              $duser = (string)($d['access_user'] ?? '');
              $dpass = (string)($d['access_pass'] ?? '');
              $dadmin = (string)($d['admin_url'] ?? '');
$dreboot = (string)($d['reboot_url'] ?? '');
$dadmin_def = (string)($d['default_admin_url'] ?? '');
$dreboot_def = (string)($d['default_reboot_url'] ?? '');
$admin_src = $dadmin!=='' ? $dadmin : $dadmin_def;
$reboot_src = $dreboot!=='' ? $dreboot : $dreboot_def;
$dadmin_full = $admin_src!=='' ? build_join_url($durl, $admin_src) : '';
$dreboot_full = $reboot_src!=='' ? build_join_url($durl, $reboot_src) : '';
?>
              <button type="button"
                class="list-group-item list-group-item-action js-select-device"
                data-title="<?= e('Mellék '.$d['extension'].($label?(' · '.$label):'')) ?>"
                data-url="<?= e($durl) ?>"
                data-user="<?= e($duser) ?>"
                data-pass="<?= e($dpass) ?>"
                data-admin-url="<?= e($dadmin_full) ?>"
                data-reboot-url="<?= e($dreboot_full) ?>">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <div class="fw-semibold">Mellék <?= e($d['extension']) ?></div>
                    <div class="text-muted small"><?= e($label ?: '—') ?></div>
                  </div>
                  <span class="text-muted">↗</span>
                </div>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="col-12 col-lg-8 col-xl-9">
    <div class="card">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div>
            <div class="text-muted small">Megnyitandó felület</div>
            <div class="fw-semibold" id="targetTitle">Központ</div>
          </div>
          <div class="d-flex gap-2 flex-wrap mb-3" id="targetButtons">
            <a class="btn btn-primary" id="openBtn" href="<?= e($pbxUrl ?: '#') ?>" target="_blank" rel="noopener" <?= $pbxUrl ? '' : 'aria-disabled="true"' ?>>Megnyitás új lapon</a>
            <a class="btn btn-outline-info d-none" id="adminBtn" href="#" target="_blank" rel="noopener">Admin</a>
            <button type="button" class="btn btn-outline-warning d-none" id="rebootBtn">Reboot</button>

            <button type="button" class="btn btn-outline-secondary" id="copyUserBtn" <?= $pbxUser===''?'disabled':'' ?>>Felhasználó másolása</button>
            <button type="button" class="btn btn-outline-secondary" id="copyPassBtn" <?= $pbxPass===''?'disabled':'' ?>>Jelszó másolása</button>
          </div>
        </div>

        <div class="alert alert-info py-2 mb-3">
          <div class="small">A kiválasztott felület külön böngészőfülön nyílik meg kattintás után.</div>
        </div>

        <div class="row g-2">
          <div class="col-12 col-md-6">
            <div class="small text-muted d-flex align-items-center justify-content-between">
              <span>Felhasználó</span>
              <button type="button" class="btn btn-sm btn-outline-secondary js-copy" id="copyCurUser" title="Másolás">📋</button>
            </div>
            <div id="curUserDisp" class="font-monospace small bg-light border rounded px-2 py-2">—</div>
          </div>
          <div class="col-12 col-md-6">
            <div class="small text-muted d-flex align-items-center justify-content-between">
              <span>Jelszó</span>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary js-copy" id="copyCurPass" title="Másolás">📋</button>
                <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-secret" data-target="curPassDisp" id="toggleCurPass" title="<?= $canSeeSecrets ? 'Megjelenítés' : 'Nincs jogosultság' ?>" <?= $canSeeSecrets ? '' : 'disabled' ?>>
                  <span class="js-eye">👁️</span>
                </button>
              </div>
            </div>
            <div id="curPassDisp" class="font-monospace small bg-light border rounded px-2 py-2"
                 data-secret="" data-visible="0">—</div>
          </div>
        </div>

      </div>
    </div>

    <div class="card mt-3">
      <div class="card-body">
        <div class="text-muted small mb-2">Jelenlegi cél URL</div>
        <div class="font-monospace small bg-light border rounded px-2 py-2" id="targetUrl"><?= e($pbxUrl ?: '—') ?></div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  function confirmReboot(url){
    if(!url) return;
    if (confirm('Biztosan újraindítod ezt az eszközt?')) {
      window.open(url, '_blank', 'noopener');
    }
  }

  async function copyText(t){
    if (!t) return;
    try{
      await navigator.clipboard.writeText(t);
      toast('Másolva');
    }catch(e){
      const ta = document.createElement('textarea');
      ta.value = t;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      toast('Másolva');
    }
  }

  function setSecretVisible(el, visible){
    const secret = el.getAttribute('data-secret') || '';
    el.setAttribute('data-visible', visible ? '1' : '0');
    if (!secret) { el.textContent = '—'; return; }
    el.textContent = visible ? secret : '••••••••';
  }

  document.querySelectorAll('.js-toggle-secret').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const targetId = btn.getAttribute('data-target');
      if (!targetId) return;
      const el = document.getElementById(targetId);
      if (!el) return;
      const secret = el.getAttribute('data-secret') || '';
      if (!secret) return;
      const visible = el.getAttribute('data-visible') === '1';
      setSecretVisible(el, !visible);
    });
  });

  const pbxPassEl = document.getElementById('pbxPassDisp');
  if (pbxPassEl) setSecretVisible(pbxPassEl, false);

  function toast(msg){
    const el = document.createElement('div');
    el.className = 'position-fixed bottom-0 end-0 p-3';
    el.style.zIndex = 1080;
    el.innerHTML = '<div class="toast show" role="alert" aria-live="assertive" aria-atomic="true"><div class="toast-body">'+msg+'</div></div>';
    document.body.appendChild(el);
    setTimeout(()=>{ el.remove(); }, 1200);
  }

  document.querySelectorAll('.js-copy').forEach(btn=>{
    btn.addEventListener('click', ()=> copyText(btn.getAttribute('data-copy')||''));
  });

  const targetTitle = document.getElementById('targetTitle');
  const targetUrlEl = document.getElementById('targetUrl');
  const openBtn = document.getElementById('openBtn');
  const adminBtn = document.getElementById('adminBtn');
  const rebootBtn = document.getElementById('rebootBtn');
  const copyUserBtn = document.getElementById('copyUserBtn');
  const copyPassBtn = document.getElementById('copyPassBtn');

  let curUser = <?= json_encode($pbxUser, JSON_UNESCAPED_UNICODE) ?>;
  let curPass = <?= json_encode($pbxPass, JSON_UNESCAPED_UNICODE) ?>;
  let curAdminUrl = '';
  let curRebootUrl = '';

  function setTarget(title, url, user, pass, adminUrl, rebootUrl){
    targetTitle.textContent = title || '—';
    targetUrlEl.textContent = url || '—';
    openBtn.href = url || '#';
    openBtn.classList.toggle('disabled', !url);
    openBtn.setAttribute('aria-disabled', url ? 'false' : 'true');

    curUser = user || '';
    curPass = pass || '';
    curAdminUrl = adminUrl || '';
    curRebootUrl = rebootUrl || '';

    if (adminBtn) {
      if (curAdminUrl) { adminBtn.classList.remove('d-none'); adminBtn.href = curAdminUrl; }
      else { adminBtn.classList.add('d-none'); adminBtn.href = '#'; }
    }
    if (rebootBtn) {
      if (curRebootUrl) rebootBtn.classList.remove('d-none');
      else rebootBtn.classList.add('d-none');
    }

    copyUserBtn.disabled = !curUser;
    copyPassBtn.disabled = !curPass;

    const userDisp = document.getElementById('curUserDisp');
    const passDisp = document.getElementById('curPassDisp');
    const toggleP = document.getElementById('toggleCurPass');

    if (userDisp) userDisp.textContent = (curUser || '—');
    if (passDisp) {
      passDisp.setAttribute('data-secret', (curPass || ''));
      passDisp.setAttribute('data-visible', '0');
      passDisp.textContent = curPass ? '••••••••' : '—';
    }
    if (toggleP) toggleP.disabled = !curPass || <?= $canSeeSecrets ? 'false' : 'true' ?>;
  }

  copyUserBtn.addEventListener('click', ()=> copyText(curUser));
  copyPassBtn.addEventListener('click', ()=> copyText(curPass));
  if (rebootBtn) rebootBtn.addEventListener('click', ()=> confirmReboot(curRebootUrl));

  const copyCurUser = document.getElementById('copyCurUser');
  const copyCurPass = document.getElementById('copyCurPass');
  if (copyCurUser) copyCurUser.addEventListener('click', ()=> copyText(curUser));
  if (copyCurPass) copyCurPass.addEventListener('click', ()=> copyText(curPass));

  document.querySelectorAll('.js-select-device').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.querySelectorAll('.js-select-device.active').forEach(a=>a.classList.remove('active'));
      btn.classList.add('active');
      setTarget(
        btn.getAttribute('data-title') || 'Eszköz',
        btn.getAttribute('data-url') || '',
        btn.getAttribute('data-user') || '',
        btn.getAttribute('data-pass') || '',
        btn.getAttribute('data-admin-url') || '',
        btn.getAttribute('data-reboot-url') || ''
      );
    });
  });

  setTarget('Központ', <?= json_encode($pbxUrl, JSON_UNESCAPED_UNICODE) ?>, curUser, curPass, '', '');
})();
</script>

<?php require __DIR__.'/_footer.php'; ?>

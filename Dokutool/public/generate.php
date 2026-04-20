<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
require __DIR__ . '/../app/render.php';
include __DIR__ . '/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$templates = $pdo->query("SELECT id, name FROM templates ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$partners  = $pdo->query("SELECT id, megnevezes FROM partners ORDER BY megnevezes ASC")->fetchAll(PDO::FETCH_ASSOC);
$projects  = $pdo->query("SELECT id, megnevezes FROM projects ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

function have_bin($bin){
  $out = @shell_exec('which '.escapeshellarg($bin).' 2>/dev/null');
  return trim((string)$out) !== '';
}
$have_wk = have_bin('wkhtmltopdf');
$have_chr = have_bin('google-chrome') || have_bin('chromium') || have_bin('chromium-browser');
?>
<style>
#overlay{position:fixed;inset:0;background:rgba(255,255,255,.9);display:none;align-items:center;justify-content:center;z-index:9999;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
.loader{border:6px solid #eee;border-top:6px solid #09f;border-radius:50%;width:54px;height:54px;animation:spin 1s linear infinite;margin:0 auto 12px;}
@keyframes spin{100%{transform:rotate(360deg)}}
#overlay .box{text-align:center;padding:18px 24px;border:1px solid #ddd;border-radius:12px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.08);}
#overlay .progress{margin-top:8px;color:#666;font-size:14px;}
</style>

<div class="container">
  <div class="card hdr">
    <div><strong>Generálás</strong> — válassz sablonokat, partnereket és egy projektet</div>
    <div><a class="btn" href="index.php">← Főmenü</a> <a class="btn" href="batches.php">📁 Archívum</a></div>
  </div>

  <form method="post" action="generate_run.php" onsubmit="return startGen();">
    <div class="card">
      <div class="input">
        <label>Generálás neve (archív könyvtár neve is)</label>
        <input name="batch_name" placeholder="pl. 2025-10-25 Ajánlatok – Projekt X" required>
      </div>
    </div>

    <div class="card">
      <h3>Sablonok (többet is jelölhetsz)</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px;">
        <?php foreach($templates as $t): ?>
          <label class="chk"><input type="checkbox" name="template_ids[]" value="<?= (int)$t['id'] ?>"><span><?= htmlspecialchars($t['name']) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h3>Partnerek (többet is jelölhetsz)</h3>
      <div class="input"><input type="text" id="pf" placeholder="Gyors szűrés névre..." oninput="filterPartners(this.value)"></div>
      <div id="partnersWrap" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px;max-height:360px;overflow:auto;">
        <?php foreach($partners as $p): ?>
          <label class="chk partner"><input type="checkbox" name="partner_ids[]" value="<?= (int)$p['id'] ?>"><span><?= htmlspecialchars($p['megnevezes']) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h3>Projekt (csak egy)</h3>
      <div class="input">
        <select name="project_id" required>
          <option value="">— válassz —</option>
          <?php foreach($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['megnevezes']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="card">
      <h3>Beállítások</h3>
      <div class="grid cols-3">
        <div class="input"><label>Alap kép szélesség</label><input name="imgw" value="40mm" placeholder="pl. 40mm vagy 300px"></div>
        <div class="input">
          <label>PDF renderelő</label>
          <select name="renderer">
            <?php if($have_chr): ?><option value="chrome" selected>Chrome headless</option><?php endif; ?>
            <?php if($have_wk): ?><option value="wkhtmltopdf" <?= $have_chr?'':'selected' ?>>Wkhtmltopdf</option><?php endif; ?>
            <option value="dompdf" <?= ($have_chr||$have_wk)?'':'selected' ?>>Dompdf</option>
            <option value="html">Csak HTML</option>
          </select>
          <small class="muted">Chrome/Wkhtmltopdf adja a legjobb egyezést a HTML-képpel.</small>
        </div>
        <div class="input"><label>Oldaltörés sablonok között</label><select name="pagebreak"><option value="always" selected>Mindig</option><option value="avoid">Ne legyen</option></select></div>
      </div>
    </div>

    <div class="card">
      <h3>Email beállítások (küldés a csomag nézetből indítható)</h3>
      <div class="grid cols-2">
        <label class="chk"><input type="checkbox" name="email_per_partner" value="1" checked> <span>Partnerenként egy email (alapértelmezett)</span></label>
        <div class="input">
          <label>Címzettek forrása</label>
          <select name="recipient_mode">
            <option value="contacts_all" selected>Partner kapcsolattartók — minden email</option>
            <option value="contacts_primary">Partner kapcsolattartók — csak elsődleges</option>
          </select>
        </div>
      </div>
      <div class="grid cols-3">
        <div class="input"><label>További címzettek (To; vesszővel)</label><input name="extra_to" placeholder="pl. info@ceg.hu, iroda@masik.hu"></div>
        <div class="input"><label>CC (vesszővel)</label><input name="extra_cc" placeholder="pl. vezeto@ceg.hu"></div>
        <div class="input"><label>BCC (vesszővel)</label><input name="extra_bcc" placeholder="pl. archiv@ceg.hu"></div>
      </div>
      <div class="input"><label>Email tárgy</label><input name="email_subject" placeholder="pl. Ajánlat – {{ partner.megnevezes }} – {{ project.megnevezes }}"></div>
      <div class="input"><label>Email szöveg (HTML megengedett)</label><textarea name="email_body" rows="6" placeholder="Tisztelt {{ contact.nev }},&#10;&#10;Csatolva küldjük: {{ template.name }}.&#10;&#10;Üdvözlettel"></textarea></div>
      <small class="muted">Ha több sablon van és be van kapcsolva a „Partnerenként egy email”, akkor egy emailben több csatolmány megy.</small>
    </div>

    <div style="text-align:right;"><button class="btn primary" type="submit">⚙ Generálás</button></div>
  </form>
</div>

<div id="overlay"><div class="box"><div class="loader"></div><div>Dokumentumok generálása folyamatban...</div><div class="progress">Kérjük, ne zárd be ezt az ablakot.</div></div></div>

<script>
function startGen(){
  const t = document.querySelectorAll('input[name="template_ids[]"]:checked').length;
  const p = document.querySelectorAll('input[name="partner_ids[]"]:checked').length;
  if (t===0){ alert('Válassz legalább 1 sablont.'); return false; }
  if (p===0){ alert('Válassz legalább 1 partnert.'); return false; }
  document.getElementById('overlay').style.display='flex';
  return true;
}
function filterPartners(q){
  q = (q||'').toLowerCase();
  document.querySelectorAll('#partnersWrap .partner').forEach(el=>{
    const txt = el.innerText.toLowerCase();
    el.style.display = txt.includes(q) ? '' : 'none';
  });
}
</script>
<?php include __DIR__ . '/footer.php'; ?>

<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php';
include __DIR__ . '/header.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$hostNoPort = explode(':', $host)[0];
$authApps = 'http://' . $hostNoPort . ':90/apps.php';
$authLogout = 'http://' . $hostNoPort . ':90/logout.php';
?>
<style>
:root{
  --bg:#f5f7fa; --card:#fff; --line:#dfe5eb; --txt:#111; --muted:#667085;
  --btn:#0b6efd; --btn-txt:#fff; --btn-line:#cfd7df;
  --hover:#eef3ff;
}
html,body{margin:0;padding:0;background:var(--bg);color:var(--txt);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
.container{max-width:1100px;margin:24px auto;padding:0 16px;}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 32px rgba(0,0,0,.06);}
.hdr{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;margin-bottom:16px;}
.hdr h1{font-size:20px;margin:0}
.main{display:grid;grid-template-columns: 340px 1fr; gap:16px; align-items:stretch}
@media (max-width: 900px){ .main{grid-template-columns:1fr} }

.menu{padding:14px}
.menu .hint{font-size:13px;color:var(--muted);margin:0 0 10px 0}
.menu .list{display:flex;flex-direction:column;gap:8px}
.menu .btn{
  display:flex;align-items:center;gap:10px;
  padding:12px 14px;border:1px solid var(--btn-line);border-radius:12px;
  text-decoration:none;color:var(--txt);background:#fff;cursor:pointer;font-size:15px;font-weight:600;
}
.menu .btn:hover, .menu .btn:focus{outline:none;background:var(--hover);border-color:#b8c6ff}
.menu .btn small{font-weight:400;color:var(--muted);margin-left:auto}

.panel{padding:18px 20px;display:flex;flex-direction:column;gap:10px;}
.panel .title{font-weight:700}
.panel .desc{color:var(--muted)}
.panel .placeholder{color:#98a2b3}

.footer-note{padding:10px 14px;color:var(--muted);font-size:13px;border-top:1px solid var(--line)}
</style>

<div class="container">
  <div class="card hdr">
    <h1>Főmenü</h1>
    <div style="display:flex;align-items:center;gap:8px;">
      <a class="btn" href="references.php" style="border:1px solid var(--line);padding:8px 12px;border-radius:10px;background:#fff;text-decoration:none;color:#111;">Hivatkozási segéd</a>
      <a class="btn" href="batches.php" style="border:1px solid var(--line);padding:8px 12px;border-radius:10px;background:#fff;text-decoration:none;color:#111;">Archívum</a>

      <?php if (!empty($_SESSION['username'])): ?>
        <span class="muted" style="font-size:13px;">Bejelentkezve: <strong><?= h($_SESSION['username']) ?></strong></span>
        <a class="btn" href="<?= h($authApps) ?>" style="border:1px solid var(--line);padding:8px 12px;border-radius:10px;background:#fff;text-decoration:none;color:#111;">Rendszerek</a>
        <a class="btn" href="/logout.php" style="border:1px solid var(--line);padding:8px 12px;border-radius:10px;background:#fff;text-decoration:none;color:#111;">Vissza</a>
        <a class="btn" href="<?= h($authLogout) ?>" style="border:1px solid var(--line);padding:8px 12px;border-radius:10px;background:#fff;text-decoration:none;color:#111;">Kilépés</a>
      <?php else: ?>
        <a class="btn" href="/login.php" style="border:1px solid var(--line);padding:8px 12px;border-radius:10px;background:#fff;text-decoration:none;color:#111;">Bejelentkezés</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card main">
    <div class="menu">
      <p class="hint">Válassz funkciót — a jobb oldalon rövid leírást látsz róla.</p>
      <div class="list" id="menuList">
        <a class="btn" href="partners.php" data-title="Partnerek" data-desc="Partner cégek kezelése: lista, új felvitel, szerkesztés, törlés. A kapcsolattartók felvitele a partner szerkesztőben történik.">
          👥 Partnerek <small>lista + szerk.</small>
        </a>
        <a class="btn" href="projects.php" data-title="Projektek" data-desc="Projektek kezelése: lista, létrehozás, szerkesztés, törlés. A mezők később bővíthetők.">
          🏗️ Projektek <small>lista + szerk.</small>
        </a>
        <a class="btn" href="templates.php" data-title="Sablonok" data-desc="WYSIWYG sablon szerkesztő. Placeholder-ek és képek beszúrása, A4 elrendezés, előnézet helyettesítéssel.">
          🧩 Sablonok <small>WYSIWYG</small>
        </a>
        <a class="btn" href="images.php" data-title="Képek" data-desc="Képfeltöltés és -kezelés: elnevezés (placeholder név), lista, megnyitás, törlés. A sablonokhoz beszúrható.">
          🖼️ Képek <small>feltöltés</small>
        </a>
        <a class="btn" href="generate.php" data-title="Generálás" data-desc="Válassz sablonokat és partnereket, jelölj ki egy projektet, majd generálj HTML/PDF-et, és küldj emailt partnerenként.">
          ⚙️ Generálás <small>HTML/PDF + email</small>
        </a>
        <a class="btn" href="batches.php" data-title="Archívum" data-desc="Korábbi generálások megtekintése: HTML/PDF linkek, státuszok, email-küldés utólag partnerenként vagy összesnek.">
          📁 Archívum <small>csomagok</small>
        </a>
        <a class="btn" href="references.php" data-title="Hivatkozási segéd" data-desc="Minden elérhető placeholder és hivatkozás: partner, projekt, kontakt indexek, képek kis bélyeggel, másolás gombbal.">
          🧭 Hivatkozási segéd <small>placeholder-ek</small>
        </a>
        <a class="btn" href="fonts.php" data-title="Betűkészletek" data-desc="Beágyazott fontok kezelése a PDF-hez (Arial, Times New Roman, stb.) — státusz és feltöltés.">
          🔤 Betűkészletek <small>PDF-hez</small>
        </a>
        <!-- Felhasználók kezelése az Auth Centerbe került -->
      </div>
      <div class="footer-note">Tipp: a menüt billentyűzettel is bejárhatod (Tab/Shift+Tab). Fókusszal is megjelenik a súgó.</div>
    </div>

    <div class="panel" id="infoPanel" aria-live="polite">
      <div class="placeholder">Vidd az egeret egy menüpontra, vagy tabbal fókuszáld ki — itt látod majd a rövid leírást.</div>
    </div>
  </div>
</div>

<script>
(function(){
  const list = document.getElementById('menuList');
  const panel = document.getElementById('infoPanel');

  function setInfo(title, desc){
    panel.innerHTML = '';
    const t = document.createElement('div');
    t.className = 'title';
    t.textContent = title || 'Funkció';
    const d = document.createElement('div');
    d.className = 'desc';
    d.textContent = desc || '';
    panel.appendChild(t);
    panel.appendChild(d);
  }

  list.querySelectorAll('.btn').forEach(btn=>{
    const title = btn.getAttribute('data-title') || btn.textContent.trim();
    const desc  = btn.getAttribute('data-desc') || '';

    btn.addEventListener('mouseenter', ()=> setInfo(title, desc));
    btn.addEventListener('focus', ()=> setInfo(title, desc));
    btn.addEventListener('touchstart', ()=> setInfo(title, desc), {passive:true});
  });
})();
</script>

</body>
</html>

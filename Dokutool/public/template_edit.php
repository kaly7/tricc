<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$err=null; $ok=null;
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM templates WHERE id=:id");
$stmt->execute([':id'=>$id]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$t) { echo '<div class="container"><div class="card err">A sablon nem található.</div></div>'; include __DIR__ . '/footer.php'; exit; }

// Load placeholder sources
$partner_fields = $pdo->query("SELECT name, label FROM partner_fields ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$project_fields = $pdo->query("SELECT name, label FROM project_fields ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$images = $pdo->query("SELECT `key`, title FROM images ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Preview selectors
$partners = $pdo->query("SELECT id, megnevezes FROM partners ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id, megnevezes FROM projects ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// JSON for client
$pf = []; foreach($partner_fields as $f){ $pf[] = ['placeholder'=>"{{ field.".$f['name']." }}", 'label'=>($f['label']?:$f['name'])]; }
$prf= []; foreach($project_fields as $f){ $prf[] = ['placeholder'=>"{{ project_field.".$f['name']." }}", 'label'=>($f['label']?:$f['name'])]; }
$img= []; foreach($images as $im){ $img[] = ['placeholder'=>"{{ image.".$im['key']." }}", 'label'=>($im['title']?:$im['key'])]; }

$jsPartnerCustom = json_encode($pf, JSON_UNESCAPED_UNICODE);
$jsProjectCustom = json_encode($prf, JSON_UNESCAPED_UNICODE);
$jsImages        = json_encode($img, JSON_UNESCAPED_UNICODE);
?>
<style>
.editor-wrap { background: var(--canvas); padding: 12px 0 24px; }
#editor { width: 210mm; min-height: 297mm; margin: 0 auto; background: #fff; border: 1px solid var(--border); padding: 20mm; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
#toolbar select, #toolbar input[type="color"], #toolbar input[type="number"] { height: 36px; }
#toolbar .group { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
#toolbar .sep { width:1px; height:28px; background:var(--border); }
.style-pills .pill{ padding:6px 10px; border:1px solid var(--border); border-radius:999px; cursor:pointer; user-select:none; }
.style-pills .pill:hover{ background:var(--panel); }
@media print { @page { size: A4 portrait; margin: 20mm; } body { background:#fff !important; } #editor { box-shadow:none; border:none; width:auto; min-height:auto; padding:0; margin:0; } }
</style>

<div class="container">

  <div class="card hdr">
    <div><strong>Sablon szerkesztése (A4 + extra tipográfia)</strong></div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="btn" href="templates.php">← Sablonok</a>
      <a class="btn" href="references.php" target="_blank">Hivatkozási segéd</a>
    </div>
  </div>

  <div class="card">
    <form id="tplform" method="post" action="template_save.php" onsubmit="return beforeSubmit();">
      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <div class="grid cols-2">
        <div class="input">
          <label>Név</label>
          <input name="name" value="<?= h($t['name']) ?>" required>
        </div>
        <div class="input">
          <label>Slug</label>
          <input value="<?= h($t['slug']) ?>" readonly>
        </div>
      </div>

      <div class="card" style="margin-top:12px;">
        <div id="toolbar" class="group">
          <button type="button" class="btn" onclick="cmd('bold')"><b>B</b></button>
          <button type="button" class="btn" onclick="cmd('italic')"><i>I</i></button>
          <button type="button" class="btn" onclick="cmd('underline')"><u>U</u></button>
          <span class="sep"></span>
          <button type="button" class="btn" onclick="formatBlock('H1')">H1</button>
          <button type="button" class="btn" onclick="formatBlock('H2')">H2</button>
          <button type="button" class="btn" onclick="formatBlock('H3')">H3</button>
          <span class="sep"></span>
          <button type="button" class="btn" onclick="cmd('insertUnorderedList')">• Lista</button>
          <button type="button" class="btn" onclick="cmd('insertOrderedList')">1. Lista</button>
          <span class="sep"></span>
          <button type="button" class="btn" onclick="cmd('justifyLeft')">Bal</button>
          <button type="button" class="btn" onclick="cmd('justifyCenter')">Közép</button>
          <button type="button" class="btn" onclick="cmd('justifyRight')">Jobb</button>
          <button type="button" class="btn" onclick="cmd('justifyFull')">Sorkizárt</button>
          <span class="sep"></span>
          <button type="button" class="btn" onclick="insertLink()">Link</button>
          <button type="button" class="btn" onclick="insertHtml('<hr>')">Választóvonal</button>
          <span class="sep"></span>

          <!-- Betűtípus -->
          <select id="fontSelect" onchange="setFont(this.value)">
            <option value="">Betűtípus</option>
            <option value="Arial">Arial</option>
            <option value="Times New Roman">Times New Roman</option>
            <option value="Courier New">Courier New</option>
            <option value="Georgia">Georgia</option>
            <option value="Tahoma">Tahoma</option>
            <option value="Verdana">Verdana</option>
          </select>

          <!-- Betűméret (px) -->
          <select id="fontSizeSelect" onchange="setFontSize(this.value)">
            <option value="">Betűméret (px)</option>
            <option value="10">10</option>
            <option value="11">11</option>
            <option value="12">12</option>
            <option value="14">14</option>
            <option value="16">16</option>
            <option value="18">18</option>
            <option value="24">24</option>
            <option value="28">28</option>
            <option value="32">32</option>
          </select>

          <!-- Színek -->
          <span class="sep"></span>
          <label class="muted">Szín</label>
          <input type="color" id="colorPicker" onchange="setColor(this.value)" title="Betűszín">
          <label class="muted">Kiemelés</label>
          <input type="color" id="bgPicker" onchange="setBgColor(this.value)" title="Háttérszín (kiemelés)">

          <!-- Sortáv / bekezdés térköz -->
          <span class="sep"></span>
          <label class="muted">Sortáv</label>
          <select id="lineHeight" onchange="setLineHeight(this.value)">
            <option value="">—</option>
            <option value="1">1.0</option>
            <option value="1.15">1.15</option>
            <option value="1.5">1.5</option>
            <option value="1.8">1.8</option>
          </select>

          <label class="muted">Térköz előtte (px)</label>
          <input type="number" id="mtop" min="0" step="1" style="width:90px" onblur="setParagraphSpacing()">
          <label class="muted">Térköz utána (px)</label>
          <input type="number" id="mbot" min="0" step="1" style="width:90px" onblur="setParagraphSpacing()">

          <!-- Extra tipográfia -->
          <span class="sep"></span>
          <button type="button" class="btn" onclick="setSmallCaps()">Kis kapitális</button>
          <button type="button" class="btn" onclick="setAllCaps()">NAGYBETŰS</button>
          <button type="button" class="btn" onclick="clearCaps()">Normál</button>
        </div>

        <!-- Stílus profilok -->
        <div class="style-pills" style="display:flex; gap:8px; flex-wrap:wrap; margin:8px 0;">
          <span class="pill" onclick="applyProfile('letter')">Levél fejléces</span>
          <span class="pill" onclick="applyProfile('offer')">Ajánlat</span>
          <span class="pill" onclick="applyProfile('contract')">Szerződés</span>
          <span class="pill" onclick="applyProfile('reset')">Alapértelmezett</span>
        </div>

        <div class="editor-wrap">
          <div id="editor" contenteditable="true">
            <?= $t['content_html'] ?>
          </div>
        </div>
        <textarea id="content_html" name="content_html" style="display:none;"></textarea>
        <small class="muted">A4 méret, 20&nbsp;mm margó. Képméret: pl. <code>{{ image.logo|w=60mm }}</code> vagy <code>{{ image.logo|w=300px|h=120px }}</code>.</small>
      </div>

      <div class="card" style="margin-top:12px;">
        <h3>Helykitöltők beszúrása</h3>
        <div class="grid cols-3">
          <div class="input">
            <label>Partner</label>
            <select onchange="ins(this)">
              <option value="">— válassz —</option>
              <option value="{{ partner.megnevezes }}">partner.megnevezes</option>
              <option value="{{ partner.cim_irsz }}">partner.cim_irsz</option>
              <option value="{{ partner.cim_telepules }}">partner.cim_telepules</option>
              <option value="{{ partner.cim_utca }}">partner.cim_utca</option>
              <option value="{{ partner.cim_hazszam }}">partner.cim_hazszam</option>
              <option value="{{ partner.cim_egyeb }}">partner.cim_egyeb</option>
            </select>
          </div>
          <div class="input">
            <label>Kapcsolattartó</label>
            <div style="display:flex; gap:6px;">
              <select id="contact_field">
                <option value="nev">contact.nev</option>
                <option value="beosztas">contact.beosztas</option>
                <option value="telefon">contact.telefon</option>
                <option value="email">contact.email</option>
              </select>
              <input type="number" id="contact_idx" min="1" placeholder="index" style="width:90px" title="Index (opcionális, pl. 1,2,3)">
              <button type="button" class="btn" onclick="insertContact()">Beilleszt</button>
            </div>
            <small class="muted">Index nélkül az elsődleges / első kerül beszúrásra.</small>
          </div>
          <div class="input">
            <label>Projekt</label>
            <select onchange="ins(this)">
              <option value="">— válassz —</option>
              <option value="{{ project.megnevezes }}">project.megnevezes</option>
              <option value="{{ project.szam }}">project.szam</option>
              <option value="{{ project.cim_irsz }}">project.cim_irsz</option>
              <option value="{{ project.cim_telepules }}">project.cim_telepules</option>
              <option value="{{ project.cim_utca }}">project.cim_utca</option>
              <option value="{{ project.cim_hazszam }}">project.cim_hazszam</option>
              <option value="{{ project.cim_egyeb }}">project.cim_egyeb</option>
              <option value="{{ project.gps_lat }}">project.gps_lat</option>
              <option value="{{ project.gps_lng }}">project.gps_lng</option>
              <option value="{{ project.kezdo_datum }}">project.kezdo_datum</option>
            </select>
          </div>

          <div class="input">
            <label>Partner egyedi mezők</label>
            <select id="partner_custom" onchange="ins(this)">
              <option value="">— válassz —</option>
            </select>
          </div>
          <div class="input">
            <label>Projekt egyedi mezők</label>
            <select id="project_custom" onchange="ins(this)">
              <option value="">— válassz —</option>
            </select>
          </div>
          <div class="input">
            <label>Képek</label>
            <select id="image_placeholders" onchange="ins(this)">
              <option value="">— válassz —</option>
            </select>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top:12px;">
        <h3>Előnézet adatokkal</h3>
        <div class="grid cols-3">
          <div class="input">
            <label>Partner (ha nincs kiválasztva, az első lesz)</label>
            <select id="prev_partner">
              <option value="0">— automatikus (első) —</option>
              <?php foreach($partners as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= h($p['megnevezes']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="input">
            <label>Projekt (ha nincs kiválasztva, az első lesz)</label>
            <select id="prev_project">
              <option value="0">— automatikus (első) —</option>
              <?php foreach($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= h($p['megnevezes']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="input">
            <label>Alap kép szélesség</label>
            <input id="prev_imgw" placeholder="pl. 40mm vagy 300px" value="40mm">
            <small class="muted">Felülírható helyben: pl. <code>{{ image.logo|w=60mm }}</code> vagy <code>|w=280px</code></small>
          </div>
        </div>
        <div style="text-align:right">
          <button class="btn" type="button" onclick="previewTpl()">Előnézet (kitöltve)</button>
        </div>
      </div>

      <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
        <button class="btn primary" type="submit">Mentés</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Mentés másként</h3>
    <form method="post" action="template_save_as.php" onsubmit="return beforeSubmitCopy();">
      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <div class="grid cols-2">
        <div class="input" style="flex:1;">
          <label>Új sablon neve</label>
          <input name="new_name" placeholder="pl. Ajánlat v2" required>
        </div>
        <div style="align-self:flex-end;">
          <button class="btn" type="submit">Mentés másként</button>
        </div>
      </div>
      <input type="hidden" name="content_html" id="content_html_copy">
    </form>
    <small class="muted">Az aktuális tartalmat új bejegyzésként menti le, új sluggal.</small>
  </div>
</div>

<script>
// Populate dynamic selects from PHP JSON
const PARTNER_CUSTOM = <?= $jsPartnerCustom ?>;
const PROJECT_CUSTOM = <?= $jsProjectCustom ?>;
const IMAGES        = <?= $jsImages ?>;
(function fillSelects(){
  const psel = document.getElementById('partner_custom');
  PARTNER_CUSTOM.forEach(x=>{
    const opt=document.createElement('option');
    opt.value = x.placeholder;
    opt.textContent = x.label + ' — ' + x.placeholder;
    psel.appendChild(opt);
  });
  const prsel = document.getElementById('project_custom');
  PROJECT_CUSTOM.forEach(x=>{
    const opt=document.createElement('option');
    opt.value = x.placeholder;
    opt.textContent = x.label + ' — ' + x.placeholder;
    prsel.appendChild(opt);
  });
  const isel = document.getElementById('image_placeholders');
  IMAGES.forEach(x=>{
    const opt=document.createElement('option');
    opt.value = x.placeholder;
    opt.textContent = (x.label||'') + ' — ' + x.placeholder;
    isel.appendChild(opt);
  });
})();

// --- szerkesztő segédek ---
try { document.execCommand('styleWithCSS', false, true); } catch(e) {}
function cmd(command){ document.execCommand(command, false, null); focusEditor(); }
function formatBlock(tag){ document.execCommand('formatBlock', false, tag); focusEditor(); }
function insertHtml(html){
  const sel = window.getSelection();
  if (!sel || !sel.rangeCount) focusEditor();
  document.execCommand('insertHTML', false, html);
  focusEditor();
}
function insertLink(){
  const url = prompt('Adja meg a hivatkozás URL-jét:');
  if (url) document.execCommand('createLink', false, url);
  focusEditor();
}
function ins(sel){
  const v = sel.value; if (!v) return;
  insertHtml(v);
  sel.value='';
}
function insertContact(){
  const f = document.getElementById('contact_field').value;
  const idx = document.getElementById('contact_idx').value.trim();
  const ph = idx ? `{{ contact.${f}.${idx} }}` : `{{ contact.${f} }}`;
  insertHtml(ph);
}
function beforeSubmit(){
  document.getElementById('content_html').value = document.getElementById('editor').innerHTML;
  return true;
}
function beforeSubmitCopy(){
  document.getElementById('content_html_copy').value = document.getElementById('editor').innerHTML;
  return true;
}
function previewTpl(){
  const pid = document.getElementById('prev_partner').value || '0';
  const prj = document.getElementById('prev_project').value || '0';
  const imgw= document.getElementById('prev_imgw').value || '';
  const url = `template_preview.php?id=<?= (int)$t['id'] ?>&partner_id=${encodeURIComponent(pid)}&project_id=${encodeURIComponent(prj)}&imgw=${encodeURIComponent(imgw)}`;
  window.open(url, 'tplprev', 'width=1000,height=800');
}

function focusEditor(){ document.getElementById('editor').focus(); }

// --- betűtípus / méret ---
function setFont(fontName){
  if (!fontName) return;
  document.execCommand("fontName", false, fontName);
  focusEditor();
}
function applyInlineStyle(prop, value){
  const sel = window.getSelection();
  if (!sel || sel.rangeCount === 0) return;
  const range = sel.getRangeAt(0);
  if (range.collapsed){
    const span = document.createElement('span');
    span.style[prop] = value;
    span.appendChild(document.createTextNode('\u200b'));
    range.insertNode(span);
    sel.removeAllRanges();
    const nr = document.createRange();
    nr.setStart(span.firstChild, 1);
    nr.collapse(true);
    sel.addRange(nr);
  } else {
    const wrapper = document.createElement('span');
    wrapper.style[prop] = value;
    try {
      wrapper.appendChild(range.extractContents());
      range.insertNode(wrapper);
      sel.removeAllRanges();
      const nr = document.createRange();
      nr.selectNodeContents(wrapper);
      nr.collapse(false);
      sel.addRange(nr);
    } catch(e){
      document.execCommand('styleWithCSS', false, true);
    }
  }
  focusEditor();
}
function setFontSize(px){
  if (!px) return;
  if (!/^\d+$/.test(px)) { alert('Add meg a betűméretet egész px-ben, pl. 12, 14, 16.'); return; }
  applyInlineStyle('fontSize', px + 'px');
}
function setColor(hex){
  if (!hex) return;
  applyInlineStyle('color', hex);
}
function setBgColor(hex){
  if (!hex) return;
  applyInlineStyle('backgroundColor', hex);
}

// --- sortáv és bekezdéstávolság ---
function closestBlock(node){
  while (node && node !== document && node !== document.body){
    const disp = window.getComputedStyle(node).display;
    if (/(block|table|list|grid|flex)/i.test(disp) || /^h[1-6]$/i.test(node.tagName)) return node;
    node = node.parentNode;
  }
  return document.getElementById('editor');
}
function setLineHeight(v){
  const sel = window.getSelection();
  if (!sel || sel.rangeCount===0) return;
  const block = closestBlock(sel.anchorNode);
  if (block) { block.style.lineHeight = v || null; }
  focusEditor();
}
function setParagraphSpacing(){
  const mt = document.getElementById('mtop').value;
  const mb = document.getElementById('mbot').value;
  const sel = window.getSelection();
  if (!sel || sel.rangeCount===0) return;
  const block = closestBlock(sel.anchorNode);
  if (block){
    block.style.marginTop = mt ? (parseInt(mt,10)||0)+'px' : null;
    block.style.marginBottom = mb ? (parseInt(mb,10)||0)+'px' : null;
  }
  focusEditor();
}

// --- extra tipográfia ---
function setSmallCaps(){ applyInlineStyle('fontVariant', 'small-caps'); }
function setAllCaps(){ applyInlineStyle('textTransform', 'uppercase'); }
function clearCaps(){
  applyInlineStyle('fontVariant', '');
  applyInlineStyle('textTransform', 'none');
}

// --- stílus profilok ---
function applyProfile(name){
  const ed = document.getElementById('editor');
  if (name==='reset'){
    ed.style.fontFamily = '';
    ed.style.fontSize = '';
    ed.style.lineHeight = '';
  }
  if (name==='letter'){
    ed.style.fontFamily = 'Georgia, "Times New Roman", serif';
    ed.style.fontSize = '12pt';
    ed.style.lineHeight = '1.5';
  }
  if (name==='offer'){
    ed.style.fontFamily = 'Arial, Helvetica, sans-serif';
    ed.style.fontSize = '11pt';
    ed.style.lineHeight = '1.4';
  }
  if (name==='contract'){
    ed.style.fontFamily = 'Tahoma, Verdana, sans-serif';
    ed.style.fontSize = '10.5pt';
    ed.style.lineHeight = '1.3';
  }
  focusEditor();
}
</script>

<?php include __DIR__ . '/footer.php'; ?>

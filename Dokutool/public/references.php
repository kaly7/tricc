<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Fetch dynamic data
$partner_fields = $pdo->query("SELECT id, name, label, type, required, active, sort_order FROM partner_fields ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$project_fields = $pdo->query("SELECT id, name, label, type, required, active, sort_order FROM project_fields ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$images = $pdo->query("SELECT id, `key`, title, stored_name, mime_type, width, height FROM images ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Filter settings
$all_sections = ['partner_base','contacts','partner_custom','project_base','project_custom','images'];
$show = isset($_GET['show']) ? (array)$_GET['show'] : $all_sections;
$show = array_values(array_intersect($show, $all_sections));
if (empty($show)) $show = $all_sections;
$q = trim((string)($_GET['s'] ?? ''));

function showsec($sec){ global $show; return in_array($sec, $show, true); }
function b($v){ return $v ? 'Igen' : 'Nem'; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function contains_q($haystack, $q){
  if ($q==='') return true;
  return mb_stripos($haystack, $q) !== false;
}

$qs = http_build_query(['show'=>$show, 's'=>$q]);
$export_url = 'references_export.php?' . $qs;
?>
<div class="container">

  <div class="card hdr">
    <div><strong>Hivatkozási segéd • sablon placeholder térkép</strong></div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="btn" href="index.php">← Főmenü</a>
      <a class="btn" href="<?=$export_url?>">CSV export</a>
    </div>
  </div>

  <div class="card">
    <form method="get" action="references.php" style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
      <div class="input"><label>Keresés</label>
        <input type="text" name="s" value="<?=h($q)?>" placeholder="pl. megnevezes, gps, logo, email">
      </div>
      <div class="input"><label>Mutatandó szekciók</label>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <?php
          $labels = [
            'partner_base'=>'Partner alap',
            'contacts'=>'Kapcsolattartók',
            'partner_custom'=>'Partner egyedi',
            'project_base'=>'Projekt alap',
            'project_custom'=>'Projekt egyedi',
            'images'=>'Képek',
          ];
          foreach($labels as $key=>$label):
            $checked = in_array($key, $show, true) ? 'checked' : '';
          ?>
          <label style="display:inline-flex; gap:6px; align-items:center;">
            <input type="checkbox" name="show[]" value="<?=$key?>" <?=$checked?>> <?=$label?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="align-self:flex-end;">
        <button class="btn" type="submit">Szűrés</button>
        <a class="btn ghost" href="references.php">Alapállapot</a>
      </div>
    </form>
  </div>

  <?php if (showsec('partner_base')): ?>
  <div class="card">
    <h3>Alap partner mezők</h3>
    <table class="table">
      <thead><tr><th>Placeholder</th><th>Tábla</th><th>Oszlop</th><th>Megjegyzés</th></tr></thead>
      <tbody>
        <?php
        $rows = [
          ['{{ partner.megnevezes }}','partners','megnevezes','Partner neve / megnevezése'],
          ['{{ partner.cim_irsz }}','partners','cim_irsz','Irányítószám'],
          ['{{ partner.cim_telepules }}','partners','cim_telepules','Település'],
          ['{{ partner.cim_utca }}','partners','cim_utca','Utca'],
          ['{{ partner.cim_hazszam }}','partners','cim_hazszam','Házszám'],
          ['{{ partner.cim_egyeb }}','partners','cim_egyeb','Egyéb cím kiegészítés'],
        ];
        $any=false;
        foreach($rows as $r){
          $joined = implode(' ', $r);
          if (!contains_q($joined, $q)) continue;
          $any=true;
          $ph = $r[0];
          echo '<tr><td><div style="display:flex;gap:6px;align-items:center;"><code>'.h($ph).'</code><button class="btn" type="button" onclick="copyToClipboard(\''.h($ph).'\', this)">Másolás</button></div></td><td>'.h($r[1]).'</td><td>'.h($r[2]).'</td><td>'.h($r[3]).'</td></tr>';
        }
        if(!$any) echo '<tr><td colspan="4"><em>Nincs találat.</em></td></tr>';
        ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (showsec('contacts')): ?>
  <div class="card">
    <h3>Kapcsolattartó (elsődleges + indexelt)</h3>
    <p style="margin-top:0;color:var(--muted);">
      Az <code>{{ contact.* }}</code> kulcsok az elsődleges kapcsolattartóra mutatnak (ha nincs, az elsőre).<br>
      Több kapcsolattartóhoz indexelés használható: <code>{{ contact.nev.1 }}</code>, <code>{{ contact.email.2 }}</code> stb.
    </p>
    <table class="table">
      <thead><tr><th>Placeholder</th><th>Tábla</th><th>Oszlop</th><th>Megjegyzés</th></tr></thead>
      <tbody>
        <?php
        $rows = [
          ['{{ contact.nev }}','partner_contacts','nev','Név (elsődleges)'],
          ['{{ contact.beosztas }}','partner_contacts','beosztas','Beosztás (elsődleges)'],
          ['{{ contact.telefon }}','partner_contacts','telefon','Telefon (elsődleges)'],
          ['{{ contact.email }}','partner_contacts','email','E-mail (elsődleges)'],
          ['{{ contact.nev.1 }}','partner_contacts','nev','1. kapcsolattartó neve'],
          ['{{ contact.nev.2 }}','partner_contacts','nev','2. kapcsolattartó neve'],
        ];
        $any=false;
        foreach($rows as $r){
          $joined = implode(' ', $r);
          if (!contains_q($joined, $q)) continue;
          $any=true;
          $ph = $r[0];
          echo '<tr><td><div style="display:flex;gap:6px;align-items:center;"><code>'.h($ph).'</code><button class="btn" type="button" onclick="copyToClipboard(\''.h($ph).'\', this)">Másolás</button></div></td><td>'.h($r[1]).'</td><td>'.h($r[2]).'</td><td>'.h($r[3]).'</td></tr>';
        }
        if(!$any) echo '<tr><td colspan="4"><em>Nincs találat.</em></td></tr>';
        ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (showsec('partner_custom')): ?>
  <div class="card">
    <h3>Egyedi partner mezők</h3>
    <p style="margin-top:0;color:var(--muted);">Sablonban: <code>{{ field.&lt;azonosito&gt; }}</code></p>
    <table class="table">
      <thead><tr><th>Placeholder</th><th>Azonosító</th><th>Címke</th><th>Típus</th><th>Köt.</th><th>Aktív</th><th>Sorrend</th></tr></thead>
      <tbody>
        <?php
        $any=false;
        foreach($partner_fields as $f){
          $joined = $f['name'].' '.$f['label'].' '.$f['type'];
          if (!contains_q($joined, $q)) continue;
          $any=true;
          $ph = '{{ field.'.$f['name'].' }}';
          echo '<tr>';
          echo '<td><div style="display:flex;gap:6px;align-items:center;"><code>'.h($ph).'</code><button class="btn" type="button" onclick="copyToClipboard(\''.h($ph).'\', this)">Másolás</button></div></td>';
          echo '<td>'.h($f['name']).'</td>';
          echo '<td>'.h($f['label']).'</td>';
          echo '<td>'.h($f['type']).'</td>';
          echo '<td>'.($f['required']?'Igen':'Nem').'</td>';
          echo '<td>'.($f['active']?'Igen':'Nem').'</td>';
          echo '<td>'.(int)$f['sort_order'].'</td>';
          echo '</tr>';
        }
        if(!$any) echo '<tr><td colspan="7"><em>Nincs találat.</em></td></tr>';
        ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (showsec('project_base')): ?>
  <div class="card">
    <h3>Alap projekt mezők</h3>
    <table class="table">
      <thead><tr><th>Placeholder</th><th>Tábla</th><th>Oszlop</th><th>Megjegyzés</th></tr></thead>
      <tbody>
        <?php
        $rows = [
          ['{{ project.megnevezes }}','projects','megnevezes','Projekt neve'],
          ['{{ project.szam }}','projects','szam','Projekt száma'],
          ['{{ project.cim_irsz }}','projects','cim_irsz','Irányítószám'],
          ['{{ project.cim_telepules }}','projects','cim_telepules','Település'],
          ['{{ project.cim_utca }}','projects','cim_utca','Utca'],
          ['{{ project.cim_hazszam }}','projects','cim_hazszam','Házszám'],
          ['{{ project.cim_egyeb }}','projects','cim_egyeb','Egyéb cím kiegészítés'],
          ['{{ project.gps_lat }}','projects','gps_lat','GPS szélesség'],
          ['{{ project.gps_lng }}','projects','gps_lng','GPS hosszúság'],
          ['{{ project.kezdo_datum }}','projects','kezdo_datum','Kezdő dátum'],
        ];
        $any=false;
        foreach($rows as $r){
          $joined = implode(' ', $r);
          if (!contains_q($joined, $q)) continue;
          $any=true;
          $ph = $r[0];
          echo '<tr><td><div style="display:flex;gap:6px;align-items:center;"><code>'.h($ph).'</code><button class="btn" type="button" onclick="copyToClipboard(\''.h($ph).'\', this)">Másolás</button></div></td><td>'.h($r[1]).'</td><td>'.h($r[2]).'</td><td>'.h($r[3]).'</td></tr>';
        }
        if(!$any) echo '<tr><td colspan="4"><em>Nincs találat.</em></td></tr>';
        ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (showsec('project_custom')): ?>
  <div class="card">
    <h3>Egyedi projekt mezők</h3>
    <p style="margin-top:0;color:var(--muted);">Sablonban: <code>{{ project_field.&lt;azonosito&gt; }}</code></p>
    <table class="table">
      <thead><tr><th>Placeholder</th><th>Azonosító</th><th>Címke</th><th>Típus</th><th>Köt.</th><th>Aktív</th><th>Sorrend</th></tr></thead>
      <tbody>
        <?php
        $any=false;
        foreach($project_fields as $f){
          $joined = $f['name'].' '.$f['label'].' '.$f['type'];
          if (!contains_q($joined, $q)) continue;
          $any=true;
          $ph = '{{ project_field.'.$f['name'].' }}';
          echo '<tr>';
          echo '<td><div style="display:flex;gap:6px;align-items:center;"><code>'.h($ph).'</code><button class="btn" type="button" onclick="copyToClipboard(\''.h($ph).'\', this)">Másolás</button></div></td>';
          echo '<td>'.h($f['name']).'</td>';
          echo '<td>'.h($f['label']).'</td>';
          echo '<td>'.h($f['type']).'</td>';
          echo '<td>'.($f['required']?'Igen':'Nem').'</td>';
          echo '<td>'.($f['active']?'Igen':'Nem').'</td>';
          echo '<td>'.(int)$f['sort_order'].'</td>';
          echo '</tr>';
        }
        if(!$any) echo '<tr><td colspan="7"><em>Nincs találat.</em></td></tr>';
        ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (showsec('images')): ?>
  <div class="card">
    <h3>Képek (bélyegkép + placeholder)</h3>
    <p style="margin-top:0;color:var(--muted);">Sablonban: <code>{{ image.&lt;kulcs&gt; }}</code> — pl. <code>{{ image.logo }}</code>, <code>{{ image.pecset }}</code></p>
    <table class="table">
      <thead><tr><th>Bélyegkép</th><th>Placeholder</th><th>Kulcs</th><th>Cím</th><th>MIME</th><th>Méret</th></tr></thead>
      <tbody>
        <?php
        $any=false;
        foreach($images as $im){
          $joined = ($im['key']??'').' '.($im['title']??'').' '.($im['mime_type']??'');
          if (!contains_q($joined, $q)) continue;
          $any=true;
          $src = 'uploads/' . h($im['stored_name']);
          $ph  = '{{ image.' . $im['key'] . ' }}';
          $wh  = (int)$im['width'] . '×' . (int)$im['height'];
          echo '<tr>';
          echo '<td>';
          if (preg_match('/^image\//', (string)$im['mime_type'])){
            echo '<img src="'.h($src).'" alt="" style="max-height:48px;max-width:120px;border:1px solid var(--border);border-radius:6px;padding:2px;background:var(--panel)">';
          }
          echo '</td>';
          echo '<td><div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;"><code style="padding:2px 6px; border:1px solid var(--border); border-radius:6px; display:inline-block;">'.h($ph).'</code> <button class="btn" type="button" onclick="copyToClipboard(\''.h($ph).'\', this)">Másolás</button></div></td>';
          echo '<td><span class="badge">'.h($im['key']).'</span></td>';
          echo '<td>'.h($im['title'] ?? '').'</td>';
          echo '<td>'.h($im['mime_type'] ?? '').'</td>';
          echo '<td>'.$wh.'</td>';
          echo '</tr>';
        }
        if(!$any) echo '<tr><td colspan="6"><em>Nincs találat.</em></td></tr>';
        ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
<?php echo <<<'HTML'

<script>
function ensureToast(){
  var t=document.getElementById('toast');
  if(!t){
    t=document.createElement('div');
    t.id='toast';
    t.style.position='fixed';
    t.style.right='16px';
    t.style.bottom='16px';
    t.style.padding='10px 14px';
    t.style.border='1px solid var(--border)';
    t.style.borderRadius='10px';
    t.style.background='#15311d';
    t.style.color='#d6ffe6';
    t.style.zIndex='9999';
    t.style.boxShadow='var(--shadow)';
    t.style.opacity='0';
    t.style.transition='opacity .15s ease';
    document.body.appendChild(t);
  }
  return t;
}
function legacyCopy(text){
  var ta=document.createElement('textarea');
  ta.value=text;
  ta.setAttribute('readonly','');
  ta.style.position='fixed';
  ta.style.top='-9999px';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); } catch(e) {}
  document.body.removeChild(ta);
}
async function copyToClipboard(text, btn){
  try{
    if (window.navigator && navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(text);
    } else {
      legacyCopy(text);
    }
    var t=ensureToast();
    t.textContent='✔ Kimásolva';
    t.style.opacity='1';
    setTimeout(function(){ t.style.opacity='0'; }, 900);
    if(btn){
      var original = btn.innerHTML;
      btn.innerHTML='✔';
      setTimeout(function(){ btn.innerHTML=original; }, 900);
    }
  }catch(e){
    alert('Másolás nem sikerült: ' + e);
  }
}
</script>

HTML; ?>
<?php include __DIR__ . '/footer.php'; ?>

<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

// Hiba kijelzés fejlesztéshez
ini_set('display_errors', 1);
error_reporting(E_ALL);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM images WHERE id=:id");
$stmt->execute([':id'=>$id]);
$img = $stmt->fetch();
$placeholder = $img ? '{{ image.' . $img['key'] . ' }}' : '';
?>
<div class="container">
  <div class="hdr card">
    <div><strong>Kép megtekintése</strong></div>
    <div>
      <a class="btn" href="images.php">← Vissza a képekhez</a>
    </div>
  </div>

  <?php if(!$img): ?>
    <div class="card err">A kép nem található.</div>
  <?php else: ?>
    <div class="card">
      <div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
        <div style="flex:1 1 400px;">
          <div style="border:1px solid var(--border); border-radius:12px; padding:12px; text-align:center; background:var(--panel);">
            <img src="uploads/<?php echo htmlspecialchars($img['stored_name']); ?>" alt="<?php echo htmlspecialchars($img['alt_text'] ?? ''); ?>" style="max-width:100%; height:auto;">
          </div>
        </div>
        <div style="flex:1 1 260px;">
          <div class="input"><label>Placeholder</label>
            <div style="display:flex; gap:6px; align-items:center;">
              <input id="ph" readonly value="<?php echo htmlspecialchars($placeholder); ?>">
              <button class="btn" type="button" onclick="copyToClipboard(document.getElementById('ph').value, this)">Másolás</button>
            </div>
          </div>
          <div class="input"><label>Kulcs</label><input readonly value="<?php echo htmlspecialchars($img['key']); ?>"></div>
          <div class="input"><label>Cím</label><input readonly value="<?php echo htmlspecialchars($img['title'] ?? ''); ?>"></div>
          <div class="input"><label>Alt</label><input readonly value="<?php echo htmlspecialchars($img['alt_text'] ?? ''); ?>"></div>
          <div class="input"><label>Fájlnév</label><input readonly value="<?php echo htmlspecialchars($img['original_name']); ?>"></div>
          <div class="input"><label>MIME</label><input readonly value="<?php echo htmlspecialchars($img['mime_type']); ?>"></div>
          <div class="input"><label>Méret</label><input readonly value="<?php echo number_format((int)$img['file_size']/1024, 0); ?> KB"></div>
          <div class="input"><label>Szélesség × Magasság</label><input readonly value="<?php echo (int)$img['width']; ?> × <?php echo (int)$img['height']; ?>"></div>
          <div class="input"><label>Címkék</label><input readonly value="<?php echo htmlspecialchars($img['tags'] ?? ''); ?>"></div>
          <div style="margin-top:12px;">
            <a class="btn" href="image_edit.php?id=<?php echo (int)$img['id']; ?>">Szerkeszt</a>
            <a class="btn ghost" href="uploads/<?php echo htmlspecialchars($img['stored_name']); ?>" target="_blank">Megnyitás fájlként</a>
            <a class="btn" href="images.php">Képlista</a>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php echo $commonJs ?? ''; ?>
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
  // Fallback for HTTP / old browsers
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

HTML;
include __DIR__ . '/footer.php'; ?>

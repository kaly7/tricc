<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

// Hiba kijelzés fejlesztéshez
ini_set('display_errors', 1);
error_reporting(E_ALL);

$err = null; $ok = null;
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM images WHERE id=:id");
$stmt->execute([':id'=>$id]);
$img = $stmt->fetch();
if (!$img) {
  echo '<div class="container"><div class="card err">A kép nem található.</div></div>';
  include __DIR__ . '/footer.php'; exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'save') {
  try {
    $title = trim($_POST['title'] ?? '') ?: null;
    $alt   = trim($_POST['alt_text'] ?? '') ?: null;
    $tags  = trim($_POST['tags'] ?? '') ?: null;
    $upd = $pdo->prepare("UPDATE images SET title=:t, alt_text=:a, tags=:g WHERE id=:id");
    $upd->execute([':t'=>$title, ':a'=>$alt, ':g'=>$tags, ':id'=>$id]);
    $ok = 'Mentve.';
    // Friss adat
    $stmt = $pdo->prepare("SELECT * FROM images WHERE id=:id");
    $stmt->execute([':id'=>$id]);
    $img = $stmt->fetch();
  } catch (Throwable $e) { $err = 'Mentési hiba: '.$e->getMessage(); }
}

$placeholder = '{{ image.' . $img['key'] . ' }}';
?>
<div class="container">
  <div class="hdr card">
    <div><strong>Kép szerkesztése</strong></div>
    <div style="display:flex; gap:8px;">
      <a class="btn" href="image_view.php?id=<?php echo (int)$img['id']; ?>">Megtekintés</a>
      <a class="btn" href="images.php">← Képek</a>
    </div>
  </div>

  <?php if($err): ?><div class="err"><?php echo $err; ?></div><?php endif; ?>
  <?php if($ok): ?><div class="ok"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

  <div class="card" style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
    <div style="flex:1 1 400px;">
      <div style="border:1px solid var(--border); border-radius:12px; padding:12px; text-align:center; background:var(--panel);">
        <img src="uploads/<?php echo htmlspecialchars($img['stored_name']); ?>" alt="<?php echo htmlspecialchars($img['alt_text'] ?? ''); ?>" style="max-width:100%; height:auto;">
      </div>
    </div>
    <div style="flex:1 1 320px;">
      <form method="post">
        <input type="hidden" name="action" value="save">
        <div class="input"><label>Placeholder</label>
          <div style="display:flex; gap:6px; align-items:center;">
            <input id="ph" readonly value="<?php echo htmlspecialchars($placeholder); ?>">
            <button class="btn" type="button" onclick="copyToClipboard(document.getElementById('ph').value, this)">Másolás</button>
          </div>
        </div>
        <div class="input"><label>Kulcs</label><input readonly value="<?php echo htmlspecialchars($img['key']); ?>"></div>
        <div class="input"><label>Cím</label><input name="title" value="<?php echo htmlspecialchars($img['title'] ?? ''); ?>"></div>
        <div class="input"><label>Alt szöveg</label><input name="alt_text" value="<?php echo htmlspecialchars($img['alt_text'] ?? ''); ?>"></div>
        <div class="input"><label>Címkék (vesszővel)</label><input name="tags" value="<?php echo htmlspecialchars($img['tags'] ?? ''); ?>"></div>
        <div style="text-align:right; margin-top:8px;">
          <button class="btn primary" type="submit">Mentés</button>
        </div>
      </form>
    </div>
  </div>
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

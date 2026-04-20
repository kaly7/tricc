<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$err = null; $ok = null;

// Delete
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
  try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      $stmt = $pdo->prepare("SELECT stored_name FROM images WHERE id=:id");
      $stmt->execute([':id'=>$id]);
      if ($row = $stmt->fetch()) {
        $file = __DIR__ . '/uploads/' . $row['stored_name'];
        if (is_file($file)) @unlink($file);
      }
      $pdo->prepare("DELETE FROM images WHERE id=:id")->execute([':id'=>$id]);
      $ok = "Kép törölve.";
    }
  } catch (Throwable $e) { $err = "Törlési hiba: ".$e->getMessage(); }
}

// List & search
$q = trim($_GET['q'] ?? '');
$params = [];
$sql = "SELECT * FROM images";
if ($q !== '') { $sql .= " WHERE `key` LIKE :q OR title LIKE :q OR tags LIKE :q OR original_name LIKE :q"; $params[':q'] = '%'.$q.'%'; }
$sql .= " ORDER BY id DESC";

$rows = [];
try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
} catch (Throwable $e) { $err = "Listázási hiba: " . $e->getMessage() . " — Futtattad a schema_images.sql-t?"; }
?>
<div class="container">
  <div class="card hdr">
    <form method="get" action="images.php" style="display:flex; gap:8px;">
      <div class="input">
        <label>Keresés</label>
        <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Kulcs, cím, tag, fájlnév...">
      </div>
      <div style="align-self:flex-end;">
        <button class="btn" type="submit">Keres</button>
        <a class="btn ghost" href="images.php">Minden</a>
      </div>
    </form>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="btn" href="index.php">← Főmenü</a>
      <a class="btn primary" href="image_upload.php">+ Új kép feltöltése</a>
    </div>
  </div>

  <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>
  <?php if($ok): ?><div class="ok"><?=htmlspecialchars($ok)?></div><?php endif; ?>

  <div class="card">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Bélyegkép</th>
          <th>Placeholder</th>
          <th>Cím</th>
          <th>Fájlnév</th>
          <th>MIME</th>
          <th>Méret</th>
          <th>WH</th>
          <th>Címkék</th>
          <th style="width:280px;">Művelet</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($rows)): foreach($rows as $r): $src = 'uploads/'.htmlspecialchars($r['stored_name']); $ph='{{ image.'.htmlspecialchars($r['key']).' }}'; ?>
        <tr>
          <td><?=$r['id']?></td>
          <td><?php if (preg_match('/^image\\//', $r['mime_type'])): ?>
            <a href="image_view.php?id=<?=$r['id']?>">
              <img src="<?=$src?>" alt="" style="max-height:48px; max-width:120px; border:1px solid var(--border); border-radius:6px; padding:2px; background:var(--panel)">
            </a>
          <?php endif; ?></td>
          <td>
            <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
              <code style="padding:2px 6px; border:1px solid var(--border); border-radius:6px; display:inline-block;"><?=htmlspecialchars($ph)?></code>
              <button class="btn" type="button" onclick="copyToClipboard('<?=htmlspecialchars($ph, ENT_QUOTES)?>', this)">Másolás</button>
            </div>
            <div class="badge" style="margin-top:4px;"><?=htmlspecialchars($r['key'])?></div>
          </td>
          <td><?=htmlspecialchars($r['title'] ?? '')?></td>
          <td><?=htmlspecialchars($r['original_name'])?></td>
          <td><?=htmlspecialchars($r['mime_type'])?></td>
          <td><?=number_format((int)$r['file_size']/1024, 0)?> KB</td>
          <td><?=(int)$r['width']?>×<?=(int)$r['height']?></td>
          <td><?=htmlspecialchars($r['tags'] ?? '')?></td>
          <td>
            <div class="toolbar" style="display:flex; gap:6px; flex-wrap:wrap;">
              <a class="btn" href="image_view.php?id=<?=$r['id']?>">Megnyit</a>
              <a class="btn" href="image_edit.php?id=<?=$r['id']?>">Szerkeszt</a>
              <a class="btn ghost" href="<?=$src?>" target="_blank">Fájl</a>
              <form method="post" onsubmit="return confirm('Biztosan törlöd?');" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=$r['id']?>">
                <button class="btn ghost" type="submit">Törlés</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="10"><em>Még nincs kép feltöltve.</em></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
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

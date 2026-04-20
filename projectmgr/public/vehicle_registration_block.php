<?php
// Feltételezzük, hogy a vehicle.php-ban ezek már léteznek:
// $pdo  (PDO kapcsolat)
// $v    (aktuális jármű rekord tömb)
// $isAdmin (bool) vagy $u['role_id']==1 alapján

if (!isset($v) || !is_array($v) || empty($v['id'])) return;

$vid = (int)$v['id'];
$admin = isset($isAdmin) ? (bool)$isAdmin : (isset($u['role_id']) && (int)$u['role_id']===1);

$regDocs = [];
try {
  $st = $pdo->prepare("SELECT id, orig_name, mime, size, created_at
                       FROM vehicle_documents
                       WHERE vehicle_id=? AND doc_type='registration'
                       ORDER BY id DESC");
  $st->execute([$vid]);
  $regDocs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $regDocs = [];
}

// Alapból rejtve: ha nincs admin és nincs doc, ne jelenjen meg semmi
if (!$admin && count($regDocs)===0) return;
?>

<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Forgalmi engedély</strong>
    <?php if ($admin): ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="pmToggleReg()">Megnyit</button>
    <?php endif; ?>
  </div>

  <div id="pmRegBox" style="display:none">
    <div class="card-body">
      <?php if ($admin): ?>
      <form method="post" action="/vehicle_doc_upload.php" enctype="multipart/form-data" class="mb-3">
        <input type="hidden" name="vehicle_id" value="<?= $vid ?>">
        <input type="file" name="files[]" multiple accept=".jpg,.jpeg,.png,.pdf" class="form-control mb-2">
        <button class="btn btn-primary btn-sm">Feltöltés</button>
      </form>
      <?php endif; ?>

      <?php if (count($regDocs)===0): ?>
        <div class="text-muted">Még nincs feltöltve forgalmi engedély.</div>
      <?php else: ?>
        <div class="list-group">
          <?php foreach ($regDocs as $d): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center">
              <div class="me-3">
                <div class="fw-semibold"><?= htmlspecialchars($d['orig_name'] ?? '') ?></div>
                <div class="small text-muted"><?= htmlspecialchars($d['created_at'] ?? '') ?></div>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary" target="_blank"
                   href="/vehicle_doc_viewer.php?vehicle_id=<?= $vid ?>&doc_id=<?= (int)$d['id'] ?>">Megnyitás</a>

<!-- a class="btn btn-sm btn-outline-primary"
   href="/vehicle_doc_view.php?vehicle_id=<?= $vid ?>&doc_id=<?= (int)$d['id'] ?>"
   onclick="return pmOpenRegDoc(<?= (int)$d['id'] ?>, <?= json_encode((string)($d['mime'] ?? '')) ?>, <?= json_encode((string)($d['orig_name'] ?? '')) ?>);">
   MegnyitásX
</a -->


                <?php if ($admin): ?>
                <form method="post" action="/vehicle_doc_delete.php" onsubmit="return confirm('Biztos törlöd?');">
                  <input type="hidden" name="vehicle_id" value="<?= $vid ?>">
                  <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Törlés</button>
                </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
function pmToggleReg(){
  var el = document.getElementById('pmRegBox');
  if(!el) return;
  el.style.display = (el.style.display==='none' || el.style.display==='') ? 'block' : 'none';
}
</script>


<style>
#pmRegModalOverlay{
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.65);
  display: none;
  z-index: 99999;
  padding: 12px;
}

#pmRegModal{
  background: #fff;
  width: min(1100px, 100%);
  height: min(85vh, 100%);
  margin: 0 auto;
  border-radius: 10px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 10px 40px rgba(0,0,0,.35);
}

#pmRegModalHeader{
  padding: 10px 12px;
  display:flex;
  align-items:center;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid #e5e5e5;
  background: #f8f9fa;
}

#pmRegModalTitle{
  font-weight: 700;
}

#pmRegModalBody{
  flex: 1;
  overflow: auto;
  background: #111; /* kép/PDF körül sötétebb */
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 10px;
}

#pmRegModalBody iframe{
  width: 100%;
  height: 100%;
  border: 0;
  background: #fff;
  border-radius: 6px;
}

#pmRegModalBody img{
  max-width: 100%;
  max-height: 100%;
  width: auto;
  height: auto;
  object-fit: contain;
  background: #fff;
  border-radius: 6px;
}
</style>

<div id="pmRegModalOverlay">
  <div id="pmRegModal" role="dialog" aria-modal="true">
    <div id="pmRegModalHeader">
      <div>
        <div id="pmRegModalTitle">Dokumentum</div>
        <div class="small text-muted" id="pmRegModalSub"></div>
      </div>

<button type="button" class="btn btn-sm btn-light" onclick="pmCloseRegModal()">Bezár</button>
    </div>
    <div id="pmRegModalBody"></div>
  </div>
</div>

<script>
(function(){
  function qs(id){ return document.getElementById(id); }

  function openModal(){
    var ov = qs('pmRegModalOverlay');
    if(!ov) return;
    ov.style.display = 'block';
    document.addEventListener('keydown', onEsc, true);
  }

  function closeModal(){
    var ov = qs('pmRegModalOverlay');
    var body = qs('pmRegModalBody');
    if(ov) ov.style.display = 'none';
    if(body) body.innerHTML = '';
    document.removeEventListener('keydown', onEsc, true);
  }

  function onEsc(e){
    if(e.key === 'Escape') closeModal();
  }

  // Kattintás a háttérre zár
  document.addEventListener('click', function(e){
    var ov = qs('pmRegModalOverlay');
    if(!ov || ov.style.display !== 'block') return;
    if(e.target === ov) closeModal();
  }, true);

  // Bezár gomb
  document.addEventListener('click', function(e){
    if(e.target && e.target.id === 'pmRegCloseBtn'){
      e.preventDefault();
      closeModal();
    }
  }, true);

  // Globális függvény a link onclick-hoz
window.pmOpenRegDoc = function(docId, mime, name){
  try{
    var body = qs('pmRegModalBody');
    var title = qs('pmRegModalTitle');
    var sub = qs('pmRegModalSub');

    // SOHA ne engedjük a sima navigációt
    if(!body || !title || !sub){
      console.error('Modal elemek hiányoznak:', {body:!!body, title:!!title, sub:!!sub});
      // ne navigáljon el
      return false;
    }

    title.textContent = name || 'Dokumentum';
    sub.textContent = mime || '';

    body.innerHTML = '';

    var url = '/vehicle_doc_view.php?vehicle_id=<?= (int)$vid ?>&doc_id=' + encodeURIComponent(docId);

    if ((mime || '').toLowerCase().indexOf('pdf') !== -1){
      var iframe = document.createElement('iframe');
      iframe.src = url;
      body.appendChild(iframe);
    } else {
      var img = document.createElement('img');
      img.src = url;
      img.alt = name || 'Dokumentum';
      body.appendChild(img);
    }

    openModal();

    // ne nyissa meg a linket
    return false;
  } catch(err){
    console.error('pmOpenRegDoc hiba:', err);
    // ne nyissa meg a linket még hiba esetén se
    return false;
  }
};
  // Exponáljuk a bezárót is (ha bárhol kell)
  window.pmCloseRegModal = closeModal;
})();
</script>

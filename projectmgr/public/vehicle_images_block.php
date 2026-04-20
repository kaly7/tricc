<?php
if (!isset($v) || !is_array($v) || empty($v['id'])) return;

$vid = (int)$v['id'];
$admin = isset($isAdmin) ? (bool)$isAdmin : (isset($u['role_id']) && (int)$u['role_id']===1);

$imgs = [];
try {
  $st = $pdo->prepare("SELECT id, orig_name, mime, size, created_at
                       FROM vehicle_images
                       WHERE vehicle_id=?
                       ORDER BY id DESC");
  $st->execute([$vid]);
  $imgs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $imgs = [];
}

// Ha nincs admin és nincs kép, ne mutassuk
if (!$admin && count($imgs)===0) return;
?>

<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Jármű fotók</strong>
    <?php if ($admin): ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="pmToggleVehicleImgs()">Megnyit</button>
    <?php endif; ?>
  </div>

  <div id="pmVehicleImgsBox" style="display:none">
    <div class="card-body">

      <?php if ($admin): ?>
      <form method="post" action="/vehicle_image_upload.php" enctype="multipart/form-data" class="mb-3">
        <input type="hidden" name="vehicle_id" value="<?= $vid ?>">
        <input type="file" name="files[]" multiple accept=".jpg,.jpeg,.png,.webp" class="form-control mb-2">
        <button class="btn btn-primary btn-sm">Feltöltés</button>
      </form>
      <?php endif; ?>

      <?php if (count($imgs)===0): ?>
        <div class="text-muted">Még nincs feltöltve járműfotó.</div>
      <?php else: ?>
        <div class="row g-2">
          <?php foreach ($imgs as $im): ?>
            <div class="col-6 col-md-4 col-lg-3">
              <div class="border rounded p-2 h-100 bg-light">
                <div class="small text-muted mb-1" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  <?= htmlspecialchars($im['orig_name'] ?? '') ?>
                </div>
<div class="small text-muted mb-2">
  Feltöltve: <?= htmlspecialchars($im['created_at'] ?? '') ?>
</div>
                <a class="d-block" href="/vehicle_image_viewer.php?vehicle_id=<?= $vid ?>&img_id=<?= (int)$im['id'] ?>">
                  <img
                    src="/vehicle_image_thumb.php?vehicle_id=<?= $vid ?>&img_id=<?= (int)$im['id'] ?>"
                    alt="<?= htmlspecialchars($im['orig_name'] ?? '') ?>"
                    style="width:100%; height:160px; object-fit:cover; border-radius:6px; background:#fff;"
                    loading="lazy">
                </a>

                <div class="d-flex justify-content-between align-items-center mt-2">
                  <a class="btn btn-sm btn-outline-primary"
                     href="/vehicle_image_viewer.php?vehicle_id=<?= $vid ?>&img_id=<?= (int)$im['id'] ?>">
                     Megnyitás
                  </a>

                  <?php if ($admin): ?>
                    <form method="post" action="/vehicle_image_delete.php" onsubmit="return confirm('Biztos törlöd a képet?');">
                      <input type="hidden" name="vehicle_id" value="<?= $vid ?>">
                      <input type="hidden" name="img_id" value="<?= (int)$im['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">Törlés</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
function pmToggleVehicleImgs(){
  var el = document.getElementById('pmVehicleImgsBox');
  if(!el) return;
  el.style.display = (el.style.display==='none' || el.style.display==='') ? 'block' : 'none';
}
</script>
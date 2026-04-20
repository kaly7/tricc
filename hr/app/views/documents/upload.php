<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Dokumentum feltöltés</h3>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/documents<?= $employee_id ? ('?employee_id='.(int)$employee_id) : '' ?>">Vissza</a>
  </div>
</div>

<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="/documents_upload" class="card" enctype="multipart/form-data">
  <div class="card-body">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="row g-3">

      <div class="col-md-6">
        <label class="form-label">Dolgozó *</label>
        <select class="form-select" name="employee_id" required>
          <option value="0">— válassz —</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= ((int)$employee_id === (int)$e['id']) ? 'selected' : '' ?>>
              <?= h($e['full_name']) ?><?= ((int)$e['is_active']===1)?'':' (inaktív)' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Gyors kiválasztás (max 500 elem).</div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Dokumentumtípus *</label>
        <select class="form-select" name="document_type_id" required>
          <option value="0">— válassz —</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">A listát admin kezeli: „Dokumentumtípusok”.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Dokumentum megnevezése (opcionális)</label>
        <input class="form-control" type="text" name="title" placeholder="pl. CE tanúsítvány 2026">
      </div>

      <div class="col-md-6">
        <label class="form-label">Fájl *</label>
        <input class="form-control" type="file" name="file" required>
        <div class="form-text">Max 25MB. A listában nem készül előnézeti kép.</div>
      </div>

      <div class="col-md-4">
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" value="1" id="has_expiry" name="has_expiry">
          <label class="form-check-label" for="has_expiry">Lejárathoz kötött</label>
        </div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Lejárat dátum</label>
        <input class="form-control" type="date" name="expires_at" id="expires_at" disabled>
        <div class="form-text">Csak akkor aktív, ha a checkbox be van jelölve.</div>
      </div>

    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Feltöltés</button>
      <a class="btn btn-outline-secondary" href="/documents<?= $employee_id ? ('?employee_id='.(int)$employee_id) : '' ?>">Mégse</a>
    </div>
  </div>
</form>

<script>
(function(){
  var cb = document.getElementById('has_expiry');
  var dt = document.getElementById('expires_at');
  function sync(){ dt.disabled = !cb.checked; if(!cb.checked) dt.value=''; }
  cb.addEventListener('change', sync);
  sync();
})();
</script>

<?php
// $authUser       = {id, username, full_name}
// $permId         = int|null
// $divisions      = [{id, name}, ...]
// $extraFields    = [{id, name}, ...]
// $staticFields   = ['Szekció' => ['key' => 'Label', ...], ...]
// $selDivisions   = [int, ...]
// $selFields      = [str, ...]
// $selExtraFields = [int, ...]
// $csrf           = string
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">HR jogosultság: <strong><?= h($authUser['full_name']) ?></strong></h4>
  <div class="d-flex gap-2">
    <a href="/hr_permissions" class="btn btn-sm btn-outline-secondary">← Vissza</a>
    <a href="/hr_audit_log?user_id=<?= (int)$authUser['id'] ?>" class="btn btn-sm btn-outline-secondary">Audit napló</a>
  </div>
</div>

<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="POST" action="/hr_permissions_save">
  <input type="hidden" name="_csrf"    value="<?= h($csrf) ?>">
  <input type="hidden" name="user_id"  value="<?= (int)$authUser['id'] ?>">

  <div class="row g-3">

    <!-- Divíziók -->
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
          <strong>Divíziók</strong>
          <div class="d-flex gap-1">
            <button type="button" class="btn btn-outline-secondary btn-sm js-check-all" data-group="div">Mind</button>
            <button type="button" class="btn btn-outline-secondary btn-sm js-check-none" data-group="div">Egyik sem</button>
          </div>
        </div>
        <div class="card-body py-2">
          <?php if (empty($divisions)): ?>
            <div class="text-muted small">Nincs aktív divízió.</div>
          <?php endif; ?>
          <?php foreach ($divisions as $d): ?>
            <div class="form-check">
              <input class="form-check-input chk-div" type="checkbox"
                     name="divisions[]" value="<?= (int)$d['id'] ?>"
                     id="div_<?= (int)$d['id'] ?>"
                     <?= in_array((int)$d['id'], $selDivisions, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="div_<?= (int)$d['id'] ?>"><?= h($d['name']) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Statikus mezők -->
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
          <strong>Mezők</strong>
          <div class="d-flex gap-1">
            <button type="button" class="btn btn-outline-secondary btn-sm js-check-all" data-group="fld">Mind</button>
            <button type="button" class="btn btn-outline-secondary btn-sm js-check-none" data-group="fld">Egyik sem</button>
          </div>
        </div>
        <div class="card-body py-2">
          <div class="mb-2">
            <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.04em">Mindig látható</span>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" checked disabled>
              <label class="form-check-label text-muted">Teljes név</label>
            </div>
          </div>
          <?php foreach ($staticFields as $sectionName => $sectionDefs): ?>
            <div class="mb-2">
              <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.04em">
                <?= h($sectionName) ?>
              </span>
              <?php foreach ($sectionDefs as $key => $label): ?>
                <div class="form-check">
                  <input class="form-check-input chk-fld" type="checkbox"
                         name="fields[]" value="<?= h($key) ?>"
                         id="fld_<?= h($key) ?>"
                         <?= in_array($key, $selFields, true) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="fld_<?= h($key) ?>"><?= h($label) ?></label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Extra mezők -->
    <div class="col-lg-3">
      <div class="card h-100">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
          <strong>Extra mezők</strong>
          <div class="d-flex gap-1">
            <button type="button" class="btn btn-outline-secondary btn-sm js-check-all" data-group="ef">Mind</button>
            <button type="button" class="btn btn-outline-secondary btn-sm js-check-none" data-group="ef">Egyik sem</button>
          </div>
        </div>
        <div class="card-body py-2">
          <?php if (empty($extraFields)): ?>
            <div class="text-muted small">Nincs aktív extra mező.</div>
          <?php endif; ?>
          <?php foreach ($extraFields as $ef): ?>
            <div class="form-check">
              <input class="form-check-input chk-ef" type="checkbox"
                     name="extra_fields[]" value="<?= (int)$ef['id'] ?>"
                     id="ef_<?= (int)$ef['id'] ?>"
                     <?= in_array((int)$ef['id'], $selExtraFields, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="ef_<?= (int)$ef['id'] ?>"><?= h($ef['name']) ?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div><!-- /row -->

  <div class="mt-3 d-flex justify-content-between align-items-center">
    <div>
      <?php if ($permId !== null): ?>
        <button type="submit" form="form-delete-perm"
                class="btn btn-outline-danger btn-sm"
                onclick="return confirm('Biztosan törlöd a teljes jogosultság rekordot? A felhasználó elveszíti az összes HR hozzáférését.')">
          Jogosultság törlése
        </button>
      <?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary px-4">Mentés</button>
  </div>
</form>

<?php if ($permId !== null): ?>
<form id="form-delete-perm" method="POST" action="/hr_permissions_delete">
  <input type="hidden" name="_csrf"    value="<?= h($csrf) ?>">
  <input type="hidden" name="user_id"  value="<?= (int)$authUser['id'] ?>">
</form>
<?php endif; ?>

<script>
document.querySelectorAll('.js-check-all').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var g = this.dataset.group;
    document.querySelectorAll('.chk-' + g).forEach(function(c){ c.checked = true; });
  });
});
document.querySelectorAll('.js-check-none').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var g = this.dataset.group;
    document.querySelectorAll('.chk-' + g).forEach(function(c){ c.checked = false; });
  });
});
</script>

<?php
// $byDivision  = [div_id => ['name'=>..., 'employees'=>[...]]]
// $allEmployees = [{id, full_name, is_active, div_id, div_name}, ...] — névsor szerint
// $fieldDefs   = ['Szekció' => ['key' => 'Label', ...]]
// $extraFields = [{id, name, ...}]
// $error       = null | 'no_selection' | 'no_fields'
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Adatexport</h4>
  <a href="/employees" class="btn btn-sm btn-outline-secondary">← Vissza</a>
</div>

<?php if ($error === 'no_selection'): ?>
  <div class="alert alert-warning">Legalább egy dolgozót jelölj be.</div>
<?php elseif ($error === 'no_fields'): ?>
  <div class="alert alert-warning">Legalább egy mezőt válassz ki (CSV/XLSX exporthoz).</div>
<?php endif; ?>

<form method="POST" action="/employees_export">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

  <div class="row g-3 align-items-stretch">

    <!-- ── Bal: Dolgozók ──────────────────────────────── -->
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header py-2">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong>Dolgozók</strong>
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <!-- Nézet váltó -->
              <div class="btn-group btn-group-sm" role="group" id="viewToggle">
                <input type="radio" class="btn-check" name="listViewOpt" id="viewDiv" autocomplete="off" checked>
                <label class="btn btn-outline-secondary" for="viewDiv">Divíziónként</label>
                <input type="radio" class="btn-check" name="listViewOpt" id="viewAll" autocomplete="off">
                <label class="btn btn-outline-secondary" for="viewAll">Névsor</label>
              </div>
              <!-- Jelölők -->
              <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAll">Mind</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="selectNone">Egyik sem</button>
            </div>
          </div>
        </div>

        <div class="card-body p-0" id="employeeListBody" style="overflow-y:auto">

          <!-- ── Divíziónkénti nézet ── -->
          <div id="listByDiv">
            <?php foreach ($byDivision as $divId => $div): ?>
              <div class="border-bottom">
                <div class="d-flex align-items-center gap-2 px-3 py-2 bg-light">
                  <input type="checkbox" class="form-check-input div-check" id="div_<?= (int)$divId ?>"
                         data-div="<?= (int)$divId ?>">
                  <label class="form-check-label fw-semibold mb-0" for="div_<?= (int)$divId ?>">
                    <?= h($div['name']) ?>
                    <span class="text-muted fw-normal small">(<?= count($div['employees']) ?> fő)</span>
                  </label>
                </div>
                <div class="px-4 pb-2" data-div-employees="<?= (int)$divId ?>">
                  <?php foreach ($div['employees'] as $emp): ?>
                    <div class="form-check my-1">
                      <input class="form-check-input emp-check-div" type="checkbox"
                             name="emp_ids[]" value="<?= (int)$emp['id'] ?>"
                             id="emp_div_<?= (int)$emp['id'] ?>"
                             data-div="<?= (int)$divId ?>">
                      <label class="form-check-label" for="emp_div_<?= (int)$emp['id'] ?>">
                        <?= h($emp['full_name']) ?>
                        <?php if (!(int)$emp['is_active']): ?>
                          <span class="badge bg-secondary" style="font-size:.65rem">inaktív</span>
                        <?php endif; ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- ── Teljes névsor nézet ── -->
          <div id="listAll" style="display:none" class="px-3 py-2">
            <div class="text-muted small border-bottom pb-1 mb-2">
              Összesen: <?= count($allEmployees) ?> fő
            </div>
            <?php foreach ($allEmployees as $emp): ?>
              <div class="form-check my-1">
                <input class="form-check-input emp-check-all" type="checkbox"
                       name="emp_ids[]" value="<?= (int)$emp['id'] ?>"
                       id="emp_all_<?= (int)$emp['id'] ?>" disabled>
                <label class="form-check-label" for="emp_all_<?= (int)$emp['id'] ?>">
                  <?= h($emp['full_name']) ?>
                  <?php if (!(int)$emp['is_active']): ?>
                    <span class="badge bg-secondary" style="font-size:.65rem">inaktív</span>
                  <?php endif; ?>
                  <span class="text-muted small">(<?= h($emp['div_name']) ?>)</span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>

        </div>
      </div>
    </div>

    <!-- ── Jobb: Mezők + Formátum ─────────────────────── -->
    <div class="col-lg-7">

      <!-- Mezők -->
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
          <strong>Mezők</strong>
          <span class="text-muted small">CSV/XLSX exporthoz</span>
        </div>
        <div class="card-body py-2">
          <div class="row g-0">
            <?php foreach ($fieldDefs as $sectionName => $sectionFields): ?>
              <div class="col-sm-6 mb-2">
                <div class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.04em">
                  <?= h($sectionName) ?>
                </div>
                <?php foreach ($sectionFields as $key => $label): ?>
                  <div class="form-check form-check-sm">
                    <input class="form-check-input" type="checkbox" name="fields[]"
                           value="<?= h($key) ?>" id="f_<?= h($key) ?>" checked>
                    <label class="form-check-label small" for="f_<?= h($key) ?>"><?= h($label) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>

            <?php if (!empty($extraFields)): ?>
              <div class="col-sm-6 mb-2">
                <div class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.04em">
                  Extra mezők
                </div>
                <?php foreach ($extraFields as $ef): ?>
                  <div class="form-check form-check-sm">
                    <input class="form-check-input" type="checkbox" name="fields[]"
                           value="extra_<?= (int)$ef['id'] ?>" id="f_extra_<?= (int)$ef['id'] ?>" checked>
                    <label class="form-check-label small" for="f_extra_<?= (int)$ef['id'] ?>">
                      <?= h($ef['name']) ?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Formátum -->
      <div class="card mb-3">
        <div class="card-header py-2"><strong>Formátum</strong></div>
        <div class="card-body py-2">
          <div class="d-flex flex-wrap gap-3">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="format" value="csv" id="fmt_csv" checked>
              <label class="form-check-label" for="fmt_csv">
                <span class="badge bg-success me-1">CSV</span> Vesszővel tagolt, UTF-8
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="format" value="xlsx" id="fmt_xlsx">
              <label class="form-check-label" for="fmt_xlsx">
                <span class="badge bg-primary me-1">XLSX</span> Excel-munkafüzet
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="format" value="pdf" id="fmt_pdf">
              <label class="form-check-label" for="fmt_pdf">
                <span class="badge bg-danger me-1">PDF</span> Formázott adatlapok (oldalanként 1 fő)
              </label>
            </div>
          </div>
          <p class="text-muted small mt-2 mb-0">
            PDF esetén a mezőválasztó nem érvényes — minden dolgozóhoz teljes adatlap jelenik meg.
          </p>
        </div>
      </div>

      <!-- Gomb -->
      <div class="d-flex justify-content-end">
        <button type="submit" class="btn btn-primary px-4">
          Exportálás
        </button>
      </div>

    </div><!-- /col -->
  </div><!-- /row -->
</form>

<script>
(function () {
  // Kártya belső magasságát a viewport maradékára állítja
  var listBody = document.getElementById('employeeListBody');
  function fitListHeight() {
    var top    = listBody.getBoundingClientRect().top;
    var footer = document.querySelector('footer');
    var footerH = footer ? footer.offsetHeight : 0;
    var available = window.innerHeight - top - footerH - 16;
    listBody.style.height = Math.max(120, available) + 'px';
  }
  fitListHeight();
  window.addEventListener('resize', fitListHeight);
  var viewDivBtn = document.getElementById('viewDiv');
  var viewAllBtn = document.getElementById('viewAll');
  var listByDiv  = document.getElementById('listByDiv');
  var listAll    = document.getElementById('listAll');

  // Szinkronizál: forrás → cél checkbox-ok alapján
  function syncChecked(fromClass, toClass) {
    document.querySelectorAll('.' + toClass).forEach(function (dst) {
      var src = document.querySelector('.' + fromClass + '[value="' + dst.value + '"]');
      if (src) dst.checked = src.checked;
    });
  }

  function switchToDiv() {
    // Névsor → divíziós szinkron
    syncChecked('emp-check-all', 'emp-check-div');
    // Divíziós jelölők frissítése
    document.querySelectorAll('.div-check').forEach(function (dc) {
      var divId   = dc.dataset.div;
      var all     = document.querySelectorAll('.emp-check-div[data-div="' + divId + '"]');
      var checked = document.querySelectorAll('.emp-check-div[data-div="' + divId + '"]:checked');
      dc.checked = (all.length > 0 && all.length === checked.length);
    });
    // Aktív/inaktív
    document.querySelectorAll('.emp-check-div').forEach(function (c) { c.disabled = false; });
    document.querySelectorAll('.emp-check-all').forEach(function (c) { c.disabled = true; });
    listByDiv.style.display = '';
    listAll.style.display   = 'none';
  }

  function switchToAll() {
    // Divíziós → névsor szinkron
    syncChecked('emp-check-div', 'emp-check-all');
    // Aktív/inaktív
    document.querySelectorAll('.emp-check-all').forEach(function (c) { c.disabled = false; });
    document.querySelectorAll('.emp-check-div').forEach(function (c) { c.disabled = true; });
    listByDiv.style.display = 'none';
    listAll.style.display   = '';
  }

  viewDivBtn.addEventListener('change', function () { if (this.checked) switchToDiv(); });
  viewAllBtn.addEventListener('change', function () { if (this.checked) switchToAll(); });

  // Divízió "mind" jelölő → tagok be/ki
  document.querySelectorAll('.div-check').forEach(function (dc) {
    dc.addEventListener('change', function () {
      var divId = this.dataset.div;
      document.querySelectorAll('.emp-check-div[data-div="' + divId + '"]').forEach(function (ec) {
        ec.checked = dc.checked;
      });
    });
  });

  // Ha minden tag be van jelölve → divízió jelölő is be
  document.querySelectorAll('.emp-check-div').forEach(function (ec) {
    ec.addEventListener('change', function () {
      var divId   = this.dataset.div;
      var all     = document.querySelectorAll('.emp-check-div[data-div="' + divId + '"]');
      var checked = document.querySelectorAll('.emp-check-div[data-div="' + divId + '"]:checked');
      var dc = document.querySelector('.div-check[data-div="' + divId + '"]');
      if (dc) dc.checked = (all.length === checked.length);
    });
  });

  // Mind / Egyik sem gombok
  document.getElementById('selectAll').addEventListener('click', function () {
    if (listByDiv.style.display !== 'none') {
      document.querySelectorAll('.emp-check-div, .div-check').forEach(function (c) { c.checked = true; });
    } else {
      document.querySelectorAll('.emp-check-all').forEach(function (c) { c.checked = true; });
    }
  });
  document.getElementById('selectNone').addEventListener('click', function () {
    if (listByDiv.style.display !== 'none') {
      document.querySelectorAll('.emp-check-div, .div-check').forEach(function (c) { c.checked = false; });
    } else {
      document.querySelectorAll('.emp-check-all').forEach(function (c) { c.checked = false; });
    }
  });
})();
</script>

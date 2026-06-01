<?php $old = $old ?? []; ?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Új dolgozó</h3>
  <a class="btn btn-sm btn-outline-secondary" href="/employees">Vissza</a>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="/employees_create" class="card mb-4" enctype="multipart/form-data">
  <div class="card-body">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="row g-3">

      <!-- Személyes adatok -->
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Személyes adatok</h6>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Név *</label>
              <input class="form-control" type="text" name="full_name" value="<?= h($old['full_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Születési név</label>
              <input class="form-control" type="text" name="birth_name" value="<?= h($old['birth_name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Anyja neve</label>
              <input class="form-control" type="text" name="mother_name" value="<?= h($old['mother_name'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Születési hely</label>
              <input class="form-control" type="text" name="birth_place" value="<?= h($old['birth_place'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Születési dátum</label>
              <input class="form-control" type="date" name="birth_date" value="<?= h($old['birth_date'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Céges / azonosító -->
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Céges / azonosító</h6>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Adóazonosító</label>
              <input class="form-control" type="text" name="tax_id" value="<?= h($old['tax_id'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">TAJ szám</label>
              <input class="form-control" type="text" name="taj" value="<?= h($old['taj'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Céges törzsszám</label>
              <input class="form-control" type="text" name="company_emp_no" value="<?= h($old['company_emp_no'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Divízió</label>
              <select class="form-select" name="division_id">
                <option value="0">—</option>
                <?php foreach ($divisions as $d): ?>
                  <option value="<?= (int)$d['id'] ?>" <?= ((int)($old['division_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                    <?= h($d['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Aktív</label>
              <select class="form-select" name="is_active">
                <option value="1" <?= ((int)($old['is_active'] ?? 1) === 1) ? 'selected' : '' ?>>igen</option>
                <option value="0" <?= ((int)($old['is_active'] ?? 1) === 0) ? 'selected' : '' ?>>nem</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- Bankszámla -->
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Bankszámla</h6>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Bankszámlaszám</label>
              <input class="form-control" type="text" name="bank_account" id="bank_account"
                     value="<?= h($old['bank_account'] ?? '') ?>"
                     placeholder="12345678-12345678-12345678">
            </div>
            <div class="col-md-6">
              <label class="form-label">Bank neve</label>
              <input class="form-control" type="text" name="bank_name" id="bank_name"
                     value="<?= h($old['bank_name'] ?? '') ?>"
                     placeholder="automatikusan kitöltve">
            </div>
          </div>
        </div>
      </div>

      <!-- Munkaviszony -->
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Munkaviszony</h6>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Belépés dátuma</label>
              <input class="form-control" type="date" name="hired_on" value="<?= h($old['hired_on'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Kilépés dátuma</label>
              <input class="form-control" type="date" name="left_on" value="<?= h($old['left_on'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Lakcím -->
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Lakcím</h6>
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Irányítószám</label>
              <input class="form-control" type="text" name="addr_zip" value="<?= h($old['addr_zip'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Település</label>
              <input class="form-control" type="text" name="addr_city" value="<?= h($old['addr_city'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Cím</label>
              <input class="form-control" type="text" name="addr_line" value="<?= h($old['addr_line'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Kapcsolat -->
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Kapcsolat</h6>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Email (céges)</label>
              <input class="form-control" type="email" name="email" value="<?= h($old['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email (privát)</label>
              <input class="form-control" type="email" name="email_private" value="<?= h($old['email_private'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Bérjegyzék email</label>
              <select class="form-select" name="payslip_email_target">
                <option value="ceges" <?= ($old['payslip_email_target'] ?? 'ceges') === 'ceges' ? 'selected' : '' ?>>Céges email</option>
                <option value="privat" <?= ($old['payslip_email_target'] ?? '') === 'privat' ? 'selected' : '' ?>>Privát email</option>
              </select>
              <div class="form-text">Erre a címre küldi a rendszer a havi bérjegyzéket.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Telefonszám</label>
              <input class="form-control" type="text" name="phone" value="<?= h($old['phone'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Megjegyzés</label>
              <textarea class="form-control" name="notes" rows="3"><?= h($old['notes'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Profilkép -->
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Profilkép</h6>
          <input class="form-control" type="file" name="profile_image" accept="image/*">
          <div class="form-text">Max 25MB. JPG/PNG/WEBP.</div>
        </div>
      </div>

    </div>

    <div class="border rounded p-3 mt-3">
      <h5 class="m-0 mb-2">Extra mezők</h5>
      <div class="row g-3">
        <?php foreach (($fields ?? []) as $f): ?>
          <?php
            $fid = (int)$f['id'];
            $type = (string)$f['field_type'];
            $val = '';
            if (!empty($field_values) && isset($field_values[$fid]['value'])) $val = $field_values[$fid]['value'];
            else if (isset($old) && isset($old['field'][$fid])) $val = $old['field'][$fid];
          ?>
          <div class="col-md-6">
            <?php
              $showChecked = 1;
              if (!empty($field_values) && isset($field_values[$fid]['show'])) $showChecked = (int)$field_values[$fid]['show'];
              if (!empty($old['show']) && isset($old['show'][$fid])) $showChecked = 1;
            ?>
            <label class="form-label">
              <input class="form-check-input me-2" type="checkbox" name="show[<?= $fid ?>]" value="1" <?= ($showChecked===1) ? 'checked' : '' ?>>
              <?= h($f['name']) ?>
            </label>

            <?php if ($type === 'textarea'): ?>
              <textarea class="form-control" name="field[<?= $fid ?>]" rows="3"><?= h($val) ?></textarea>

            <?php elseif ($type === 'select' || $type === 'multiselect'): ?>
              <?php $opts = json_decode((string)($f['options'] ?? ''), true); if (!is_array($opts)) $opts = []; ?>
              <?php if ($type === 'select'): ?>
                <select class="form-select" name="field[<?= $fid ?>]">
                  <option value=""></option>
                  <?php foreach ($opts as $o): ?>
                    <option value="<?= h($o) ?>" <?= ($val===$o)?'selected':'' ?>><?= h($o) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <?php $arr = json_decode((string)$val, true); if (!is_array($arr)) $arr = []; ?>
                <div class="d-flex flex-wrap gap-2">
                  <?php foreach ($opts as $o): ?>
                    <label class="form-check form-check-inline m-0">
                      <input class="form-check-input" type="checkbox" name="field[<?= $fid ?>][]" value="<?= h($o) ?>" <?= in_array($o, $arr, true)?'checked':'' ?>>
                      <span class="form-check-label"><?= h($o) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

            <?php elseif ($type === 'date'): ?>
              <input class="form-control" type="date" name="field[<?= $fid ?>]" value="<?= h($val) ?>">

            <?php elseif ($type === 'number'): ?>
              <input class="form-control" type="number" step="any" name="field[<?= $fid ?>]" value="<?= h($val) ?>">

            <?php else: ?>
              <input class="form-control" type="text" name="field[<?= $fid ?>]" value="<?= h($val) ?>">
            <?php endif; ?>

          </div>
        <?php endforeach; ?>

        <?php if (empty($fields)): ?>
          <div class="col-12 text-muted">Nincs aktív extra mező.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Létrehozás</button>
      <a class="btn btn-outline-secondary" href="/employees">Mégse</a>
    </div>
  </div>
</form>

<script>
(function(){
  var banks = {
    '100': 'Magyar Nemzeti Bank (MNB)',
    '101': 'OTP Bank',
    '102': 'MBH Bank (MKB)',
    '103': 'UniCredit Bank',
    '104': 'K&H Bank',
    '105': 'CIB Bank',
    '106': 'Fundamenta-Lakáskassza',
    '107': 'CIB Bank',
    '109': 'Magyar Export-Import Bank',
    '116': 'Erste Bank',
    '117': 'OTP Bank',
    '120': 'Raiffeisen Bank',
    '121': 'Erste Bank (George)',
    '122': 'UniCredit Bank',
    '123': 'Citibank',
    '125': 'Budapest Bank (BB)',
    '131': 'MBH Bank',
    '141': 'FHB Bank',
    '147': 'MBH Bank (ex-Sberbank)',
    '154': 'OBERBANK',
    '155': 'Gránit Bank',
    '156': 'Gránit Bank',
    '157': 'KDB Bank',
    '162': 'Commerzbank',
    '164': 'Cetelem Bank',
    '167': 'MBH Bank (ex-MKB)',
    '172': 'MBH Bank (Takarék)',
    '173': 'MBH Bank',
    '174': 'MBH Bank',
    '176': 'Erste Bank',
    '177': 'MBH Bank',
    '178': 'OTP Bank',
    '179': 'OTP Bank',
    '184': 'OTP Jelzálogbank',
    '189': 'UniCredit Jelzálogbank',
    '219': 'Magnet Bank',
    '239': 'Budapest Bank',
    '314': 'Cofidis',
    '523': 'Gránit Bank',
    '526': 'Gránit Bank'
  };

  function detectBank(val) {
    var digits = val.replace(/[\s\-]/g, '');
    if (digits.toUpperCase().startsWith('HU') && digits.length >= 6) {
      digits = digits.substring(4);
    }
    var prefix3 = digits.substring(0, 3);
    var prefix2 = digits.substring(0, 2);
    return banks[prefix3] || banks[prefix2] || '';
  }

  var accInput = document.getElementById('bank_account');
  var nameInput = document.getElementById('bank_name');
  if (accInput && nameInput) {
    accInput.addEventListener('input', function() {
      var detected = detectBank(this.value.trim());
      if (detected) nameInput.value = detected;
    });
  }
})();
</script>

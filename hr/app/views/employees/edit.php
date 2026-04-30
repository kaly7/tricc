<?php
// expects: $emp, $divisions, $csrf, $success, $error, $docs, $docTypes, $canSee, $canSeeExtra
if (!isset($canSee))      $canSee      = fn(string $key): bool => true;
if (!isset($canSeeExtra)) $canSeeExtra = fn(int $id): bool => true;
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Dolgozó szerkesztése</h3>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/employees">Vissza</a>
    <a class="btn btn-sm btn-outline-secondary" href="/employees_view?id=<?= (int)$emp['id'] ?>">Karton</a>
  </div>
</div>

<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="/employees_edit" class="card mb-4" enctype="multipart/form-data">
  <div class="card-body">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">

    <div class="row g-3">

      <!-- Személyes adatok -->
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Személyes adatok</h6>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Név *</label>
              <input class="form-control" type="text" name="full_name" value="<?= h($emp['full_name'] ?? '') ?>" required>
            </div>
            <?php if ($canSee('birth_name')): ?>
            <div class="col-md-6">
              <label class="form-label">Születési név</label>
              <input class="form-control" type="text" name="birth_name" value="<?= h($emp['birth_name'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('mother_name')): ?>
            <div class="col-md-6">
              <label class="form-label">Anyja neve</label>
              <input class="form-control" type="text" name="mother_name" value="<?= h($emp['mother_name'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('birth_place')): ?>
            <div class="col-md-3">
              <label class="form-label">Születési hely</label>
              <input class="form-control" type="text" name="birth_place" value="<?= h($emp['birth_place'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('birth_date')): ?>
            <div class="col-md-3">
              <label class="form-label">Születési dátum</label>
              <input class="form-control" type="date" name="birth_date" value="<?= h($emp['birth_date'] ?? '') ?>">
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Céges / azonosító -->
      <?php if ($canSee('tax_id') || $canSee('taj') || $canSee('company_emp_no') || $canSee('division_name') || !empty($is_admin)): ?>
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Céges / azonosító</h6>
          <div class="row g-3">
            <?php if ($canSee('tax_id')): ?>
            <div class="col-md-4">
              <label class="form-label">Adóazonosító</label>
              <input class="form-control" type="text" name="tax_id" value="<?= h($emp['tax_id'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('taj')): ?>
            <div class="col-md-4">
              <label class="form-label">TAJ szám</label>
              <input class="form-control" type="text" name="taj" value="<?= h($emp['taj'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('company_emp_no')): ?>
            <div class="col-md-4">
              <label class="form-label">Céges törzsszám</label>
              <input class="form-control" type="text" name="company_emp_no" value="<?= h($emp['company_emp_no'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if (!empty($is_admin)): ?>
            <div class="col-md-4">
              <label class="form-label">Divízió</label>
              <select class="form-select" name="division_id">
                <option value="0">—</option>
                <?php foreach (($divisions ?? []) as $d): ?>
                  <option value="<?= (int)$d['id'] ?>" <?= ((int)($emp['division_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                    <?= h($d['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php elseif ($canSee('division_name')): ?>
            <div class="col-md-4">
              <label class="form-label">Divízió</label>
              <input class="form-control" type="text" value="<?= h($emp['division_name'] ?? '—') ?>" readonly>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Bankszámla -->
      <?php if ($canSee('bank_account') || $canSee('bank_name')): ?>
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Bankszámla</h6>
          <div class="row g-3">
            <?php if ($canSee('bank_account')): ?>
            <div class="col-md-6">
              <label class="form-label">Bankszámlaszám</label>
              <input class="form-control" type="text" name="bank_account" id="bank_account"
                     value="<?= h($emp['bank_account'] ?? '') ?>"
                     placeholder="12345678-12345678-12345678">
            </div>
            <?php endif; ?>
            <?php if ($canSee('bank_name')): ?>
            <div class="col-md-6">
              <label class="form-label">Bank neve</label>
              <input class="form-control" type="text" name="bank_name" id="bank_name"
                     value="<?= h($emp['bank_name'] ?? '') ?>"
                     placeholder="automatikusan kitöltve">
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Munkaviszony -->
      <?php if ($canSee('hired_on') || $canSee('left_on')): ?>
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Munkaviszony</h6>
          <div class="row g-3">
            <?php if ($canSee('hired_on')): ?>
            <div class="col-md-4">
              <label class="form-label">Belépés dátuma</label>
              <input class="form-control" type="date" name="hired_on" value="<?= h($emp['hired_on'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('left_on')): ?>
            <div class="col-md-4">
              <label class="form-label">Kilépés dátuma</label>
              <input class="form-control" type="date" name="left_on" value="<?= h($emp['left_on'] ?? '') ?>">
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Lakcím -->
      <?php if ($canSee('addr_zip') || $canSee('addr_city') || $canSee('addr_line')): ?>
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Lakcím</h6>
          <div class="row g-3">
            <?php if ($canSee('addr_zip')): ?>
            <div class="col-md-3">
              <label class="form-label">Irányítószám</label>
              <input class="form-control" type="text" name="addr_zip" value="<?= h($emp['addr_zip'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('addr_city')): ?>
            <div class="col-md-3">
              <label class="form-label">Település</label>
              <input class="form-control" type="text" name="addr_city" value="<?= h($emp['addr_city'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('addr_line')): ?>
            <div class="col-md-6">
              <label class="form-label">Cím</label>
              <input class="form-control" type="text" name="addr_line" value="<?= h($emp['addr_line'] ?? '') ?>">
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Kapcsolat -->
      <?php if ($canSee('email') || $canSee('phone') || $canSee('notes')): ?>
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Kapcsolat</h6>
          <div class="row g-3">
            <?php if ($canSee('email')): ?>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input class="form-control" type="email" name="email" value="<?= h($emp['email'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('phone')): ?>
            <div class="col-md-6">
              <label class="form-label">Telefonszám</label>
              <input class="form-control" type="text" name="phone" value="<?= h($emp['phone'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <?php if ($canSee('notes')): ?>
            <div class="col-12">
              <label class="form-label">Megjegyzés</label>
              <textarea class="form-control" name="notes" rows="4"><?= h($emp['notes'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Profilkép – csak admin -->
      <?php if (!empty($is_admin)): ?>
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-muted mb-3">Profilkép</h6>
          <div class="row g-3 align-items-center">
            <div class="col-md-8">
              <input class="form-control" type="file" name="profile_image" accept="image/*">
              <div class="form-text">Max 25MB. JPG/PNG/WEBP.</div>
            </div>
            <div class="col-md-4">
              <?php if (!empty($emp['profile_image_path'])): ?>
                <a href="<?= h($emp['profile_image_path']) ?>" target="_blank" rel="noopener">
                  <img src="<?= h($emp['profile_image_path']) ?>" class="img-thumbnail" style="max-height:140px;">
                </a>
              <?php else: ?>
                <span class="text-muted">Nincs feltöltött kép.</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>

    
  <?php
  $visibleExtraFields = array_filter($fields ?? [], fn($f) => $canSeeExtra((int)$f['id']));
  ?>
  <?php if (!empty($visibleExtraFields)): ?>
  <div class="border rounded p-3 mt-3">
    <h5 class="m-0 mb-2">Extra mezők</h5>
    <div class="row g-3">
      <?php foreach ($visibleExtraFields as $f): ?>
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

    </div>
  </div>
  <?php endif; ?>

<div class="mt-3 border-top pt-3 d-flex align-items-center justify-content-between gap-2">
      <!-- Bal: törlés + inaktivál -->
      <div class="d-flex gap-2">
        <?php if (!empty($is_admin)): ?>
          <button type="submit" form="form-delete-<?= (int)$emp['id'] ?>" class="btn btn-outline-danger"
                  onclick="return confirm('Biztosan véglegesen törlöd a dolgozót és a kapcsolódó adatait?')">Törlés</button>
        <?php endif; ?>
        <button type="submit" form="form-toggle-<?= (int)$emp['id'] ?>"
                class="btn btn-outline-secondary"
                onclick="return confirm('Biztosan módosítod a dolgozó állapotát?')"><?= ((int)($emp['is_active'] ?? 1) === 1) ? 'Inaktivál' : 'Aktivál' ?></button>
      </div>
      <!-- Jobb: mentés -->
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/employees">Mégse</a>
        <button class="btn btn-primary" type="submit">Mentés</button>
      </div>
    </div>
  </div>
</form>

<form id="form-delete-<?= (int)$emp['id'] ?>" method="post" action="/employees_delete">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
</form>
<form id="form-toggle-<?= (int)$emp['id'] ?>" method="post" action="/employees_toggle">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
</form>

<!-- Dokumentumok blokk a szerkesztés oldalon -->
<div class="border rounded p-3 mb-4">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h5 class="m-0">Dokumentumok</h5>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/documents?employee_id=<?= (int)$emp['id'] ?>">Lista</a>
      <a class="btn btn-sm btn-primary" href="/documents_upload?employee_id=<?= (int)$emp['id'] ?>">+ Feltöltés</a>
    </div>
  </div>

  <?php if (empty($docs)): ?>
    <div class="text-muted">Nincs dokumentum.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead>
          <tr>
            <th>Típus</th>
            <th>Megnevezés</th>
            <th>Fájl</th>
            <th>Lejárat</th>
            <th>Feltöltve</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $today = new DateTime('today');
            foreach ($docs as $d):
              $expires = $d['expires_at'] ?? null;
              $badge = '';
              if ($expires) {
                $dd = DateTime::createFromFormat('Y-m-d', $expires);
                if ($dd) {
                  $diffDays = (int)$today->diff($dd)->format('%r%a');
                  if ($diffDays < 0) $badge = '<span class="badge bg-danger">'.h($expires).'</span>';
                  else if ($diffDays <= 30) $badge = '<span class="badge bg-warning text-dark">'.h($expires).'</span>';
                  else $badge = '<span class="badge bg-secondary">'.h($expires).'</span>';
                } else $badge = h($expires);
              }
              $fileLabel = $d['original_name'] ?: basename((string)$d['file_path']);
          ?>
            <tr>
              <td><?= h($d['type_name'] ?? '') ?></td>
              <td><?= h($d['title'] ?? '') ?></td>
              <td><a href="<?= h($d['file_path']) ?>" target="_blank" rel="noopener"><?= h($fileLabel) ?></a></td>
              <td><?= $badge ?></td>
              <td><?= h($d['created_at'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

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

<?php $old = $old ?? []; ?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Új dolgozó</h3>
  <a class="btn btn-sm btn-outline-secondary" href="/employees">Vissza</a>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="/employees_create" class="card" enctype="multipart/form-data">
  <div class="card-body">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Név *</label>
        <!-- input class="form-control" type="text" name="name" value="<?= h($old['name'] ?? '') ?>" required -->

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

      <div class="col-md-6">
        <label class="form-label">Születési hely</label>
        <input class="form-control" type="text" name="birth_place" value="<?= h($old['birth_place'] ?? '') ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">Születési dátum (ÉÉÉÉ-HH-NN)</label>
        <input class="form-control" type="text" name="birth_date" value="<?= h($old['birth_date'] ?? '') ?>" placeholder="1980-12-31">
      </div>

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
        <!-- input class="form-control" type="text" name="company_number" value="<?= h($old['company_number'] ?? '') ?>" -->

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

      <div class="col-12">
        <label class="form-label">Megjegyzés</label>
        <textarea class="form-control" name="notes" rows="3"><?= h($old['notes'] ?? '') ?></textarea>
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

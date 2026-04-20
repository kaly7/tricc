<?php
// decode options json to textarea lines
$opts = '';
if (!empty($field['options'])) {
  $arr = json_decode((string)$field['options'], true);
  if (is_array($arr)) $opts = implode("\n", $arr);
}
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Mező szerkesztése</h3>
  <a class="btn btn-sm btn-outline-secondary" href="/fields">Vissza</a>
</div>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<form method="post" action="/fields_edit" class="card">
  <div class="card-body">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$field['id'] ?>">

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Mező neve</label>
        <input class="form-control" name="name" value="<?= h($field['name']) ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label">Kulcs</label>
        <input class="form-control" name="field_key" value="<?= h($field['field_key']) ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Típus</label>
        <select class="form-select" name="field_type">
          <?php
            $types = ['text','textarea','select','multiselect','date','number'];
            foreach ($types as $t):
          ?>
            <option value="<?= h($t) ?>" <?= ((string)$field['field_type']===$t)?'selected':'' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Aktív</label>
        <select class="form-select" name="is_active">
          <option value="1" <?= ((int)$field['is_active']===1)?'selected':'' ?>>igen</option>
          <option value="0" <?= ((int)$field['is_active']===0)?'selected':'' ?>>nem</option>
        </select>
      </div>

      <div class="col-md-8">
        <label class="form-label">Opciók (soronként 1)</label>
        <textarea class="form-control" name="options" rows="5"><?= h($opts) ?></textarea>
        <div class="form-text">Csak select/multiselect esetén számít.</div>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Mentés</button>
      <a class="btn btn-outline-secondary" href="/fields">Vissza</a>
    </div>
  </div>
</form>

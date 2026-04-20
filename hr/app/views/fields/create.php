<?php $old = $old ?? []; ?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Új extra mező</h3>
  <a class="btn btn-sm btn-outline-secondary" href="/fields">Vissza</a>
</div>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<form method="post" action="/fields_create" class="card">
  <div class="card-body">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Mező neve</label>
        <input class="form-control" name="name" value="<?= h($old['name'] ?? '') ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label">Kulcs (egyedi, pl. license_type)</label>
        <input class="form-control" name="field_key" value="<?= h($old['field_key'] ?? '') ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Típus</label>
        <select class="form-select" name="field_type" id="ftype">
          <?php
            $types = ['text'=>'text','textarea'=>'textarea','select'=>'select','multiselect'=>'multiselect','date'=>'date','number'=>'number'];
            $cur = $old['field_type'] ?? 'text';
            foreach ($types as $k=>$lbl):
          ?>
            <option value="<?= h($k) ?>" <?= ($cur===$k)?'selected':'' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Select/multiselect esetén adj meg opciókat.</div>
      </div>

      <div class="col-md-8">
        <label class="form-label">Opciók (soronként 1)</label>
        <textarea class="form-control" name="options" rows="5"><?= h($old['options'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Létrehozás</button>
      <a class="btn btn-outline-secondary" href="/fields">Mégse</a>
    </div>
  </div>
</form>

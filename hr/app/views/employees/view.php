<?php
// expects: $emp, $docs, $fields, $field_values, $csrf, $canSee, $canSeeExtra
// $canSee(string $key): bool  –  null → admin (minden látható)
// $canSeeExtra(int $id): bool
if (!isset($canSee))      $canSee      = fn(string $key): bool => true;
if (!isset($canSeeExtra)) $canSeeExtra = fn(int $id): bool => true;

// Szekciók láthatóságát előre kiszámítjuk
$showPersonal  = $canSee('birth_name') || $canSee('mother_name') || $canSee('birth_place') || $canSee('birth_date');
$showCompany   = $canSee('tax_id') || $canSee('taj') || $canSee('company_emp_no') || $canSee('division_name');
$showBank      = $canSee('bank_account') || $canSee('bank_name');
$showEmploy    = $canSee('hired_on') || $canSee('left_on');
$showAddr      = $canSee('addr_zip') || $canSee('addr_city') || $canSee('addr_line');
$showContact   = $canSee('email') || $canSee('phone') || $canSee('notes');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Dolgozói karton</h3>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/employees">Vissza</a>
    <a class="btn btn-sm btn-outline-primary" href="/employees_pdf?id=<?= (int)($emp['id'] ?? 0) ?>" target="_blank">Karton PDF</a>
    <a class="btn btn-sm btn-primary" href="/employees_edit?id=<?= (int)$emp['id'] ?>">Szerkesztés</a>
    <?php if (!empty($is_admin)): ?>
      <form method="post" action="/employees_toggle" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
        <button class="btn btn-sm btn-outline-secondary" type="submit"><?= ((int)($emp['is_active'] ?? 1) === 1) ? 'Inaktiválás' : 'Aktiválás' ?></button>
      </form>
      <form method="post" action="/employees_delete" class="d-inline" onsubmit="return confirm('Biztosan véglegesen törlöd a dolgozót és a kapcsolódó adatait?');">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
        <button class="btn btn-sm btn-outline-danger" type="submit">Törlés</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Dokumentum szerkesztő modal -->
<div class="modal fade" id="docEditModal" tabindex="-1" aria-labelledby="docEditModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="/documents_edit" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="doc_id" id="docEditId">
        <input type="hidden" name="_back" value="/employees_view?id=<?= (int)$emp['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="docEditModalLabel">Dokumentum szerkesztése</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Típus</label>
            <select name="document_type_id" id="docEditType" class="form-select" required>
              <?php foreach ($docTypes as $dt): ?>
                <option value="<?= (int)$dt['id'] ?>"><?= h($dt['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Megnevezés</label>
            <input type="text" name="title" id="docEditTitle" class="form-control" placeholder="pl. Munkaszerződés 2024">
          </div>
          <div class="mb-3">
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="has_expiry" id="docEditHasExpiry" value="1">
              <label class="form-check-label" for="docEditHasExpiry">Van lejárati dátum</label>
            </div>
            <input type="date" name="expires_at" id="docEditExpires" class="form-control" style="display:none">
          </div>
          <div class="mb-3">
            <label class="form-label">Fájl cseréje <span class="text-muted">(elhagyható — ha nem töltesz fel, a meglévő marad)</span></label>
            <div class="text-muted small mb-1" id="docEditCurrentFile"></div>
            <input type="file" name="file" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
          <button type="submit" class="btn btn-primary">Mentés</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const docEditModal = document.getElementById('docEditModal');
docEditModal.addEventListener('show.bs.modal', function(e) {
  const btn = e.relatedTarget;
  document.getElementById('docEditId').value      = btn.dataset.id;
  document.getElementById('docEditTitle').value   = btn.dataset.title;
  document.getElementById('docEditCurrentFile').textContent = 'Jelenlegi fájl: ' + btn.dataset.filename;

  const typeSelect = document.getElementById('docEditType');
  for (const opt of typeSelect.options) opt.selected = (opt.value === btn.dataset.type);

  const expires = btn.dataset.expires;
  const hasExp  = document.getElementById('docEditHasExpiry');
  const expInp  = document.getElementById('docEditExpires');
  if (expires) {
    hasExp.checked  = true;
    expInp.style.display = '';
    expInp.value    = expires;
  } else {
    hasExp.checked  = false;
    expInp.style.display = 'none';
    expInp.value    = '';
  }
});

document.getElementById('docEditHasExpiry').addEventListener('change', function() {
  const expInp = document.getElementById('docEditExpires');
  expInp.style.display = this.checked ? '' : 'none';
  if (!this.checked) expInp.value = '';
});
</script>

<div class="row g-3">

  <!-- Bal oszlop: adatok -->
  <div class="col-lg-8">

    <!-- Személyes adatok -->
    <div class="border rounded p-3">
      <h6 class="text-muted mb-3">Személyes adatok</h6>
      <div class="row g-2">
        <div class="col-12">
          <span class="fs-5 fw-semibold"><?= h($emp['full_name'] ?? '') ?></span>
          <?php if ($canSee('is_active')): ?>
            <?php if ((int)($emp['is_active'] ?? 1) === 1): ?>
              <span class="badge bg-success ms-2">aktív</span>
            <?php else: ?>
              <span class="badge bg-danger ms-2">inaktív</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($canSee('birth_name')): ?>
          <div class="col-md-6"><strong>Születési név:</strong> <?= h($emp['birth_name'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('mother_name')): ?>
          <div class="col-md-6"><strong>Anyja neve:</strong> <?= h($emp['mother_name'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('birth_place')): ?>
          <div class="col-md-6"><strong>Születési hely:</strong> <?= h($emp['birth_place'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('birth_date')): ?>
          <div class="col-md-6"><strong>Születési dátum:</strong> <?= h($emp['birth_date'] ?? '—') ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Céges / azonosító -->
    <?php if ($showCompany): ?>
    <div class="border rounded p-3 mt-3">
      <h6 class="text-muted mb-3">Céges / azonosító</h6>
      <div class="row g-2">
        <?php if ($canSee('tax_id')): ?>
          <div class="col-md-4"><strong>Adóazonosító:</strong> <?= h($emp['tax_id'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('taj')): ?>
          <div class="col-md-4"><strong>TAJ szám:</strong> <?= h($emp['taj'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('company_emp_no')): ?>
          <div class="col-md-4"><strong>Céges törzsszám:</strong> <?= h($emp['company_emp_no'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('division_name')): ?>
          <div class="col-md-6"><strong>Divízió:</strong> <?= h($emp['division_name'] ?? '—') ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Bankszámla -->
    <?php if ($showBank): ?>
    <div class="border rounded p-3 mt-3">
      <h6 class="text-muted mb-3">Bankszámla</h6>
      <div class="row g-2">
        <?php if ($canSee('bank_account')): ?>
          <div class="col-md-6"><strong>Bankszámlaszám:</strong> <?= h($emp['bank_account'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('bank_name')): ?>
          <div class="col-md-6"><strong>Bank neve:</strong> <?= h($emp['bank_name'] ?? '—') ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Munkaviszony -->
    <?php if ($showEmploy): ?>
    <div class="border rounded p-3 mt-3">
      <h6 class="text-muted mb-3">Munkaviszony</h6>
      <div class="row g-2">
        <?php if ($canSee('hired_on')): ?>
          <div class="col-md-4"><strong>Belépés dátuma:</strong> <?= h($emp['hired_on'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('left_on')): ?>
          <div class="col-md-4"><strong>Kilépés dátuma:</strong> <?= h($emp['left_on'] ?? '—') ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Lakcím -->
    <?php if ($showAddr): ?>
    <div class="border rounded p-3 mt-3">
      <h6 class="text-muted mb-3">Lakcím</h6>
      <div class="row g-2">
        <?php if ($canSee('addr_zip')): ?>
          <div class="col-md-3"><strong>Irányítószám:</strong> <?= h($emp['addr_zip'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('addr_city')): ?>
          <div class="col-md-3"><strong>Település:</strong> <?= h($emp['addr_city'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('addr_line')): ?>
          <div class="col-md-6"><strong>Cím:</strong> <?= h($emp['addr_line'] ?? '—') ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Kapcsolat -->
    <?php if ($showContact): ?>
    <div class="border rounded p-3 mt-3">
      <h6 class="text-muted mb-3">Kapcsolat</h6>
      <div class="row g-2">
        <?php if ($canSee('email')): ?>
          <div class="col-md-6"><strong>Email (céges):</strong> <?= h($emp['email'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('email_private')): ?>
          <div class="col-md-6"><strong>Email (privát):</strong> <?= h($emp['email_private'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('payslip_email_target')): ?>
          <div class="col-md-6">
            <strong>Bérjegyzék email:</strong>
            <?php if (($emp['payslip_email_target'] ?? 'ceges') === 'privat'): ?>
              <span class="badge bg-info text-dark">Privát</span>
              <?= h($emp['email_private'] ?? '—') ?>
            <?php else: ?>
              <span class="badge bg-secondary">Céges</span>
              <?= h($emp['email'] ?? '—') ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($canSee('phone')): ?>
          <div class="col-md-6"><strong>Telefon:</strong> <?= h($emp['phone'] ?? '—') ?></div>
        <?php endif; ?>
        <?php if ($canSee('notes') && !empty($emp['notes'])): ?>
          <div class="col-12 mt-1"><strong>Megjegyzés:</strong><br><span class="text-muted"><?= nl2br(h($emp['notes'])) ?></span></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Extra mezők -->
    <?php
      $hasExtra = false;
      if (!empty($fields) && !empty($field_values)) {
        foreach ($fields as $f) {
          $fid = (int)$f['id'];
          if (!$canSeeExtra($fid)) continue;
          $val = $field_values[$fid]['value'] ?? '';
          $show = (int)($field_values[$fid]['show'] ?? 1);
          if ($show === 1 && trim((string)$val) !== '') { $hasExtra = true; break; }
        }
      }
    ?>
    <?php if ($hasExtra): ?>
      <div class="border rounded p-3 mt-3">
        <h6 class="text-muted mb-3">Egyéb adatok</h6>
        <div class="row g-2">
          <?php foreach (($fields ?? []) as $f): ?>
            <?php
              $fid  = (int)$f['id'];
              if (!$canSeeExtra($fid)) continue;
              $val  = $field_values[$fid]['value'] ?? '';
              $show = (int)($field_values[$fid]['show'] ?? 1);
              if ($show !== 1 || trim((string)$val) === '') continue;
              $type = $f['field_type'] ?? 'text';
              $disp = $val;
              if ($type === 'multiselect') {
                $arr = json_decode((string)$val, true);
                if (is_array($arr)) $disp = implode(', ', $arr);
              }
            ?>
            <div class="col-md-6">
              <strong><?= h($f['name'] ?? '') ?>:</strong>
              <?= nl2br(h((string)$disp)) ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Dokumentumok -->
    <div class="border rounded p-3 mt-3">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="text-muted m-0">Dokumentumok</h6>
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
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php
                $today = new DateTime('today');
                foreach ($docs as $d):
                  $expires = $d['expires_at'] ?? null;
                  $badge = '—';
                  if ($expires) {
                    $dd = DateTime::createFromFormat('Y-m-d', $expires);
                    if ($dd) {
                      $diffDays = (int)$today->diff($dd)->format('%r%a');
                      if ($diffDays < 0)      $badge = '<span class="badge bg-danger">'.h($expires).'</span>';
                      elseif ($diffDays <= 30) $badge = '<span class="badge bg-warning text-dark">'.h($expires).'</span>';
                      else                    $badge = '<span class="badge bg-secondary">'.h($expires).'</span>';
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
                  <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                      data-bs-toggle="modal" data-bs-target="#docEditModal"
                      data-id="<?= (int)$d['id'] ?>"
                      data-type="<?= (int)$d['document_type_id'] ?>"
                      data-title="<?= h($d['title'] ?? '') ?>"
                      data-expires="<?= h($d['expires_at'] ?? '') ?>"
                      data-filename="<?= h($fileLabel) ?>">✏️ Szerkesztés</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Jobb oszlop: profilkép -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body text-center">
        <?php if (!empty($emp['profile_image_path'])): ?>
          <a href="<?= h($emp['profile_image_path']) ?>" target="_blank" rel="noopener">
            <img src="<?= h($emp['profile_image_path']) ?>" class="img-fluid rounded mb-2">
          </a>
        <?php else: ?>
          <div class="text-muted py-3">Nincs profilkép.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

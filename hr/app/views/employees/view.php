<?php
// expects: $emp, $docs, $fields, $field_values
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Dolgozói karton</h3>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/employees">Vissza</a>
    <a class="btn btn-sm btn-outline-primary" href="/employees_pdf?id=<?= (int)($emp['id'] ?? 0) ?>" target="_blank">Karton PDF</a>
    <a class="btn btn-sm btn-primary" href="/employees_edit?id=<?= (int)$emp['id'] ?>">Szerkesztés</a>
    <?php if (($_SESSION['user']['role'] ?? '') === 'admin'): ?>
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

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-body">
        <h4 class="card-title mb-3"><?= h($emp['full_name'] ?? '') ?></h4>

        <div class="row g-2">
          <div class="col-md-6"><strong>Születési név:</strong> <?= h($emp['birth_name'] ?? '') ?></div>
          <div class="col-md-6"><strong>Anyja neve:</strong> <?= h($emp['mother_name'] ?? '') ?></div>
          <div class="col-md-6"><strong>Születési hely:</strong> <?= h($emp['birth_place'] ?? '') ?></div>
          <div class="col-md-6"><strong>Születési dátum:</strong> <?= h($emp['birth_date'] ?? '') ?></div>

          <div class="col-md-4"><strong>Adóazonosító:</strong> <?= h($emp['tax_id'] ?? '') ?></div>
          <div class="col-md-4"><strong>TAJ szám:</strong> <?= h($emp['taj'] ?? '') ?></div>
          <div class="col-md-4"><strong>Céges törzsszám:</strong> <?= h($emp['company_emp_no'] ?? '') ?></div>
          <div class="col-md-6"><strong>Divízió:</strong> <?= h($emp['division_name'] ?? '') ?></div>

          <div class="col-md-4"><strong>Irányítószám:</strong> <?= h($emp['addr_zip'] ?? '') ?></div>
          <div class="col-md-4"><strong>Település:</strong> <?= h($emp['addr_city'] ?? '') ?></div>
          <div class="col-md-4"><strong>Cím:</strong> <?= h($emp['addr_line'] ?? '') ?></div>

          <div class="col-md-6"><strong>Email:</strong> <?= h($emp['email'] ?? '') ?></div>
          <div class="col-md-6"><strong>Telefon:</strong> <?= h($emp['phone'] ?? '') ?></div>
        </div>
      </div>
    </div>

    <?php if (!empty($emp['notes'])): ?>
      <div class="border rounded p-3 mt-3">
        <h5 class="m-0 mb-2">Megjegyzés</h5>
        <div class="text-muted"><?= nl2br(h($emp['notes'])) ?></div>
      </div>
    <?php endif; ?>


    <?php
      // Extra fields (only shown if: has value + show_on_card = 1)
      $hasExtra = false;
      if (!empty($fields) && !empty($field_values)) {
        foreach ($fields as $f) {
          $fid = (int)$f['id'];
          $val = $field_values[$fid]['value'] ?? '';
          $show = (int)($field_values[$fid]['show'] ?? 1);
          if ($show === 1 && trim((string)$val) !== '') { $hasExtra = true; break; }
        }
      }
    ?>
    <?php if ($hasExtra): ?>
      <div class="border rounded p-3 mt-3">
        <h5 class="m-0 mb-2">Egyéb adatok</h5>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <tbody>
              <?php foreach (($fields ?? []) as $f): ?>
                <?php
                  $fid = (int)$f['id'];
                  $val = $field_values[$fid]['value'] ?? '';
                  $show = (int)($field_values[$fid]['show'] ?? 1);
                  if ($show !== 1) continue;
                  if (trim((string)$val) === '') continue;

                  $type = $f['field_type'] ?? 'text';
                  $disp = $val;

                  // for select/multiselect we store JSON array sometimes
                  if (in_array($type, ['multiselect'], true)) {
                    $arr = json_decode((string)$val, true);
                    if (is_array($arr)) $disp = implode(', ', $arr);
                  }
                ?>
                <tr>
                  <th style="width: 35%;" class="text-muted"><?= h($f['name'] ?? '') ?></th>
                  <td><?= nl2br(h((string)$disp)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>




    <div class="border rounded p-3 mt-3">
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

  </div>

  <div class="col-lg-4">
    <div class="card">
      <div class="card-body text-center">
        <?php if (!empty($emp['profile_image_path'])): ?>
          <a href="<?= h($emp['profile_image_path']) ?>" target="_blank" rel="noopener">
            <img src="<?= h($emp['profile_image_path']) ?>" class="img-fluid rounded mb-2">
          </a>
        <?php else: ?>
          <div class="text-muted">Nincs profilkép.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
// $rows, $total, $page, $perPage, $totalPages
// $f_user, $f_emp, $f_action, $f_date_from, $f_date_to

$actionLabels = [
  'employee_view' => ['text' => 'Megtekintés',    'badge' => 'bg-secondary'],
  'employee_edit' => ['text' => 'Szerkesztés',     'badge' => 'bg-info text-dark'],
  'field_update'  => ['text' => 'Mező módosítva',  'badge' => 'bg-primary'],
  'doc_upload'    => ['text' => 'Dok. feltöltés',  'badge' => 'bg-success'],
  'doc_delete'    => ['text' => 'Dok. törlés',     'badge' => 'bg-danger'],
];

// Aktuális szűrők lekérdezési stringgé (lapozó linkekhez)
$filterQs = http_build_query(array_filter([
  'f_user'     => $f_user,
  'f_emp'      => $f_emp,
  'f_action'   => $f_action,
  'f_date_from'=> $f_date_from,
  'f_date_to'  => $f_date_to,
]));
$pageBase = '/hr_audit_log?' . ($filterQs ? $filterQs . '&' : '');
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Audit napló</h4>
  <a href="/hr_permissions" class="btn btn-sm btn-outline-secondary">← Vissza</a>
</div>

<!-- Szűrő panel -->
<form method="GET" action="/hr_audit_log" class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">

      <div class="col-sm-6 col-md-3 col-lg-2">
        <label class="form-label small mb-1">Felhasználó</label>
        <input class="form-control form-control-sm" type="text" name="f_user"
               value="<?= h($f_user) ?>" placeholder="névtöredék">
      </div>

      <div class="col-sm-6 col-md-3 col-lg-2">
        <label class="form-label small mb-1">Dolgozó</label>
        <input class="form-control form-control-sm" type="text" name="f_emp"
               value="<?= h($f_emp) ?>" placeholder="névtöredék">
      </div>

      <div class="col-sm-6 col-md-2 col-lg-2">
        <label class="form-label small mb-1">Esemény</label>
        <select class="form-select form-select-sm" name="f_action">
          <option value="">— Mind —</option>
          <?php foreach ($actionLabels as $key => $lbl): ?>
            <option value="<?= h($key) ?>" <?= $f_action === $key ? 'selected' : '' ?>>
              <?= h($lbl['text']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-6 col-md-2 col-lg-2">
        <label class="form-label small mb-1">Dátumtól</label>
        <input class="form-control form-control-sm" type="date" name="f_date_from"
               value="<?= h($f_date_from) ?>">
      </div>

      <div class="col-sm-6 col-md-2 col-lg-2">
        <label class="form-label small mb-1">Dátumig</label>
        <input class="form-control form-control-sm" type="date" name="f_date_to"
               value="<?= h($f_date_to) ?>">
      </div>

      <div class="col-sm-6 col-md-2 col-lg-2 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Szűrés</button>
        <a href="/hr_audit_log" class="btn btn-outline-secondary btn-sm" title="Szűrők törlése">✕</a>
      </div>

    </div>
  </div>
</form>

<!-- Találatok fejléc -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <div class="text-muted small">
    <?= $total ?> találat
    <?php if ($totalPages > 1): ?>
      &nbsp;|&nbsp; <?= $page ?>. oldal / <?= $totalPages ?>
    <?php endif; ?>
  </div>
  <?php if ($total > 0): ?>
    <div class="text-muted small"><?= $perPage ?> / oldal</div>
  <?php endif; ?>
</div>

<!-- Táblázat -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="text-nowrap">Időpont</th>
            <th>Felhasználó</th>
            <th>Esemény</th>
            <th>Dolgozó</th>
            <th>Mező</th>
            <th>Régi érték</th>
            <th>Új érték</th>
            <th>Részlet</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="text-muted text-center py-3">Nincs naplóbejegyzés.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <?php $act = $actionLabels[$r['action']] ?? ['text' => h($r['action']), 'badge' => 'bg-light text-dark border']; ?>
            <tr>
              <td class="text-nowrap small"><?= h($r['created_at']) ?></td>
              <td class="small">
                <?= h($r['user_name']) ?>
                <div class="text-muted" style="font-size:.7rem">#<?= (int)$r['user_id'] ?></div>
              </td>
              <td><span class="badge <?= $act['badge'] ?>"><?= $act['text'] ?></span></td>
              <td class="small">
                <?php if (!empty($r['emp_name'])): ?>
                  <a href="/employees_view?id=<?= (int)$r['employee_id'] ?>"><?= h($r['emp_name']) ?></a>
                <?php else: ?>
                  <span class="text-muted">#<?= (int)$r['employee_id'] ?></span>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?= h($r['field_key'] ?? '') ?></td>
              <td class="small" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= h($r['old_value'] ?? '') ?>"><?= h($r['old_value'] ?? '') ?></td>
              <td class="small" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= h($r['new_value'] ?? '') ?>"><?= h($r['new_value'] ?? '') ?></td>
              <td class="small text-muted" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= h($r['detail'] ?? '') ?>"><?= h($r['detail'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Lapozó -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">

    <!-- Első / Előző -->
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= $pageBase ?>page=1">«</a>
    </li>
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= $pageBase ?>page=<?= $page - 1 ?>">‹</a>
    </li>

    <?php
      // Legfeljebb 9 oldalgomb, az aktuális körül
      $start = max(1, $page - 4);
      $end   = min($totalPages, $start + 8);
      $start = max(1, $end - 8);
    ?>
    <?php if ($start > 1): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <?php for ($p = $start; $p <= $end; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="<?= $pageBase ?>page=<?= $p ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
      <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>

    <!-- Következő / Utolsó -->
    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= $pageBase ?>page=<?= $page + 1 ?>">›</a>
    </li>
    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= $pageBase ?>page=<?= $totalPages ?>">»</a>
    </li>

  </ul>
</nav>
<?php endif; ?>

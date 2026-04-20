<?php
function mask_tax($tax) {
  if (!$tax) return '';
  $tax = (string)$tax;
  $len = mb_strlen($tax);
  if ($len <= 3) return $tax;
  return str_repeat('*', $len - 3) . mb_substr($tax, -3);
}

function sort_link($label, $key, $currentSort, $currentDir, $q = '', $showInactive = false) {
  $dir = 'asc';
  $arrow = '';
  if ($currentSort === $key) {
    if ($currentDir === 'asc') { $dir = 'desc'; $arrow = ' ↑'; }
    else { $dir = 'asc'; $arrow = ' ↓'; }
  }
  $params = ['sort' => $key, 'dir' => $dir];
  if ($q !== '') $params['q'] = $q;
  if ($showInactive) $params['show_inactive'] = 1;
  $href = '/employees?' . http_build_query($params);
  return '<a class="text-decoration-none" href="'.h($href).'">'.h($label).$arrow.'</a>';
}
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Dolgozók</h3>
  <div class="d-flex gap-2">
    <?php if (!empty($show_inactive)): ?>
      <a class="btn btn-sm btn-outline-secondary" href="/employees">Csak aktívak</a>
    <?php else: ?>
      <a class="btn btn-sm btn-outline-secondary" href="/employees?show_inactive=1">Inaktív is látható</a>
    <?php endif; ?>
    <a class="btn btn-sm btn-primary" href="/employees_create">+ Új dolgozó</a>
  </div>
</div>

<form class="row g-2 mb-3" method="get" action="/employees">
  <div class="col-md-6">
    <input class="form-control form-control-sm" type="text" name="q" value="<?= h($q ?? '') ?>" placeholder="Keresés (név, adóazonosító, törzsszám...)">
  </div>
  <div class="col-auto">
    <input type="hidden" name="sort" value="<?= h($sort ?? 'name') ?>">
    <input type="hidden" name="dir" value="<?= h($dir ?? 'desc') ?>">
    <?php if (!empty($show_inactive)): ?><input type="hidden" name="show_inactive" value="1"><?php endif; ?>
    <button class="btn btn-sm btn-outline-secondary" type="submit">Keresés</button>
    <?php if (!empty($q)): ?>
      <a class="btn btn-sm btn-outline-secondary" href="/employees?sort=<?= h($sort ?? 'name') ?>&dir=<?= h($dir ?? 'desc') ?><?= !empty($show_inactive) ? '&show_inactive=1' : '' ?>">Törlés</a>
    <?php endif; ?>
  </div>
</form>

<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="table-responsive">
<table class="table table-striped table-hover align-middle">
  <thead>
    <tr>
      <th><?= sort_link('Név', 'name', $sort ?? 'name', $dir ?? 'desc', $q ?? '', !empty($show_inactive)) ?></th>
      <th><?= sort_link('Divízió', 'division', $sort ?? 'name', $dir ?? 'desc', $q ?? '', !empty($show_inactive)) ?></th>
      <th>Adóazonosító</th>
      <th>Állapot</th>
      <th class="text-end">Műveletek</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($employees as $e): ?>
    <?php
      $rowClass = '';
      if ((int)($e['is_active'] ?? 1) === 0) {
        $rowClass = 'employee-row-inactive';
      } elseif ((int)($e['expired_doc_count'] ?? 0) > 0) {
        $rowClass = 'employee-row-expired';
      } elseif ((int)($e['expiring_doc_count'] ?? 0) > 0) {
        $rowClass = 'employee-row-expiring';
      }
    ?>
    <tr class="<?= h($rowClass) ?>">
      <td>
        <?= h($e['full_name']) ?>
        <?php if ((int)($e['expired_doc_count'] ?? 0) > 0): ?>
          <span class="badge bg-danger ms-2">lejárt doksi</span>
        <?php elseif ((int)($e['expiring_doc_count'] ?? 0) > 0): ?>
          <span class="badge bg-warning text-dark ms-2">lejáró doksi</span>
        <?php endif; ?>
      </td>
      <td><?= h($e['division_name'] ?? '') ?></td>
      <td><?= h(mask_tax($e['tax_id'] ?? '')) ?></td>
      <td>
        <?php if ((int)$e['is_active'] === 1): ?>
          <span class="badge bg-success">aktív</span>
        <?php else: ?>
          <span class="badge bg-danger">inaktív</span>
        <?php endif; ?>
      </td>
      <td class="text-end">
        <a class="btn btn-sm btn-outline-secondary" href="/employees_view?id=<?= (int)$e['id'] ?>">Karton</a>
        <a class="btn btn-sm btn-outline-primary" href="/employees_edit?id=<?= (int)$e['id'] ?>">Szerkesztés</a>
        <?php if (!empty($is_admin)): ?>
          <form method="post" action="/employees_toggle" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= ((int)$e['is_active'] === 1) ? 'Inaktivál' : 'Aktivál' ?></button>
          </form>
          <form method="post" action="/employees_delete" class="d-inline" onsubmit="return confirm('Biztosan véglegesen törlöd a dolgozót és a kapcsolódó adatait?');">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" type="submit">Törlés</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>

  <?php if (empty($employees)): ?>
    <tr><td colspan="5" class="text-muted">Nincs találat.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

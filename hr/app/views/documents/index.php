<?php
function sort_link($label, $key, $currentSort, $currentDir, $params) {
  $dir = 'asc';
  $arrow = '';
  if ($currentSort === $key) {
    if ($currentDir === 'asc') { $dir = 'desc'; $arrow = ' ↑'; }
    else { $dir = 'asc'; $arrow = ' ↓'; }
  }
  $p = $params;
  $p['sort'] = $key;
  $p['dir'] = $dir;
  $href = '/documents?' . http_build_query($p);
  return '<a class="text-decoration-none" href="'.h($href).'">'.h($label).$arrow.'</a>';
}

$baseParams = [
  'employee_id' => (int)($employee_id ?? 0),
  'division_id' => (int)($division_id ?? 0),
  'type_id' => (int)($type_id ?? 0),
  'q' => (string)($q ?? ''),
  'sort' => (string)($sort ?? 'created'),
  'dir' => (string)($dir ?? 'desc'),
];
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Dokumentumok</h3>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="/documents_upload<?= !empty($employee_id) ? ('?employee_id='.(int)$employee_id) : '' ?>">+ Feltöltés</a>
  </div>
</div>

<?php if (!empty($success)): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get" action="/documents">
  <div class="col-md-4">
    <input class="form-control form-control-sm" type="text" name="q" value="<?= h($baseParams['q']) ?>"
           placeholder="Keresés (név, divízió, típus, megnevezés...)">
  </div>

  <div class="col-md-3">
    <select class="form-select form-select-sm" name="division_id">
      <option value="0">Minden divízió</option>
      <?php foreach (($divisions ?? []) as $d): ?>
        <option value="<?= (int)$d['id'] ?>" <?= ((int)$baseParams['division_id'] === (int)$d['id']) ? 'selected' : '' ?>>
          <?= h($d['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-3">
    <select class="form-select form-select-sm" name="type_id">
      <option value="0">Minden típus</option>
      <?php foreach (($types ?? []) as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= ((int)$baseParams['type_id'] === (int)$t['id']) ? 'selected' : '' ?>>
          <?= h($t['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-2">
    <select class="form-select form-select-sm" name="employee_id">
      <option value="0">Minden dolgozó</option>
      <?php foreach (($employees ?? []) as $e): ?>
        <option value="<?= (int)$e['id'] ?>" <?= ((int)$baseParams['employee_id'] === (int)$e['id']) ? 'selected' : '' ?>>
          <?= h($e['full_name']) ?><?= ((int)$e['is_active']===1)?'':' (inaktív)' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <input type="hidden" name="sort" value="<?= h($baseParams['sort']) ?>">
  <input type="hidden" name="dir" value="<?= h($baseParams['dir']) ?>">

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-sm btn-outline-secondary" type="submit">Szűrés</button>
    <a class="btn btn-sm btn-outline-secondary" href="/documents">Törlés</a>
  </div>
</form>

<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
  <thead>
    <tr>
      <th><?= sort_link('Dolgozó', 'employee', $baseParams['sort'], $baseParams['dir'], $baseParams) ?></th>
      <th><?= sort_link('Divízió', 'division', $baseParams['sort'], $baseParams['dir'], $baseParams) ?></th>
      <th><?= sort_link('Típus', 'type', $baseParams['sort'], $baseParams['dir'], $baseParams) ?></th>
      <th><?= sort_link('Megnevezés', 'title', $baseParams['sort'], $baseParams['dir'], $baseParams) ?></th>
      <th>Fájl</th>
      <th><?= sort_link('Lejárat', 'expires', $baseParams['sort'], $baseParams['dir'], $baseParams) ?></th>
      <th><?= sort_link('Feltöltve', 'created', $baseParams['sort'], $baseParams['dir'], $baseParams) ?></th>
      <?php if (!empty($is_admin)): ?>
        <th style="width:1%;"></th>
      <?php endif; ?>
    </tr>
  </thead>
  <tbody>
    <?php
      $today = new DateTime('today');
      foreach (($docs ?? []) as $d):
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
        $title = trim((string)($d['title'] ?? ''));
    ?>
      <tr>
        <td><?= h($d['full_name'] ?? '') ?></td>
        <td><?= h($d['division_name'] ?? '') ?></td>
        <td><?= h($d['type_name'] ?? '') ?></td>
        <td><?= h($title) ?></td>
        <td>
          <a href="<?= h($d['file_path'] ?? '') ?>" target="_blank" rel="noopener">
            <?= h($fileLabel) ?>
          </a>
        </td>
        <td><?= $badge ?></td>
        <td><?= h($d['created_at'] ?? '') ?></td>
        <?php if (!empty($is_admin)): ?>
          <td class="text-end">
            <form method="post" action="/documents_delete" class="m-0" onsubmit="return confirm('Biztosan törlöd ezt a dokumentumot?');">
              <input type="hidden" name="_csrf" value="<?= h($csrf ?? '') ?>">
              <input type="hidden" name="id" value="<?= (int)($d['id'] ?? 0) ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Törlés</button>
            </form>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>

    <?php if (empty($docs)): ?>
      <tr><td colspan="<?= !empty($is_admin) ? 8 : 7 ?>" class="text-muted">Nincs dokumentum.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

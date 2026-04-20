<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Készletmozgás lista és export.
 * Minden bevét, kiadás, korrekció és azonosító-áthelyezés itt követhető vissza.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Készletmozgások';
$loggedIn = true;

$list = warehouse_stock_movements_search($config, $_GET);
$filters = $list['filters'];
$rows = $list['rows'];
$warehouses = warehouse_accessible_warehouses($config, false);
$materials = warehouse_materials_all($config, false);
$movementTypes = warehouse_stock_movement_types();

// A mozgáslista képernyőn és exportban ugyanarra a keresésre épül,
// exportnál csak a per_page limitet emeljük meg.
if ((string)($_GET['export'] ?? '') === 'csv') {
    $exportList = warehouse_stock_movements_search($config, array_merge($_GET, ['page' => 1, 'per_page' => 10000]));
    $exportRows = [];
    foreach ($exportList['rows'] as $row) {
        $exportRows[] = [
            'Dátum' => (string)($row['created_at'] ?? ''),
            'Raktár' => (string)($row['warehouse_name'] ?? ''),
            'Raktárkód' => (string)($row['warehouse_code'] ?? ''),
            'Cikkszám' => (string)($row['sku'] ?? ''),
            'Megnevezés' => (string)($row['material_name'] ?? ''),
            'Mozgástípus' => warehouse_movement_type_label((string)($row['movement_type'] ?? '')),
            'Változás' => warehouse_format_quantity($row['quantity_change'] ?? 0),
            'Előtte' => warehouse_format_quantity($row['quantity_before'] ?? 0),
            'Utána' => warehouse_format_quantity($row['quantity_after'] ?? 0),
            'Mértékegység' => (string)($row['unit'] ?? ''),
            'Hivatkozás' => (string)($row['reference_no'] ?? ''),
            'Felhasználó' => (string)($row['performed_name'] ?? ''),
            'Megjegyzés' => (string)($row['note'] ?? ''),
        ];
    }
    warehouse_csv_download('keszletmozgasok_' . date('Ymd_His') . '.csv', ['Dátum', 'Raktár', 'Raktárkód', 'Cikkszám', 'Megnevezés', 'Mozgástípus', 'Változás', 'Előtte', 'Utána', 'Mértékegység', 'Hivatkozás', 'Felhasználó', 'Megjegyzés'], $exportRows);
}

$queryBase = [
    'warehouse_id' => $filters['warehouse_id'],
    'material_id' => $filters['material_id'],
    'movement_type' => $filters['movement_type'],
    'date_from' => $filters['date_from'],
    'date_to' => $filters['date_to'],
    'q' => $filters['q'],
    'sort' => $list['sort'],
    'dir' => $list['dir'],
    'page' => $list['page'],
];
$buildQuery = static function (array $overrides = []) use ($queryBase): string {
    $params = array_merge($queryBase, $overrides);
    return http_build_query(array_filter($params, static function ($value): bool {
        return !($value === '' || $value === null || $value === 0 || $value === '0');
    }));
};
$sortLink = static function (string $column) use ($list, $buildQuery): string {
    $nextDir = 'asc';
    if ($list['sort'] === $column && $list['dir'] === 'asc') {
        $nextDir = 'desc';
    }
    return '/stock_movements.php?' . $buildQuery([
        'sort' => $column,
        'dir' => $nextDir,
        'page' => 1,
    ]);
};
$sortIcon = static function (string $column) use ($list): string {
    if ($list['sort'] !== $column) {
        return '↕';
    }
    return $list['dir'] === 'asc' ? '↑' : '↓';
};

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0">Készletmozgások</h1>
    <div class="text-secondary small">Oldalanként 50 sor, kereshető, szűrhető és kattintható oszlopfejléccel rendezhető nézet.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/stock.php">Készlet</a>
    <?php if (warehouse_module_admin($config)): ?>
      <a class="btn btn-sm btn-outline-secondary" href="/audit_log.php">Admin napló</a>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <form method="get" class="row g-3">
      <div class="col-12 col-lg-3">
        <label class="form-label">Raktár</label>
        <select class="form-select" name="warehouse_id">
          <option value="0">— mind —</option>
          <?php foreach ($warehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= $filters['warehouse_id'] === (int)$w['id'] ? 'selected' : '' ?>><?= h((string)$w['name']) ?> (<?= h((string)$w['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label">Anyag</label>
        <select class="form-select" name="material_id">
          <option value="0">— mind —</option>
          <?php foreach ($materials as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= $filters['material_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= h((string)$m['name']) ?> [<?= h((string)$m['sku']) ?>]</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-2">
        <label class="form-label">Mozgástípus</label>
        <select class="form-select" name="movement_type">
          <option value="">— mind —</option>
          <?php foreach ($movementTypes as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $filters['movement_type'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-lg-1">
        <label class="form-label">Tól</label>
        <input class="form-control" type="date" name="date_from" value="<?= h($filters['date_from']) ?>">
      </div>
      <div class="col-6 col-lg-1">
        <label class="form-label">Ig</label>
        <input class="form-control" type="date" name="date_to" value="<?= h($filters['date_to']) ?>">
      </div>
      <div class="col-12 col-lg-2">
        <label class="form-label">Keresés</label>
        <input class="form-control" name="q" value="<?= h($filters['q']) ?>" placeholder="anyag, cikkszám, megjegyzés...">
      </div>
      <div class="col-12 d-flex justify-content-between">
        <a class="btn btn-outline-secondary" href="/stock_movements.php">Szűrők törlése</a>
        <button class="btn btn-primary" type="submit">Szűrés</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
      <div>
        <h2 class="h6 mb-1">Mozgás lista</h2>
        <div class="text-secondary small">
          Összesen <?= (int)$list['total'] ?> sor, oldalanként <?= (int)$list['per_page'] ?>.
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="small text-secondary">
          <?php if ($list['total'] > 0): ?>
            Megjelenítve: <?= (int)$list['offset'] + 1 ?>–<?= (int)min($list['offset'] + count($rows), $list['total']) ?>
          <?php else: ?>
            Nincs találat.
          <?php endif; ?>
        </div>
        <a class="btn btn-sm btn-outline-success js-csv-export" data-export-label="CSV készül…" href="/stock_movements.php?<?= h($buildQuery(['export' => 'csv', 'page' => 1])) ?>">CSV export</a>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle table-sm">
        <thead>
          <tr>
            <th><a class="link-dark text-decoration-none" href="<?= h($sortLink('date')) ?>">Dátum <?= h($sortIcon('date')) ?></a></th>
            <th><a class="link-dark text-decoration-none" href="<?= h($sortLink('warehouse')) ?>">Raktár <?= h($sortIcon('warehouse')) ?></a></th>
            <th><a class="link-dark text-decoration-none" href="<?= h($sortLink('material')) ?>">Anyag <?= h($sortIcon('material')) ?></a></th>
            <th><a class="link-dark text-decoration-none" href="<?= h($sortLink('movement_type')) ?>">Típus <?= h($sortIcon('movement_type')) ?></a></th>
            <th class="text-end">Változás</th>
            <th class="text-end">Előtte</th>
            <th class="text-end">Utána</th>
            <th>Felhasználó</th>
            <th>Megjegyzés</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <div class="fw-bold"><?= h((string)$row['created_at']) ?></div>
              <?php if (!empty($row['reference_no'])): ?><div class="text-secondary small">Ref: <?= h((string)$row['reference_no']) ?></div><?php endif; ?>
            </td>
            <td>
              <div class="fw-bold"><?= h((string)$row['warehouse_name']) ?></div>
              <div class="text-secondary small"><?= h((string)$row['warehouse_code']) ?></div>
            </td>
            <td>
              <div><code><?= h((string)$row['sku']) ?></code></div>
              <div class="fw-bold"><?= h((string)$row['material_name']) ?></div>
            </td>
            <td><span class="badge bg-secondary"><?= h(warehouse_movement_type_label((string)$row['movement_type'])) ?></span></td>
            <td class="text-end fw-bold <?= ((float)$row['quantity_change'] >= 0) ? 'text-success' : 'text-danger' ?>"><?= h(warehouse_format_quantity($row['quantity_change'])) ?></td>
            <td class="text-end"><?= h(warehouse_format_quantity($row['quantity_before'])) ?></td>
            <td class="text-end"><?= h(warehouse_format_quantity($row['quantity_after'])) ?> <?= h((string)($row['unit'] ?? '')) ?></td>
            <td>
              <div class="fw-bold"><?= h((string)$row['performed_name']) ?></div>
              <?php if (!empty($row['performed_username'])): ?><div class="text-secondary small"><?= h((string)$row['performed_username']) ?></div><?php endif; ?>
            </td>
            <td class="small"><?= !empty($row['note']) ? nl2br(h((string)$row['note'])) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
          <tr><td colspan="9" class="text-secondary">Nincs mozgás a megadott szűrőkre.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($list['pages'] > 1): ?>
    <nav aria-label="Mozgás lapozás" class="mt-3">
      <ul class="pagination pagination-sm mb-0 flex-wrap">
        <?php $prevPage = max(1, $list['page'] - 1); ?>
        <li class="page-item <?= $list['page'] <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="/stock_movements.php?<?= h($buildQuery(['page' => $prevPage])) ?>">«</a>
        </li>
        <?php
          $startPage = max(1, $list['page'] - 2);
          $endPage = min($list['pages'], $list['page'] + 2);
          for ($p = $startPage; $p <= $endPage; $p++):
        ?>
        <li class="page-item <?= $p === $list['page'] ? 'active' : '' ?>">
          <a class="page-link" href="/stock_movements.php?<?= h($buildQuery(['page' => $p])) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
        <?php $nextPage = min($list['pages'], $list['page'] + 1); ?>
        <li class="page-item <?= $list['page'] >= $list['pages'] ? 'disabled' : '' ?>">
          <a class="page-link" href="/stock_movements.php?<?= h($buildQuery(['page' => $nextPage])) ?>">»</a>
        </li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.js-csv-export').forEach(function (link) {
    link.addEventListener('click', function (event) {
      if (link.dataset.exporting === '1') {
        event.preventDefault();
        return;
      }
      event.preventDefault();
      link.dataset.exporting = '1';
      link.dataset.originalText = link.textContent;
      link.classList.add('disabled');
      link.setAttribute('aria-disabled', 'true');
      link.textContent = link.dataset.exportLabel || 'CSV készül…';
      setTimeout(function () {
        window.location.href = link.href;
      }, 60);
      setTimeout(function () {
        link.dataset.exporting = '0';
        link.classList.remove('disabled');
        link.removeAttribute('aria-disabled');
        link.textContent = link.dataset.originalText || 'CSV export';
      }, 2500);
    });
  });
});
</script>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

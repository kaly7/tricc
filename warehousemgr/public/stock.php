<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Raktárkészlet összesítő oldal.
 * Készletbevét, korrekció, szűrés, azonosítós találatok és CSV export kezelése.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/pdf_stock_report.php';

$title = 'Készlet';
$loggedIn = true;

$allAccessibleWarehouses = warehouse_accessible_warehouses($config, true);
$manageableWarehouses = warehouse_manageable_warehouses($config, true);
$activeMaterials = warehouse_material_select_options($config, true);
$canManageAny = count($manageableWarehouses) > 0;

// A készletoldal kétféle közvetlen műveletet kezel: bevételezés és kézi korrekció.
// A tényleges üzleti ellenőrzéseket a helper függvények végzik.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'stock_receipt') {
            if (!$canManageAny) {
                throw new RuntimeException('Nincs olyan raktárad, ahol készletet módosíthatsz.');
            }
            warehouse_stock_apply_movement(
                $config,
                (int)($_POST['warehouse_id'] ?? 0),
                (int)($_POST['material_id'] ?? 0),
                'receipt',
                $_POST['quantity'] ?? '',
                (string)($_POST['reference_no'] ?? ''),
                (string)($_POST['note'] ?? '')
            );
            flash_set('msg', 'Bevételezés rögzítve.');
            header('Location: /stock.php');
            exit;
        }

        if ($action === 'stock_adjustment') {
            if (!$canManageAny) {
                throw new RuntimeException('Nincs olyan raktárad, ahol készletet módosíthatsz.');
            }
            $mode = (string)($_POST['adjustment_mode'] ?? 'adjustment_set');
            if (!in_array($mode, ['adjustment_set', 'adjustment_add', 'adjustment_subtract'], true)) {
                throw new RuntimeException('Érvénytelen korrekciós mód.');
            }
            warehouse_stock_apply_movement(
                $config,
                (int)($_POST['warehouse_id'] ?? 0),
                (int)($_POST['material_id'] ?? 0),
                $mode,
                $_POST['quantity'] ?? '',
                (string)($_POST['reference_no'] ?? ''),
                (string)($_POST['note'] ?? '')
            );
            flash_set('msg', 'Készletkorrekció rögzítve.');
            header('Location: /stock.php');
            exit;
        }
    } catch (Throwable $e) {
        flash_set('err', $e->getMessage());
        header('Location: /stock.php');
        exit;
    }
}

$msg = flash_get('msg');
$err = flash_get('err');
// A képernyőn és CSV exportban is ugyanazokat a szűrőket használjuk.
$filters = warehouse_stock_filter_values($_GET);
$rows = warehouse_stock_summary($config, $filters);
$identifierFeatureReady = warehouse_material_identifier_feature_ready($config);
$archiveFeatureReady = warehouse_material_archive_feature_ready($config);
$categoryOptions = warehouse_material_category_options($config, true);
$searchQuery = trim((string)($filters['q'] ?? ''));
$identifierPageSize = isset($_GET['id_page_size']) ? max(1, min(200, (int)$_GET['id_page_size'])) : 25;
$stockSort = (string)($_GET['sort'] ?? 'warehouse');
if (!in_array($stockSort, ['warehouse', 'material', 'category'], true)) {
    $stockSort = 'warehouse';
}
$stockDir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$stockPageSize = isset($_GET['page_size']) ? max(10, min(200, (int)$_GET['page_size'])) : 50;
$stockPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$stockSortCompare = static function (array $a, array $b) use ($stockSort, $stockDir): int {
    $cmp = 0;
    switch ($stockSort) {
        case 'material':
            $cmp = strnatcasecmp(trim((string)($a['sku'] ?? '')) . ' ' . trim((string)($a['material_name'] ?? '')), trim((string)($b['sku'] ?? '')) . ' ' . trim((string)($b['material_name'] ?? '')));
            if ($cmp === 0) {
                $cmp = strnatcasecmp((string)($a['warehouse_name'] ?? ''), (string)($b['warehouse_name'] ?? ''));
            }
            break;
        case 'category':
            $cmp = strnatcasecmp((string)($a['category_name'] ?? ''), (string)($b['category_name'] ?? ''));
            if ($cmp === 0) {
                $cmp = strnatcasecmp(trim((string)($a['material_name'] ?? '')), trim((string)($b['material_name'] ?? '')));
            }
            if ($cmp === 0) {
                $cmp = strnatcasecmp((string)($a['sku'] ?? ''), (string)($b['sku'] ?? ''));
            }
            break;
        case 'warehouse':
        default:
            $cmp = strnatcasecmp((string)($a['warehouse_name'] ?? ''), (string)($b['warehouse_name'] ?? ''));
            if ($cmp === 0) {
                $cmp = strnatcasecmp(trim((string)($a['material_name'] ?? '')), trim((string)($b['material_name'] ?? '')));
            }
            if ($cmp === 0) {
                $cmp = strnatcasecmp((string)($a['sku'] ?? ''), (string)($b['sku'] ?? ''));
            }
            break;
    }

    if ($cmp === 0) {
        $cmp = ((int)($a['warehouse_id'] ?? 0) <=> (int)($b['warehouse_id'] ?? 0));
    }
    if ($cmp === 0) {
        $cmp = ((int)($a['material_id'] ?? 0) <=> (int)($b['material_id'] ?? 0));
    }

    return $stockDir === 'desc' ? -$cmp : $cmp;
};

if ($rows !== []) {
    usort($rows, $stockSortCompare);
}

$stockIdentifierMap = $identifierFeatureReady ? warehouse_stock_identifier_map($config, $rows, (int)($filters['include_archived'] ?? 0) === 1) : [];

$queryBase = [
    'warehouse_id' => $filters['warehouse_id'],
    'category_name' => $filters['category_name'] ?? '',
    'q' => $filters['q'],
    'low_only' => $filters['low_only'],
    'include_archived' => (int)($filters['include_archived'] ?? 0) === 1 ? 1 : null,
    'include_zero' => (int)($filters['include_zero'] ?? 0) === 1 ? 1 : null,
    'sort' => $stockSort !== 'warehouse' ? $stockSort : null,
    'dir' => $stockDir !== 'asc' ? $stockDir : null,
    'page_size' => $stockPageSize !== 50 ? $stockPageSize : null,
    'id_page_size' => $identifierPageSize !== 25 ? $identifierPageSize : null,
    'page' => $stockPage > 1 ? $stockPage : null,
];
$buildQuery = static function (array $overrides = []) use ($queryBase): string {
    $params = array_merge($queryBase, $overrides);
    return http_build_query(array_filter($params, static function ($value): bool {
        return !($value === '' || $value === null || $value === 0 || $value === '0');
    }));
};
$buildStockSortUrl = static function (string $column) use ($buildQuery, $stockSort, $stockDir): string {
    $nextDir = ($stockSort === $column && $stockDir === 'asc') ? 'desc' : 'asc';
    return '/stock.php?' . $buildQuery(['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
};
$stockSortIndicator = static function (string $column) use ($stockSort, $stockDir): string {
    if ($stockSort !== $column) {
        return '';
    }
    return $stockDir === 'desc' ? ' ↓' : ' ↑';
};

// Exportnál a teljes, szűrt lista kerül kiírásra, beleértve az azonosítókat is.
$exportMode = (string)($_GET['export'] ?? '');
if ($exportMode === 'pdf' || $exportMode === 'pdf_identifiers') {
    try {
        $detailedPdf = $exportMode === 'pdf_identifiers';
        $pdf = warehouse_generate_stock_report_pdf($config, $filters, $rows, $allAccessibleWarehouses, $stockIdentifierMap, $detailedPdf);
        $abs = (string)($pdf['abs'] ?? '');
        $defaultFilename = $detailedPdf ? ('raktarkeszlet_azonositokkal_' . date('Ymd_His') . '.pdf') : ('raktarkeszlet_' . date('Ymd_His') . '.pdf');
        $filename = (string)($pdf['filename'] ?? $defaultFilename);
        if ($abs === '' || !is_file($abs)) {
            throw new RuntimeException('A PDF fájl nem jött létre.');
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($abs);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'PDF generálási hiba: ' . h($e->getMessage());
        exit;
    }
}

if ((string)($_GET['export'] ?? '') === 'csv') {
    $exportRows = [];
    foreach ($rows as $row) {
        $minimum = $row['minimum_stock'] !== null ? (float)$row['minimum_stock'] : null;
        $qty = (float)($row['quantity'] ?? 0);
        $isLow = $minimum !== null && $qty <= $minimum;
        $status = ((int)($row['warehouse_is_active'] ?? 0) !== 1)
            ? 'Raktár inaktív'
            : ($isLow ? 'Minimum alatt' : 'Rendben');
        $identifierKey = ((int)($row['warehouse_id'] ?? 0)) . ':' . ((int)($row['material_id'] ?? 0));
        $identifierRows = $stockIdentifierMap[$identifierKey] ?? [];
        $identifierValues = [];
        foreach ($identifierRows as $identifierRow) {
            $value = trim(warehouse_material_identifier_display_value((array)$identifierRow));
            if ($value !== '') {
                $identifierValues[] = $value;
            }
        }

        $baseRow = [
            'Raktár' => (string)($row['warehouse_name'] ?? ''),
            'Raktárkód' => (string)($row['warehouse_code'] ?? ''),
            'Cikkszám' => (string)($row['sku'] ?? ''),
            'Megnevezés' => (string)($row['material_name'] ?? ''),
            'Kategória' => (string)($row['category_name'] ?? ''),
            'Mértékegység' => (string)($row['unit'] ?? ''),
            'Készlet' => warehouse_format_quantity($row['quantity'] ?? 0),
            'Minimum készlet' => $row['minimum_stock'] !== null ? warehouse_format_quantity($row['minimum_stock']) : '',
            'Állapot' => $status,
            'Archivált anyag' => ($archiveFeatureReady && (int)($row['material_is_archived'] ?? 0) === 1) ? 'Igen' : 'Nem',
            'Egyedi azonosítós' => ((int)($row['is_identified'] ?? 0) === 1) ? 'Igen' : 'Nem',
            'Azonosító típusa' => (string)($row['identifier_label'] ?? ''),
            'Rögzített azonosítók' => ((int)($row['is_identified'] ?? 0) === 1) ? (string)count($identifierValues) : '',
            'Frissítés' => (string)($row['updated_at'] ?? ''),
        ];

        if ((int)($row['is_identified'] ?? 0) === 1) {
            if ($identifierValues) {
                foreach ($identifierValues as $identifierValue) {
                    $exportRows[] = $baseRow + ['Azonosító' => $identifierValue];
                }
            } else {
                $exportRows[] = $baseRow + ['Azonosító' => ''];
            }
            continue;
        }

        $exportRows[] = $baseRow + ['Azonosító' => ''];
    }
    warehouse_csv_download('raktarkeszlet_' . date('Ymd_His') . '.csv', ['Raktár', 'Raktárkód', 'Cikkszám', 'Megnevezés', 'Kategória', 'Mértékegység', 'Készlet', 'Minimum készlet', 'Állapot', 'Archivált anyag', 'Egyedi azonosítós', 'Azonosító típusa', 'Rögzített azonosítók', 'Azonosító', 'Frissítés'], $exportRows);
}

$stockTotalRows = count($rows);
$stockTotalPages = max(1, (int)ceil($stockTotalRows / $stockPageSize));
if ($stockPage > $stockTotalPages) {
    $stockPage = $stockTotalPages;
}
$stockOffset = ($stockPage - 1) * $stockPageSize;
$displayRows = array_slice($rows, $stockOffset, $stockPageSize);
$displayFrom = $stockTotalRows > 0 ? $stockOffset + 1 : 0;
$displayTo = min($stockOffset + $stockPageSize, $stockTotalRows);

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0">Raktárkészlet</h1>
    <div class="text-secondary small">Raktárankénti készletlista, bevételezés és készletkorrekció egy felületen.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/stock_movements.php">Mozgásnapló</a>
    <?php if (warehouse_module_admin($config)): ?>
      <a class="btn btn-sm btn-outline-secondary" href="/audit_log.php">Admin napló</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<?php if (!$allAccessibleWarehouses): ?>
<div class="alert alert-warning">Ehhez a modulhoz van hozzáférésed, de egyetlen raktárhoz sincs helyi jogosultságod. Az admin tud hozzárendelni raktárat.</div>
<?php endif; ?>

<?php if ($canManageAny): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="fw-semibold">Bevételezés</div>
        <div class="text-secondary small">Új készlet bevételezése egy kezelhető raktárba.</div>
      </div>
      <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#stock-receipt-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
        <span class="wm-panel-toggle-label">Megnyitás</span>
      </button>
    </div>
    <div id="stock-receipt-panel" class="collapse" data-wm-panel="1" data-panel-key="stock-receipt">
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="stock_receipt">
          <div class="col-12 col-lg-4">
            <label class="form-label">Raktár</label>
            <select class="form-select" name="warehouse_id" required>
              <option value="">— válassz —</option>
              <?php foreach ($manageableWarehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>"><?= h((string)$w['name']) ?> (<?= h((string)$w['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label">Anyag</label>
            <select class="form-select" name="material_id" required>
              <option value="">— válassz —</option>
              <?php foreach ($activeMaterials as $m): ?>
                <option value="<?= (int)$m['id'] ?>"><?= h((string)$m['name']) ?> [<?= h((string)$m['sku']) ?>]</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6 col-lg-2">
            <label class="form-label">Mennyiség</label>
            <input class="form-control" name="quantity" required placeholder="pl. 10 vagy 2,5">
          </div>
          <div class="col-12 col-md-6 col-lg-2">
            <label class="form-label">Hivatkozás</label>
            <input class="form-control" name="reference_no" placeholder="szállítólevél / bizonylat">
          </div>
          <div class="col-12">
            <label class="form-label">Megjegyzés</label>
            <textarea class="form-control" name="note" rows="3"></textarea>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary" type="submit">Bevételezés rögzítése</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="fw-semibold">Készletkorrekció</div>
        <div class="text-secondary small">Meglévő készlet beállítása, növelése vagy csökkentése.</div>
      </div>
      <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#stock-adjustment-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
        <span class="wm-panel-toggle-label">Megnyitás</span>
      </button>
    </div>
    <div id="stock-adjustment-panel" class="collapse" data-wm-panel="1" data-panel-key="stock-adjustment">
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="stock_adjustment">
          <div class="col-12 col-lg-4">
            <label class="form-label">Raktár</label>
            <select class="form-select" name="warehouse_id" required>
              <option value="">— válassz —</option>
              <?php foreach ($manageableWarehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>"><?= h((string)$w['name']) ?> (<?= h((string)$w['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label">Anyag</label>
            <select class="form-select" name="material_id" required>
              <option value="">— válassz —</option>
              <?php foreach ($activeMaterials as $m): ?>
                <option value="<?= (int)$m['id'] ?>"><?= h((string)$m['name']) ?> [<?= h((string)$m['sku']) ?>]</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6 col-lg-2">
            <label class="form-label">Mód</label>
            <select class="form-select" name="adjustment_mode">
              <option value="adjustment_set">Készlet beállítása</option>
              <option value="adjustment_add">Készlet növelése</option>
              <option value="adjustment_subtract">Készlet csökkentése</option>
            </select>
          </div>
          <div class="col-12 col-md-6 col-lg-2">
            <label class="form-label">Mennyiség</label>
            <input class="form-control" name="quantity" required placeholder="pl. 1 vagy 0,5">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Hivatkozás</label>
            <input class="form-control" name="reference_no" placeholder="leltár / korrekció ok">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Megjegyzés</label>
            <input class="form-control" name="note">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-outline-primary" type="submit">Korrekció mentése</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <form method="get" class="row g-3 mb-3">
      <div class="col-12 col-lg-4">
        <label class="form-label">Raktár</label>
        <select class="form-select" name="warehouse_id">
          <option value="0">— mind —</option>
          <?php foreach ($allAccessibleWarehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= $filters['warehouse_id'] === (int)$w['id'] ? 'selected' : '' ?>><?= h((string)$w['name']) ?> (<?= h((string)$w['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label">Kategória</label>
        <select class="form-select" name="category_name">
          <option value="">— mind —</option>
          <?php foreach ($categoryOptions as $categoryOption): ?>
            <option value="<?= h($categoryOption) ?>" <?= (($filters['category_name'] ?? '') === $categoryOption) ? 'selected' : '' ?>><?= h($categoryOption) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label">Keresés</label>
        <input class="form-control" name="q" value="<?= h($filters['q']) ?>" placeholder="cikkszám, anyag, kategória, raktár, azonosító...">
      </div>
      <div class="col-12 col-lg-2 d-flex align-items-end">
        <div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="low_only" name="low_only" value="1" <?= ((int)$filters['low_only'] === 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="low_only">Csak minimum alatt</label>
          </div>
          <div class="form-check mb-2">
            <input type="hidden" name="include_zero" value="0">
            <input class="form-check-input" type="checkbox" id="include_zero" name="include_zero" value="1" <?= ((int)($filters['include_zero'] ?? 0) === 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="include_zero">0 készlet is</label>
          </div>
          <?php if ($archiveFeatureReady): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="include_archived" name="include_archived" value="1" <?= ((int)($filters['include_archived'] ?? 0) === 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="include_archived">Archív is</label>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-12 col-lg-1 d-flex align-items-end justify-content-end">
        <button class="btn btn-primary w-100" type="submit">Szűrés</button>
      </div>
    </form>

    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
      <h2 class="h6 mb-0">Készletlista</h2>
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <div class="text-secondary small"><?= $displayFrom ?>–<?= $displayTo ?>. tétel / <?= $stockTotalRows ?> összesen · oldal <?= $stockPage ?> / <?= $stockTotalPages ?><?php if ((int)($filters['include_zero'] ?? 0) === 1): ?> · 0 készlettel együtt<?php endif; ?><?php if ($archiveFeatureReady && (int)($filters['include_archived'] ?? 0) === 1): ?> · archívval együtt<?php endif; ?></div>
        <a class="btn btn-sm btn-outline-success js-csv-export" data-export-label="CSV készül…" href="/stock.php?<?= h($buildQuery(['export' => 'csv', 'page' => null])) ?>">CSV export</a>
        <a class="btn btn-sm btn-outline-danger" target="_blank" href="/stock.php?<?= h($buildQuery(['export' => 'pdf', 'page' => null])) ?>">PDF / Nyomtatás</a>
        <a class="btn btn-sm btn-outline-danger" target="_blank" href="/stock.php?<?= h($buildQuery(['export' => 'pdf_identifiers', 'page' => null])) ?>">PDF + azonosítók</a>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th><a class="link-dark text-decoration-none" href="<?= h($buildStockSortUrl('warehouse')) ?>">Raktár<?= h($stockSortIndicator('warehouse')) ?></a></th>
            <th><a class="link-dark text-decoration-none" href="<?= h($buildStockSortUrl('material')) ?>">Cikkszám / Anyag<?= h($stockSortIndicator('material')) ?></a></th>
            <th><a class="link-dark text-decoration-none" href="<?= h($buildStockSortUrl('category')) ?>">Kategória<?= h($stockSortIndicator('category')) ?></a></th>
            <th class="text-end">Készlet</th>
            <th class="text-end">Minimum</th>
            <th>Állapot</th>
            <th>Frissítés</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($displayRows as $row): ?>
            <?php
              $minimum = $row['minimum_stock'] !== null ? (float)$row['minimum_stock'] : null;
              $qty = (float)$row['quantity'];
              $isLow = $minimum !== null && $qty <= $minimum;
              $isIdentified = $identifierFeatureReady && (int)($row['is_identified'] ?? 0) === 1;
              $identifierKey = ((int)$row['warehouse_id']) . ':' . ((int)$row['material_id']);
              $identifierRows = $stockIdentifierMap[$identifierKey] ?? [];
              $identifierCollapseId = 'stock-identifiers-' . (int)$row['warehouse_id'] . '-' . (int)$row['material_id'];
              $identifierBrowserId = $identifierCollapseId . '-browser';
              $identifierLabel = $isIdentified ? warehouse_material_identifier_value_label($row) : 'Azonosító';
              $preparedIdentifierRows = [];
              $matchedIdentifierValues = [];
              if ($isIdentified) {
                  foreach ($identifierRows as $identifierRow) {
                      $identifierDisplayValue = trim(warehouse_material_identifier_display_value((array)$identifierRow));
                      $identifierRowMatch = $searchQuery !== '' && $identifierDisplayValue !== '' && stripos($identifierDisplayValue, $searchQuery) !== false;
                      if ($identifierRowMatch) {
                          $matchedIdentifierValues[] = $identifierDisplayValue;
                      }
                      $preparedIdentifierRows[] = [
                          'display' => $identifierDisplayValue,
                          'note' => (string)($identifierRow['note'] ?? ''),
                          'created_at' => (string)($identifierRow['created_at'] ?? ''),
                          'is_match' => $identifierRowMatch,
                      ];
                  }
                  if ($searchQuery !== '' && $preparedIdentifierRows) {
                      usort($preparedIdentifierRows, static function (array $a, array $b): int {
                          $matchCompare = (int)$b['is_match'] <=> (int)$a['is_match'];
                          if ($matchCompare !== 0) {
                              return $matchCompare;
                          }
                          return strcmp((string)$a['display'], (string)$b['display']);
                      });
                  }
                  $matchedIdentifierValues = array_values(array_unique($matchedIdentifierValues));
              }
              $matchedIdentifierCount = count($matchedIdentifierValues);
              $hasIdentifierMatch = $matchedIdentifierCount > 0;
            ?>
            <tr<?= $hasIdentifierMatch ? ' class="table-warning"' : '' ?>>
              <td>
                <div class="fw-bold"><?= h((string)$row['warehouse_name']) ?></div>
                <div class="text-secondary small"><?= h((string)$row['warehouse_code']) ?></div>
              </td>
              <td>
                <div><code><?= h((string)$row['sku']) ?></code></div>
                <div class="fw-bold"><?= h((string)$row['material_name']) ?></div>
                <?php if ((int)$row['material_is_active'] !== 1): ?><div class="text-danger small">Inaktív anyag</div><?php endif; ?>
                <?php if ($archiveFeatureReady && (int)($row['material_is_archived'] ?? 0) === 1): ?><div class="small mt-1"><span class="badge bg-warning text-dark">Archivált anyag</span></div><?php endif; ?>
                <?php if ($isIdentified): ?>
                  <div class="small text-primary mt-1">Egyedi azonosítós<?= !empty($row['identifier_label']) ? ' · ' . h((string)$row['identifier_label']) : '' ?></div>
                  <div class="small text-secondary">Rögzített azonosítók: <?= (int)count($identifierRows) ?></div>
                  <?php if ($hasIdentifierMatch): ?>
                    <div class="small mt-1"><span class="badge bg-warning text-dark">Azonosító találat</span></div>
                    <div class="small text-dark mt-1">Találat: <?= h(implode(', ', $matchedIdentifierValues)) ?></div>
                  <?php endif; ?>
                  <button class="btn btn-sm <?= $hasIdentifierMatch ? 'btn-warning text-dark' : 'btn-outline-secondary' ?> mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($identifierCollapseId) ?>" aria-expanded="false" aria-controls="<?= h($identifierCollapseId) ?>">
                    <?= h($identifierLabel) ?>k megjelenítése
                  </button>
                <?php endif; ?>
              </td>
              <td><?= h((string)($row['category_name'] ?? '—')) ?></td>
              <td class="text-end fw-bold"><?= h(warehouse_format_quantity($row['quantity'])) ?> <?= h((string)($row['unit'] ?? '')) ?></td>
              <td class="text-end"><?= $row['minimum_stock'] !== null ? h(warehouse_format_quantity($row['minimum_stock'])) : '—' ?></td>
              <td>
                <?php if ((int)$row['warehouse_is_active'] !== 1): ?>
                  <span class="badge bg-secondary">Raktár inaktív</span>
                <?php elseif ($isLow): ?>
                  <span class="badge bg-warning text-dark">Minimum alatt</span>
                <?php else: ?>
                  <span class="badge bg-success">Rendben</span>
                <?php endif; ?>
              </td>
              <td>
                <div><?= h((string)$row['updated_at']) ?></div>
              </td>
            </tr>
            <?php if ($isIdentified): ?>
              <tr class="table-light">
                <td colspan="7" class="py-0">
                  <div id="<?= h($identifierCollapseId) ?>" class="collapse">
                    <div class="p-3 js-identifier-browser"
                         id="<?= h($identifierBrowserId) ?>"
                         data-page-size="<?= (int)$identifierPageSize ?>"
                         data-default-view="<?= $hasIdentifierMatch ? 'matches' : 'all' ?>">
                      <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-2">
                        <div>
                          <div class="fw-semibold"><?= h($identifierLabel) ?>k a raktárban</div>
                          <div class="text-secondary small"><?= h((string)$row['material_name']) ?> · <?= h((string)$row['warehouse_name']) ?></div>
                        </div>
                        <div class="text-end">
                          <div class="small text-secondary">Összesen: <?= (int)count($preparedIdentifierRows) ?> db</div>
                          <?php if ($hasIdentifierMatch): ?>
                            <div class="small"><span class="badge bg-warning text-dark">Találatok: <?= (int)$matchedIdentifierCount ?> db</span></div>
                          <?php endif; ?>
                        </div>
                      </div>
                      <?php if ($preparedIdentifierRows): ?>
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                          <div class="small text-secondary js-identifier-summary">&nbsp;</div>
                          <div class="btn-group btn-group-sm" role="group" aria-label="Azonosító nézet váltása">
                            <button type="button" class="btn btn-outline-secondary js-identifier-view" data-view="all">Összes azonosító</button>
                            <button type="button" class="btn btn-outline-warning js-identifier-view" data-view="matches"<?= $hasIdentifierMatch ? '' : ' disabled' ?>>Csak találatok</button>
                          </div>
                        </div>
                        <div class="table-responsive">
                          <table class="table table-sm align-middle mb-0">
                            <thead>
                              <tr>
                                <th><?= h($identifierLabel) ?></th>
                                <th>Megjegyzés</th>
                                <th>Rögzítve</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($preparedIdentifierRows as $identifierRow): ?>
                                <tr<?= $identifierRow['is_match'] ? ' class="table-warning"' : '' ?> data-identifier-item="1" data-match="<?= $identifierRow['is_match'] ? '1' : '0' ?>">
                                  <td>
                                    <code><?= h((string)$identifierRow['display']) ?></code>
                                    <?php if ($identifierRow['is_match']): ?>
                                      <span class="badge bg-warning text-dark ms-2">Találat</span>
                                    <?php endif; ?>
                                  </td>
                                  <td><?= h((string)$identifierRow['note']) ?: '—' ?></td>
                                  <td><?= h((string)$identifierRow['created_at']) ?></td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mt-2">
                          <div class="small text-secondary js-identifier-range">&nbsp;</div>
                          <div class="d-flex align-items-center gap-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary js-identifier-prev">Előző</button>
                            <span class="small text-secondary js-identifier-page">1 / 1</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary js-identifier-next">Következő</button>
                          </div>
                        </div>
                        <div class="alert alert-warning mt-2 mb-0 d-none js-identifier-empty">Nincs megjeleníthető azonosító ebben a nézetben.</div>
                      <?php else: ?>
                        <div class="alert alert-warning mb-0">Ehhez a készletsorhoz még nincs rögzített azonosító.</div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if (!$displayRows): ?>
            <tr><td colspan="7" class="text-center text-secondary py-4">Nincs megjeleníthető készletadat.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($stockTotalRows > 0): ?>
      <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mt-3">
        <div class="small text-secondary">
          <?= $displayFrom ?>–<?= $displayTo ?>. tétel / <?= $stockTotalRows ?> összesen · oldal <?= $stockPage ?> / <?= $stockTotalPages ?>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <a class="btn btn-sm btn-outline-secondary <?= $stockPage <= 1 ? 'disabled' : '' ?>" href="<?= $stockPage <= 1 ? '#' : h('/stock.php?' . $buildQuery(['page' => $stockPage - 1])) ?>" <?= $stockPage <= 1 ? 'tabindex="-1" aria-disabled="true"' : '' ?>>Előző</a>
          <span class="small text-secondary"><?= $stockPage ?> / <?= $stockTotalPages ?></span>
          <a class="btn btn-sm btn-outline-secondary <?= $stockPage >= $stockTotalPages ? 'disabled' : '' ?>" href="<?= $stockPage >= $stockTotalPages ? '#' : h('/stock.php?' . $buildQuery(['page' => $stockPage + 1])) ?>" <?= $stockPage >= $stockTotalPages ? 'tabindex="-1" aria-disabled="true"' : '' ?>>Következő</a>
        </div>
      </div>
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

  document.querySelectorAll('.js-identifier-browser').forEach(function (browser) {
    var rows = Array.prototype.slice.call(browser.querySelectorAll('tr[data-identifier-item="1"]'));
    if (!rows.length) {
      return;
    }

    var pageSize = parseInt(browser.dataset.pageSize || '25', 10);
    if (!pageSize || pageSize < 1) {
      pageSize = 25;
    }

    var defaultView = browser.dataset.defaultView === 'matches' ? 'matches' : 'all';
    var currentView = defaultView;
    var currentPage = 1;
    var summaryEl = browser.querySelector('.js-identifier-summary');
    var rangeEl = browser.querySelector('.js-identifier-range');
    var pageEl = browser.querySelector('.js-identifier-page');
    var emptyEl = browser.querySelector('.js-identifier-empty');
    var prevBtn = browser.querySelector('.js-identifier-prev');
    var nextBtn = browser.querySelector('.js-identifier-next');
    var viewButtons = Array.prototype.slice.call(browser.querySelectorAll('.js-identifier-view'));
    var matchCount = rows.filter(function (row) { return row.dataset.match === '1'; }).length;

    function getFilteredRows() {
      return rows.filter(function (row) {
        return currentView !== 'matches' || row.dataset.match === '1';
      });
    }

    function updateViewButtons() {
      viewButtons.forEach(function (button) {
        var isActive = button.dataset.view === currentView;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        if (button.dataset.view === 'all') {
          button.classList.toggle('btn-secondary', isActive);
          button.classList.toggle('btn-outline-secondary', !isActive);
        }
        if (button.dataset.view === 'matches') {
          button.disabled = matchCount === 0;
          button.classList.toggle('btn-warning', isActive && matchCount > 0);
          button.classList.toggle('btn-outline-warning', !isActive && matchCount > 0);
        }
      });
    }

    function render() {
      var filteredRows = getFilteredRows();
      var totalRows = filteredRows.length;
      var totalPages = Math.max(1, Math.ceil(totalRows / pageSize));

      if (currentPage > totalPages) {
        currentPage = totalPages;
      }
      if (currentPage < 1) {
        currentPage = 1;
      }

      var startIndex = totalRows === 0 ? 0 : (currentPage - 1) * pageSize;
      var endIndex = Math.min(startIndex + pageSize, totalRows);
      var visibleRows = filteredRows.slice(startIndex, endIndex);

      rows.forEach(function (row) {
        row.style.display = 'none';
      });
      visibleRows.forEach(function (row) {
        row.style.display = '';
      });

      if (summaryEl) {
        var modeLabel = currentView === 'matches' ? 'Találatok nézet' : 'Összes nézet';
        summaryEl.textContent = modeLabel + ' · ' + totalRows + ' tétel';
      }
      if (rangeEl) {
        rangeEl.textContent = totalRows === 0
          ? 'Nincs megjeleníthető tétel.'
          : (startIndex + 1) + '–' + endIndex + '. tétel / ' + totalRows + ' összesen';
      }
      if (pageEl) {
        pageEl.textContent = currentPage + ' / ' + totalPages;
      }
      if (prevBtn) {
        prevBtn.disabled = currentPage <= 1 || totalRows === 0;
      }
      if (nextBtn) {
        nextBtn.disabled = currentPage >= totalPages || totalRows === 0;
      }
      if (emptyEl) {
        emptyEl.classList.toggle('d-none', totalRows !== 0);
      }

      updateViewButtons();
    }

    browser.addEventListener('click', function (event) {
      var viewButton = event.target.closest('.js-identifier-view');
      if (viewButton && browser.contains(viewButton)) {
        event.preventDefault();
        event.stopPropagation();
        if (viewButton.disabled) {
          return;
        }
        currentView = viewButton.dataset.view === 'matches' ? 'matches' : 'all';
        currentPage = 1;
        render();
        return;
      }

      var prevButton = event.target.closest('.js-identifier-prev');
      if (prevButton && browser.contains(prevButton)) {
        event.preventDefault();
        event.stopPropagation();
        if (currentPage > 1) {
          currentPage -= 1;
          render();
        }
        return;
      }

      var nextButton = event.target.closest('.js-identifier-next');
      if (nextButton && browser.contains(nextButton)) {
        event.preventDefault();
        event.stopPropagation();
        var filteredRows = getFilteredRows();
        var totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        if (currentPage < totalPages) {
          currentPage += 1;
          render();
        }
      }
    });

    render();
  });
});
</script>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

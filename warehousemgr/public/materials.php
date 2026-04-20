<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Anyagtörzs oldal.
 * Anyagok létrehozása, módosítása, archiválása és CSV importja itt történik.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Anyagtörzs';
$loggedIn = true;
$pdo = warehouse_pdo($config);
$isAdmin = warehouse_module_admin($config);
$identifierFeatureReady = warehouse_material_identifier_feature_ready($config);
$archiveFeatureReady = warehouse_material_archive_feature_ready($config);
$priceFeatureReady = warehouse_material_price_feature_ready($config);
$editId = (int)($_GET['edit'] ?? $_POST['material_id'] ?? 0);
$editMaterial = $editId > 0 ? warehouse_material_find($config, $editId) : null;

// Az oldal ugyanazon a végponton kezeli a létrehozást, módosítást, archiválást és a CSV import lépéseit.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!$isAdmin && in_array($action, ['save_material', 'toggle_material_active', 'toggle_material_archive', 'import_csv_prepare', 'import_csv_update_mapping', 'import_csv_confirm', 'import_csv_cancel'], true)) {
        http_response_code(403);
        echo '403 - Ehhez a művelethez warehousemgr admin jogosultság szükséges.';
        exit;
    }

    if ($action === 'save_material') {
        $materialId = (int)($_POST['material_id'] ?? 0);
        try {
            $savedId = warehouse_material_upsert($config, [
                'sku' => $_POST['sku'] ?? '',
                'name' => $_POST['name'] ?? '',
                'unit' => $_POST['unit'] ?? '',
                'category_name' => $_POST['category_name'] ?? '',
                'minimum_stock' => $_POST['minimum_stock'] ?? '',
                'note' => $_POST['note'] ?? '',
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'is_identified' => isset($_POST['is_identified']) ? 1 : 0,
                'identifier_label' => $_POST['identifier_label'] ?? '',
                'unit_price' => $_POST['unit_price'] ?? '',
                'currency_code' => $_POST['currency_code'] ?? '',
            ], $materialId > 0 ? $materialId : null);

            warehouse_audit($config, $materialId > 0 ? 'material.update' : 'material.create', 'material', $savedId, [
                'sku' => trim((string)($_POST['sku'] ?? '')),
                'name' => trim((string)($_POST['name'] ?? '')),
            ]);
            flash_set('msg', $materialId > 0 ? 'Anyag módosítva.' : 'Anyag létrehozva.');
            header('Location: /materials.php' . ($materialId > 0 ? '?edit=' . $savedId : ''));
            exit;
        } catch (Throwable $e) {
            flash_set('err', 'Mentési hiba: ' . $e->getMessage());
            header('Location: /materials.php' . ($materialId > 0 ? '?edit=' . $materialId : ''));
            exit;
        }
    }

    if ($action === 'toggle_material_active') {
        $materialId = (int)($_POST['material_id'] ?? 0);
        if ($materialId > 0) {
            $pdo->prepare("UPDATE material_items SET is_active = IF(is_active=1,0,1), updated_by=? WHERE id=?")
                ->execute([current_auth_user_id(), $materialId]);
            $current = warehouse_material_find($config, $materialId);
            warehouse_audit($config, 'material.toggle_active', 'material', $materialId, [
                'new_is_active' => (int)($current['is_active'] ?? 0),
                'sku' => (string)($current['sku'] ?? ''),
                'name' => (string)($current['name'] ?? ''),
            ]);
            flash_set('msg', 'Anyag állapota frissítve.');
        }
        header('Location: /materials.php');
        exit;
    }

    // Anyag archiválása: nem törlünk adatot, csak kivesszük az anyagot az aktív napi használatból.
    if ($action === 'toggle_material_archive') {
        $materialId = (int)($_POST['material_id'] ?? 0);
        if ($materialId > 0) {
            $archive = (int)($_POST['archive'] ?? 0) === 1;
            $current = warehouse_material_archive_toggle($config, $materialId, $archive);
            warehouse_audit($config, 'material.toggle_archive', 'material', $materialId, [
                'new_is_archived' => (int)($current['is_archived'] ?? 0),
                'sku' => (string)($current['sku'] ?? ''),
                'name' => (string)($current['name'] ?? ''),
            ]);
            flash_set('msg', $archive ? 'Anyag archiválva.' : 'Anyag visszaállítva az aktív listába.');
        }
        $query = http_build_query(array_filter([
            'q' => trim((string)($_POST['return_q'] ?? '')),
            'category_name' => trim((string)($_POST['return_category_name'] ?? '')),
            'sort' => trim((string)($_POST['return_sort'] ?? 'name')),
            'dir' => trim((string)($_POST['return_dir'] ?? 'asc')),
            'page' => (int)($_POST['return_page'] ?? 1),
            'include_archived' => (int)($_POST['return_include_archived'] ?? 0) === 1 ? 1 : null,
        ], static fn($v): bool => !($v === '' || $v === null)));
        header('Location: /materials.php' . ($query !== '' ? '?' . $query : ''));
        exit;
    }

    if ($action === 'import_csv_prepare') {
        try {
            if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
                throw new RuntimeException('Nem érkezett CSV fájl.');
            }
            $file = $_FILES['csv_file'];
            if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('A feltöltés hibás vagy hiányzik.');
            }
            $name = (string)($file['name'] ?? 'anyagtorzs.csv');
            warehouse_material_import_prepare((string)$file['tmp_name'], $name);
            flash_set('msg', 'CSV beolvasva. Ellenőrizd a mező-összerendelést, majd indítsd az importot.');
        } catch (Throwable $e) {
            flash_set('err', 'CSV előkészítési hiba: ' . $e->getMessage());
        }
        header('Location: /materials.php');
        exit;
    }

    if (in_array($action, ['import_csv_update_mapping', 'import_csv_confirm', 'import_csv_cancel'], true)) {
        try {
            $pending = warehouse_material_import_pending_get();
            if (!$pending) {
                throw new RuntimeException('Nincs folyamatban lévő CSV import. Tölts fel új fájlt.');
            }

            if ($action === 'import_csv_cancel') {
                warehouse_material_import_pending_clear();
                flash_set('msg', 'Az előkészített CSV import törölve lett.');
                header('Location: /materials.php');
                exit;
            }

            $map = warehouse_material_import_mapping_from_request($_POST, (array)($pending['headers'] ?? []));
            $pending['map'] = $map;
            $_SESSION['_warehouse_material_import'] = $pending;

            if ($action === 'import_csv_update_mapping') {
                flash_set('msg', 'Mező-összerendelés frissítve. Ellenőrizd az előnézetet, majd indítsd az importot.');
                header('Location: /materials.php');
                exit;
            }

            $result = warehouse_material_import_execute(
                $config,
                (string)($pending['temp_path'] ?? ''),
                (string)($pending['original_name'] ?? 'anyagtorzs.csv'),
                (string)($pending['delimiter'] ?? ';'),
                $map
            );
            warehouse_material_import_pending_clear();
            $msg = 'CSV import kész. Sorok: ' . $result['total_rows'] . ', új: ' . $result['inserted_rows'] . ', frissített: ' . $result['updated_rows'] . ', hibás: ' . $result['error_rows'] . '.';
            if (!empty($result['error_report_path'])) {
                $msg .= ' Hibalista: storage/' . ltrim((string)$result['error_report_path'], '/');
            }
            flash_set('msg', $msg);
        } catch (Throwable $e) {
            flash_set('err', 'CSV import hiba: ' . $e->getMessage());
        }
        header('Location: /materials.php');
        exit;
    }
}

$msg = flash_get('msg');
$err = flash_get('err');
// A listaoldal minden megjelenítési adatát a központi helper adja vissza,
// így a szűrés, rendezés és lapozás logikája egy helyen marad.
$list = warehouse_material_list($config, $_GET);
$materials = $list['rows'];
$categoryOptions = warehouse_material_category_options($config, true);
$batches = warehouse_material_import_batches($config, 8);
$importPending = warehouse_material_import_pending_get();
$importTargets = warehouse_material_import_targets();
$importPreview = $importPending ? warehouse_material_import_preview_rows((array)($importPending['sample_rows'] ?? []), (array)($importPending['map'] ?? [])) : [];

$selectedMaterialIds = array_values(array_unique(array_filter(
    array_map('intval', (array)($_GET['selected_material_ids'] ?? [])),
    static fn(int $id): bool => $id > 0
)));
$showLocations = isset($_GET['show_locations']) && $selectedMaterialIds !== [];
$materialLocations = [];
if ($showLocations) {
    $materialLocations = warehouse_material_stock_locations($config, $selectedMaterialIds, true);
    warehouse_audit($config, 'material.stock_locations_view', 'material', count($selectedMaterialIds) === 1 ? $selectedMaterialIds[0] : null, [
        'material_ids' => $selectedMaterialIds,
        'material_count' => count($selectedMaterialIds),
    ]);
}

if ((string)($_GET['export'] ?? '') === 'csv') {
    $exportList = warehouse_material_list($config, array_merge($_GET, ['page' => 1, 'per_page' => 10000]));
    $exportRows = [];
    foreach ($exportList['rows'] as $row) {
        $exportRows[] = [
            'Cikkszám' => (string)($row['sku'] ?? ''),
            'Megnevezés' => (string)($row['name'] ?? ''),
            'Kategória' => (string)($row['category_name'] ?? ''),
            'Mértékegység' => (string)($row['unit'] ?? ''),
            'Minimum készlet' => $row['minimum_stock'] !== null ? warehouse_format_quantity($row['minimum_stock']) : '',
            'Aktív' => ((int)($row['is_active'] ?? 0) === 1) ? 'Igen' : 'Nem',
            'Archivált' => ((int)($row['is_archived'] ?? 0) === 1) ? 'Igen' : 'Nem',
            'Egyedi azonosítós' => ((int)($row['is_identified'] ?? 0) === 1) ? 'Igen' : 'Nem',
            'Azonosító típusa' => (string)($row['identifier_label'] ?? ''),
            'Egységár' => $priceFeatureReady ? warehouse_format_money_amount($row['unit_price'] ?? null) : '',
            'Pénznem' => $priceFeatureReady ? (string)($row['currency_code'] ?? '') : '',
            'Megjegyzés' => (string)($row['note'] ?? ''),
        ];
    }
    warehouse_csv_download('anyagtorzs_' . date('Ymd_His') . '.csv', ['Cikkszám', 'Megnevezés', 'Kategória', 'Mértékegység', 'Minimum készlet', 'Aktív', 'Archivált', 'Egyedi azonosítós', 'Azonosító típusa', 'Egységár', 'Pénznem', 'Megjegyzés'], $exportRows);
}


if ((string)($_GET['export'] ?? '') === 'locations_csv' && $showLocations) {
    $exportRows = [];
    foreach ($materialLocations as $group) {
        $material = (array)($group['material'] ?? []);
        $locations = (array)($group['locations'] ?? []);
        if ($locations === []) {
            $exportRows[] = [
                'Cikkszám' => (string)($material['sku'] ?? ''),
                'Megnevezés' => (string)($material['name'] ?? ''),
                'Kategória' => (string)($material['category_name'] ?? ''),
                'Mértékegység' => (string)($material['unit'] ?? ''),
                'Raktár' => '',
                'Raktárkód' => '',
                'Mennyiség' => warehouse_format_quantity(0),
                'Összes mennyiség' => warehouse_format_quantity($group['total_quantity'] ?? 0),
                'Állapot' => '',
            ];
            continue;
        }
        foreach ($locations as $loc) {
            $exportRows[] = [
                'Cikkszám' => (string)($material['sku'] ?? ''),
                'Megnevezés' => (string)($material['name'] ?? ''),
                'Kategória' => (string)($material['category_name'] ?? ''),
                'Mértékegység' => (string)($material['unit'] ?? ''),
                'Raktár' => (string)($loc['warehouse_name'] ?? ''),
                'Raktárkód' => (string)($loc['warehouse_code'] ?? ''),
                'Mennyiség' => warehouse_format_quantity($loc['quantity'] ?? 0),
                'Összes mennyiség' => warehouse_format_quantity($group['total_quantity'] ?? 0),
                'Állapot' => ((int)($loc['warehouse_is_active'] ?? 0) === 1) ? 'Aktív' : 'Inaktív',
            ];
        }
    }
    warehouse_csv_download('anyag_raktarlista_' . date('Ymd_His') . '.csv', ['Cikkszám', 'Megnevezés', 'Kategória', 'Mértékegység', 'Raktár', 'Raktárkód', 'Mennyiség', 'Összes mennyiség', 'Állapot'], $exportRows);
}

$defaults = [
    'id' => 0,
    'sku' => '',
    'name' => '',
    'unit' => '',
    'category_name' => '',
    'minimum_stock' => '',
    'note' => '',
    'is_active' => 1,
    'is_identified' => 0,
    'identifier_label' => '',
    'unit_price' => '',
    'currency_code' => 'HUF',
];
$formData = array_merge($defaults, $editMaterial ?: []);

$queryBase = [
    'q' => $list['q'],
    'category_name' => $list['category_name'] ?? '',
    'sort' => $list['sort'],
    'dir' => $list['dir'],
    'include_archived' => (int)($list['include_archived'] ?? 0) === 1 ? 1 : null,
    'page' => $list['page'],
];
$buildQuery = static function (array $overrides = [], bool $includeSelected = false) use ($queryBase, $selectedMaterialIds): string {
    $params = array_merge($queryBase, $overrides);
    if ($includeSelected && $selectedMaterialIds !== []) {
        $params['selected_material_ids'] = $selectedMaterialIds;
        $params['show_locations'] = 1;
    }
    return http_build_query(array_filter($params, static function ($value): bool {
        return !($value === '' || $value === null);
    }));
};
$sortLink = static function (string $column) use ($list, $buildQuery): string {
    $nextDir = 'asc';
    if ($list['sort'] === $column && $list['dir'] === 'asc') {
        $nextDir = 'desc';
    }
    return '/materials.php?' . $buildQuery([
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
    <h1 class="h4 m-0">Közös anyagtörzs</h1>
    <div class="text-secondary small">Lapozható, kereshető és rendezhető anyaglista. A raktárankénti előfordulás külön lekérdezhető.</div>
  </div>
  <?php if ($isAdmin): ?>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/audit_log.php">Admin napló</a>
    <a class="btn btn-sm btn-outline-secondary" href="/sample_materials.csv" download>Minta CSV</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<?php if (!$identifierFeatureReady && $isAdmin): ?>
<div class="alert alert-warning">Az egyedi azonosítós anyagkezelés adatbázis része még nincs telepítve. Futtasd a <code>database/warehousemgr_update_step12_material_identifiers.sql</code> fájlt, és utána jelennek meg az új mezők és az azonosítókezelő oldal.</div>
<?php endif; ?>

<?php if (!$priceFeatureReady && $isAdmin): ?>
<div class="alert alert-warning">Az árkezelés adatbázis része még nincs telepítve. Futtasd a <code>database/warehousemgr_update_step17_material_prices.sql</code> fájlt, és utána jelennek meg az új ár mezők.</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="fw-semibold"><?= ((int)$formData['id'] > 0) ? 'Anyag módosítása' : 'Új anyag felvitel' ?></div>
        <div class="text-secondary small">Kézi felvitel vagy meglévő anyag szerkesztése.</div>
      </div>
      <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#material-form-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
        <span class="wm-panel-toggle-label">Megnyitás</span>
      </button>
    </div>
    <div id="material-form-panel" class="collapse" data-wm-panel="1" data-panel-key="materials-form" data-force-open="<?= ((int)$formData['id'] > 0) ? '1' : '0' ?>">
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="save_material">
          <input type="hidden" name="material_id" value="<?= (int)$formData['id'] ?>">
          <div class="col-12 col-md-6">
            <label class="form-label">Cikkszám</label>
            <input class="form-control" name="sku" required value="<?= h((string)$formData['sku']) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Mértékegység</label>
            <input class="form-control" name="unit" value="<?= h((string)$formData['unit']) ?>" placeholder="db / m / kg">
          </div>
          <div class="col-12">
            <label class="form-label">Megnevezés</label>
            <input class="form-control" name="name" required value="<?= h((string)$formData['name']) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Kategória</label>
            <input class="form-control" name="category_name" value="<?= h((string)$formData['category_name']) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Minimum készlet</label>
            <input class="form-control" name="minimum_stock" value="<?= h((string)$formData['minimum_stock']) ?>" placeholder="pl. 10 vagy 2,5">
          </div>
          <?php if ($priceFeatureReady): ?>
          <div class="col-12 col-md-6">
            <label class="form-label">Egységár</label>
            <input class="form-control" name="unit_price" value="<?= h((string)$formData['unit_price']) ?>" inputmode="decimal" placeholder="pl. 1250 vagy 19,90">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Pénznem</label>
            <input class="form-control" name="currency_code" list="currency-code-options" value="<?= h((string)($formData['currency_code'] ?? 'HUF')) ?>" placeholder="HUF / EUR">
            <datalist id="currency-code-options">
              <option value="HUF"></option>
              <option value="EUR"></option>
              <option value="USD"></option>
              <option value="GBP"></option>
              <option value="CHF"></option>
            </datalist>
          </div>
          <?php endif; ?>
          <div class="col-12">
            <label class="form-label">Megjegyzés</label>
            <textarea class="form-control" name="note" rows="3"><?= h((string)$formData['note']) ?></textarea>
          </div>
          <?php if ($identifierFeatureReady): ?>
          <div class="col-12 col-md-6">
            <div class="form-check mt-4">
              <input class="form-check-input" type="checkbox" name="is_identified" id="is_identified" value="1" <?= ((int)$formData['is_identified'] === 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_identified">Egyedi azonosítós anyag</label>
            </div>
            <div class="form-text">Ilyen anyagnál darabonként külön azonosító (pl. sorozatszám, IMEI) rögzíthető.</div>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Azonosító típusa</label>
            <input class="form-control" name="identifier_label" id="identifier_label" value="<?= h((string)$formData['identifier_label']) ?>" placeholder="pl. Sorozatszám / IMEI / Gyári szám">
          </div>
          <?php endif; ?>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= ((int)$formData['is_active'] === 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_active">Aktív</label>
            </div>
          </div>
          <div class="col-12 d-flex justify-content-between">
            <?php if ((int)$formData['id'] > 0): ?>
              <a class="btn btn-outline-secondary" href="/materials.php">Új űrlap</a>
            <?php else: ?>
              <span></span>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= ((int)$formData['id'] > 0) ? 'Mentés' : 'Anyag létrehozása' ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="fw-semibold">CSV import</div>
        <div class="text-secondary small">Tömeges anyagfeltöltés vagy frissítés cikkszám alapján.</div>
      </div>
      <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#material-import-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
        <span class="wm-panel-toggle-label">Megnyitás</span>
      </button>
    </div>
    <div id="material-import-panel" class="collapse" data-wm-panel="1" data-panel-key="materials-import" data-force-open="<?= $importPending ? '1' : '0' ?>">
      <div class="card-body">
        <?php if (!$importPending): ?>
        <form method="post" enctype="multipart/form-data" class="row g-3">
          <input type="hidden" name="action" value="import_csv_prepare">
          <div class="col-12">
            <label class="form-label">CSV fájl</label>
            <input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required>
            <div class="form-text">Feltöltés után megadható, hogy a CSV melyik oszlopa melyik belső mezőnek feleljen meg.</div>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-outline-primary" type="submit">Tovább az összerendeléshez</button>
          </div>
        </form>
        <?php else: ?>
        <div class="alert alert-light border mb-3">
          <div><strong>Fájl:</strong> <?= h((string)($importPending['original_name'] ?? '')) ?></div>
          <div><strong>Oszlopok:</strong> <?= count((array)($importPending['headers'] ?? [])) ?> db</div>
          <div><strong>Fejléc:</strong> <?= h(implode(' | ', array_map(static fn($v): string => (string)$v, (array)($importPending['headers'] ?? [])))) ?></div>
        </div>

        <form method="post" class="row g-3">
          <?php foreach ($importTargets as $fieldKey => $meta): ?>
          <div class="col-12 col-md-6 col-lg-4">
            <label class="form-label"><?= h((string)$meta['label']) ?><?= !empty($meta['required']) ? ' *' : '' ?></label>
            <select class="form-select" name="mapping[<?= h($fieldKey) ?>]">
              <option value="">— nincs hozzárendelve —</option>
              <?php foreach ((array)($importPending['headers'] ?? []) as $idx => $header): ?>
              <option value="<?= (int)$idx ?>" <?= (($importPending['map'][$fieldKey] ?? null) === $idx) ? 'selected' : '' ?>><?= h((string)$header) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endforeach; ?>
          <div class="col-12 d-flex flex-wrap justify-content-between gap-2">
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-outline-secondary" type="submit" name="action" value="import_csv_update_mapping">Előnézet frissítése</button>
              <button class="btn btn-primary" type="submit" name="action" value="import_csv_confirm">Import indítása</button>
            </div>
            <button class="btn btn-outline-danger" type="submit" name="action" value="import_csv_cancel">Mégse</button>
          </div>
        </form>

        <hr>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>
            <div class="fw-semibold">Előnézet</div>
            <div class="text-secondary small">Az első néhány sor a jelenlegi mező-összerendeléssel.</div>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <?php foreach ($importTargets as $fieldKey => $meta): ?>
                <th><?= h((string)$meta['label']) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($importPreview as $previewRow): ?>
              <tr>
                <?php foreach ($importTargets as $fieldKey => $meta): ?>
                <td><?= h((string)($previewRow[$fieldKey] ?? '')) ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
              <?php if (!$importPreview): ?>
              <tr><td colspan="<?= count($importTargets) ?>" class="text-secondary">Nincs megjeleníthető mintasor.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="fw-semibold">Legutóbbi importok</div>
        <div class="text-secondary small">Az utolsó importok összesítése és eredménye.</div>
      </div>
      <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#material-import-history-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
        <span class="wm-panel-toggle-label">Megnyitás</span>
      </button>
    </div>
    <div id="material-import-history-panel" class="collapse" data-wm-panel="1" data-panel-key="materials-import-history">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle table-sm">
            <thead>
              <tr>
                <th>ID</th>
                <th>Fájl</th>
                <th>Sor</th>
                <th>Új</th>
                <th>Frissített</th>
                <th>Hiba</th>
                <th>Megjegyzés</th>
                <th>Dátum</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($batches as $b): ?>
              <tr>
                <td><?= (int)$b['id'] ?></td>
                <td><?= h((string)$b['file_name']) ?></td>
                <td><?= (int)$b['total_rows'] ?></td>
                <td><?= (int)$b['inserted_rows'] ?></td>
                <td><?= (int)$b['updated_rows'] ?></td>
                <td><?= (int)$b['error_rows'] ?></td>
                <td class="small text-break"><?= h((string)($b['notes'] ?? '')) ?></td>
                <td><?= h((string)$b['created_at']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$batches): ?>
              <tr><td colspan="8" class="text-secondary">Még nem volt import.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
      <div>
        <h2 class="h6 mb-1">Anyag lista</h2>
        <div class="text-secondary small">
          Összesen <?= (int)$list['total'] ?> anyag,
          oldalanként <?= (int)$list['per_page'] ?> sor<?php if ((int)($list['include_archived'] ?? 0) === 1): ?> · archívval együtt<?php endif; ?>.
        </div>
      </div>
      <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="sort" value="<?= h((string)$list['sort']) ?>">
        <input type="hidden" name="dir" value="<?= h((string)$list['dir']) ?>">
        <div class="col-auto">
          <label class="form-label small mb-1">Keresés</label>
          <input class="form-control form-control-sm" type="text" name="q" value="<?= h((string)$list['q']) ?>" placeholder="Cikkszám / megnevezés / kategória / azonosító">
        </div>
        <?php if ($archiveFeatureReady): ?>
        <div class="col-auto">
          <label class="form-label small mb-1">Kategória</label>
          <select class="form-select form-select-sm" name="category_name">
            <option value="">— mind —</option>
            <?php foreach ($categoryOptions as $categoryOption): ?>
              <option value="<?= h($categoryOption) ?>" <?= (($list['category_name'] ?? '') === $categoryOption) ? 'selected' : '' ?>><?= h($categoryOption) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto d-flex align-items-end">
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="include_archived_materials" name="include_archived" value="1" <?= ((int)($list['include_archived'] ?? 0) === 1) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="include_archived_materials">Archív is</label>
          </div>
        </div>
        <?php endif; ?>
        <div class="col-auto">
          <button class="btn btn-sm btn-primary" type="submit">Szűrés</button>
        </div>
        <div class="col-auto">
          <a class="btn btn-sm btn-outline-secondary" href="/materials.php">Alaphelyzet</a>
        </div>
        <div class="col-auto">
          <a class="btn btn-sm btn-outline-success js-csv-export" data-export-label="CSV készül…" href="/materials.php?<?= h($buildQuery(['export' => 'csv', 'page' => 1], false)) ?>">CSV export</a>
        </div>
      </form>
    </div>

    <form method="get" id="materials-list-form">
      <input type="hidden" name="q" value="<?= h((string)$list['q']) ?>">
      <input type="hidden" name="category_name" value="<?= h((string)($list['category_name'] ?? '')) ?>">
      <input type="hidden" name="sort" value="<?= h((string)$list['sort']) ?>">
      <input type="hidden" name="dir" value="<?= h((string)$list['dir']) ?>">
      <?php if ((int)($list['include_archived'] ?? 0) === 1): ?><input type="hidden" name="include_archived" value="1"><?php endif; ?>
      <input type="hidden" name="page" value="<?= (int)$list['page'] ?>">
      <input type="hidden" name="show_locations" value="1">

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="small text-secondary">
          <?php if ($list['total'] > 0): ?>
            Megjelenítve: <?= (int)$list['offset'] + 1 ?>–<?= (int)min($list['offset'] + count($materials), $list['total']) ?>
          <?php else: ?>
            Nincs találat.
          <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-sm btn-outline-primary">Kijelöltek raktárkészlete</button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th style="width:40px;"><input class="form-check-input" type="checkbox" id="check-all-materials"></th>
              <th><a class="link-dark text-decoration-none" href="<?= h($sortLink('sku')) ?>">Cikkszám <?= h($sortIcon('sku')) ?></a></th>
              <th><a class="link-dark text-decoration-none" href="<?= h($sortLink('name')) ?>">Megnevezés <?= h($sortIcon('name')) ?></a></th>
              <th><a class="link-dark text-decoration-none" href="<?= h($sortLink('category')) ?>">Kategória <?= h($sortIcon('category')) ?></a></th>
              <th>ME</th>
              <th>Minimum</th>
              <?php if ($priceFeatureReady): ?><th><a class="link-dark text-decoration-none" href="<?= h($sortLink('price')) ?>">Egységár <?= h($sortIcon('price')) ?></a></th><th><a class="link-dark text-decoration-none" href="<?= h($sortLink('currency')) ?>">Pénznem <?= h($sortIcon('currency')) ?></a></th><?php endif; ?>
              <th>Aktív</th>
              <?php if ($archiveFeatureReady): ?><th><a class="link-dark text-decoration-none" href="<?= h($sortLink('archived')) ?>">Archivált <?= h($sortIcon('archived')) ?></a></th><?php endif; ?>
              <th class="text-end">Művelet</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($materials as $m): ?>
            <?php $checked = in_array((int)$m['id'], $selectedMaterialIds, true); ?>
            <tr>
              <td>
                <input class="form-check-input material-check" type="checkbox" name="selected_material_ids[]" value="<?= (int)$m['id'] ?>" <?= $checked ? 'checked' : '' ?>>
              </td>
              <td><code><?= h((string)$m['sku']) ?></code></td>
              <td>
                <div class="fw-bold"><?= h((string)$m['name']) ?></div>
                <?php if ((int)($m['is_identified'] ?? 0) === 1): ?><div class="small text-primary">Egyedi azonosítós<?= !empty($m['identifier_label']) ? ' · ' . h((string)$m['identifier_label']) : '' ?></div><?php endif; ?>
                <?php if ($archiveFeatureReady && (int)($m['is_archived'] ?? 0) === 1): ?><div class="small text-warning-emphasis"><span class="badge bg-warning text-dark">Archivált</span></div><?php endif; ?>
                <?php if (!empty($m['note'])): ?><div class="text-secondary small"><?= h((string)$m['note']) ?></div><?php endif; ?>
              </td>
              <td><?= h((string)($m['category_name'] ?? '—')) ?></td>
              <td><?= h((string)($m['unit'] ?? '—')) ?></td>
              <td><?= $m['minimum_stock'] !== null ? h(warehouse_format_quantity($m['minimum_stock'])) : '—' ?></td>
              <?php if ($priceFeatureReady): ?>
              <td><?= !empty($m['unit_price']) ? h(warehouse_format_money_amount($m['unit_price'])) : '—' ?></td>
              <td><?= !empty($m['currency_code']) ? h((string)$m['currency_code']) : '—' ?></td>
              <?php endif; ?>
              <td><?= ((int)$m['is_active'] === 1) ? '<span class="badge bg-success">Igen</span>' : '<span class="badge bg-secondary">Nem</span>' ?></td>
              <?php if ($archiveFeatureReady): ?><td><?= ((int)($m['is_archived'] ?? 0) === 1) ? '<span class="badge bg-warning text-dark">Igen</span>' : '<span class="badge bg-light text-dark border">Nem</span>' ?></td><?php endif; ?>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-info" href="/materials.php?<?= h($buildQuery(['selected_material_ids' => [(int)$m['id']], 'show_locations' => 1], false)) ?>#stock-locations">Raktárak</a>
                <?php if ($identifierFeatureReady && (int)($m['is_identified'] ?? 0) === 1): ?>
                  <a class="btn btn-sm btn-outline-dark" href="/material_identifiers.php?material_id=<?= (int)$m['id'] ?>">Azonosítók</a>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                  <a class="btn btn-sm btn-outline-primary" href="/materials.php?edit=<?= (int)$m['id'] ?>">Szerkesztés</a>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="toggle_material_active">
                    <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
                    <!-- button class="btn btn-sm btn-outline-secondary" type="submit"><?= ((int)$m['is_active'] === 1) ? 'Inaktivál' : 'Aktivál' ?></button -->
                  </form>
                  <?php if ($archiveFeatureReady): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('<?= ((int)($m['is_archived'] ?? 0) === 1) ? 'Biztosan visszaállítod az anyagot az aktív listába?' : 'Biztosan archiválod az anyagot és a hozzá tartozó azonosítókat is?' ?>');">
                    <input type="hidden" name="action" value="toggle_material_archive">
                    <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
                    <input type="hidden" name="archive" value="<?= ((int)($m['is_archived'] ?? 0) === 1) ? '0' : '1' ?>">
                    <input type="hidden" name="return_q" value="<?= h((string)$list['q']) ?>">
                    <input type="hidden" name="return_category_name" value="<?= h((string)($list['category_name'] ?? '')) ?>">
                    <input type="hidden" name="return_sort" value="<?= h((string)$list['sort']) ?>">
                    <input type="hidden" name="return_dir" value="<?= h((string)$list['dir']) ?>">
                    <input type="hidden" name="return_page" value="<?= (int)$list['page'] ?>">
                    <input type="hidden" name="return_include_archived" value="<?= ((int)($list['include_archived'] ?? 0) === 1) ? '1' : '0' ?>">
                    <button class="btn btn-sm <?= ((int)($m['is_archived'] ?? 0) === 1) ? 'btn-outline-warning' : 'btn-outline-dark' ?>" type="submit"><?= ((int)($m['is_archived'] ?? 0) === 1) ? 'Visszaállít' : 'Archivál' ?></button>
                  </form>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$materials): ?>
            <tr><td colspan="<?= ($archiveFeatureReady ? 9 : 8) + ($priceFeatureReady ? 2 : 0) ?>" class="text-secondary">Még nincs anyag az anyagtörzsben.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </form>

    <?php if ($list['pages'] > 1): ?>
    <nav aria-label="Anyag lapozás" class="mt-3">
      <ul class="pagination pagination-sm mb-0 flex-wrap">
        <?php $prevPage = max(1, $list['page'] - 1); ?>
        <li class="page-item <?= $list['page'] <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="/materials.php?<?= h($buildQuery(['page' => $prevPage], true)) ?>">«</a>
        </li>
        <?php
          $startPage = max(1, $list['page'] - 2);
          $endPage = min($list['pages'], $list['page'] + 2);
          for ($p = $startPage; $p <= $endPage; $p++):
        ?>
        <li class="page-item <?= $p === $list['page'] ? 'active' : '' ?>">
          <a class="page-link" href="/materials.php?<?= h($buildQuery(['page' => $p], true)) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
        <?php $nextPage = min($list['pages'], $list['page'] + 1); ?>
        <li class="page-item <?= $list['page'] >= $list['pages'] ? 'disabled' : '' ?>">
          <a class="page-link" href="/materials.php?<?= h($buildQuery(['page' => $nextPage], true)) ?>">»</a>
        </li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
</div>

<?php if ($showLocations): ?>
<div class="card shadow-sm mb-4" id="stock-locations">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h2 class="h6 mb-1">Kiválasztott anyagok raktárkészlete</h2>
        <div class="text-secondary small">Megmutatja, hogy az adott anyag mely raktár(ak)ban szerepel és milyen mennyiségben.</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-sm btn-outline-success js-csv-export" data-export-label="CSV készül…" href="/materials.php?<?= h($buildQuery(['export' => 'locations_csv'], true)) ?>">CSV export</a>
        <a class="btn btn-sm btn-outline-secondary" href="/materials.php?<?= h($buildQuery([], false)) ?>">Bezárás</a>
      </div>
    </div>

    <?php foreach ($materialLocations as $materialId => $group): ?>
    <?php $material = $group['material']; ?>
    <div class="border rounded p-3 mb-3">
      <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
        <div>
          <div class="fw-bold"><code><?= h((string)($material['sku'] ?? '')) ?></code><?php if (!empty($material['name'])): ?> · <?= h((string)$material['name']) ?><?php endif; ?></div>
          <?php if (!empty($material['category_name'])): ?>
          <div class="small text-secondary">Kategória: <?= h((string)$material['category_name']) ?></div>
          <?php endif; ?>
        </div>
        <div class="text-end small">
          <div><strong>Összes mennyiség:</strong> <?= h(warehouse_format_quantity($group['total_quantity'])) ?> <?= h((string)($material['unit'] ?: '')) ?></div>
        </div>
      </div>

      <?php if ($group['locations']): ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Raktár</th>
              <th>Kód</th>
              <th>Mennyiség</th>
              <th>Állapot</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($group['locations'] as $loc): ?>
            <tr>
              <td><?= h((string)$loc['warehouse_name']) ?></td>
              <td><code><?= h((string)$loc['warehouse_code']) ?></code></td>
              <td><?= h(warehouse_format_quantity($loc['quantity'])) ?> <?= h((string)($material['unit'] ?: '')) ?></td>
              <td><?= ((int)$loc['warehouse_is_active'] === 1) ? '<span class="badge bg-success">Aktív</span>' : '<span class="badge bg-secondary">Inaktív</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="text-secondary small">Ehhez az anyaghoz jelenleg nincs készlet a látható raktárakban.</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const checkAll = document.getElementById('check-all-materials');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      document.querySelectorAll('.material-check').forEach(function (cb) {
        cb.checked = checkAll.checked;
      });
    });
  }

  const identifiedCheckbox = document.getElementById('is_identified');
  const identifierLabelInput = document.getElementById('identifier_label');
  const syncIdentifierInputs = function () {
    if (!identifierLabelInput || !identifiedCheckbox) return;
    identifierLabelInput.disabled = !identifiedCheckbox.checked;
    if (!identifiedCheckbox.checked) {
      identifierLabelInput.value = '';
    }
  };
  if (identifiedCheckbox && identifierLabelInput) {
    identifiedCheckbox.addEventListener('change', syncIdentifierInputs);
    syncIdentifierInputs();
  }

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

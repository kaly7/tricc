<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Anyagazonosítók kezelése.
 * Kézi, gyors és CSV alapú felvitel, szűrés, törlés és állapotkövetés ezen az oldalon történik.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Anyagazonosítók';
$loggedIn = true;
$featureReady = warehouse_material_identifier_feature_ready($config);
$archiveFeatureReady = warehouse_material_archive_feature_ready($config);
$includeArchivedFilter = in_array((string)($_GET['include_archived'] ?? ''), ['1', 'on', 'true'], true);
$manageableWarehouses = warehouse_manageable_warehouses($config, false);
$accessibleWarehouses = warehouse_accessible_warehouses($config, false);
$materials = warehouse_identified_materials_all($config, false, false);
$filterMaterials = warehouse_identified_materials_all($config, false, $includeArchivedFilter);

// Az oldal kezeli a kézi rögzítést, a gyors / szkenneres tömeges rögzítést,
// a CSV importot és a meglévő azonosítók törlését is.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if (!$featureReady) {
            throw new RuntimeException('Az egyedi azonosítós bővítés adatbázis része még nincs telepítve.');
        }

        // Egyedi kézi felvitel: egyszeres vagy páros azonosító is rögzíthető.
        if ($action === 'add_identifier') {
            $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
            $materialId = (int)($_POST['material_id'] ?? 0);
            $scanMode = strtolower(trim((string)($_POST['scan_mode'] ?? 'single')));
            $scanMode = $scanMode === 'pair' ? 'pair' : 'single';
            $id = warehouse_material_identifier_create($config, [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'identifier_value' => $_POST['identifier_value'] ?? '',
                'secondary_identifier_value' => $scanMode === 'pair' ? ($_POST['secondary_identifier_value'] ?? '') : '',
                'note' => $_POST['note'] ?? '',
            ]);
            flash_set('msg', $scanMode === 'pair' ? 'Kódpár rögzítve.' : 'Azonosító rögzítve.');
            header('Location: /material_identifiers.php?material_id=' . $materialId . '&warehouse_id=' . $warehouseId . '&highlight=' . $id);
            exit;
        }

        // Gyors rögzítés: vonalkódolvasóval vagy több soros beillesztéssel érkező kódok feldolgozása.
        if ($action === 'bulk_add_identifiers') {
            $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
            $materialId = (int)($_POST['material_id'] ?? 0);
            $scanMode = strtolower(trim((string)($_POST['scan_mode'] ?? 'single')));
            $scanMode = $scanMode === 'pair' ? 'pair' : 'single';
            $result = warehouse_material_identifiers_bulk_add($config, $materialId, $warehouseId, (string)($_POST['identifier_lines'] ?? ''), (string)($_POST['bulk_note'] ?? ''), $scanMode);
            $msg = ($scanMode === 'pair' ? 'Gyors páros rögzítés kész. ' : 'Gyors rögzítés kész. ')
                . 'Beolvasott sorok: ' . (int)$result['total_rows'] . ', új: ' . (int)$result['inserted_rows'] . ', hibás: ' . (int)$result['error_rows'] . '.';
            flash_set('msg', $msg);
            if (!empty($result['errors'])) {
                $_SESSION['_flash_identifier_bulk_errors'] = array_values((array)$result['errors']);
            }
            header('Location: /material_identifiers.php?material_id=' . $materialId . '&warehouse_id=' . $warehouseId);
            exit;
        }

        if ($action === 'import_identifiers_csv') {
            if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
                throw new RuntimeException('Nem érkezett CSV fájl.');
            }
            $file = $_FILES['csv_file'];
            if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('A feltöltés hibás vagy hiányzik.');
            }
            $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
            $materialId = (int)($_POST['material_id'] ?? 0);
            $scanMode = strtolower(trim((string)($_POST['scan_mode'] ?? 'single')));
            $scanMode = $scanMode === 'pair' ? 'pair' : 'single';
            $result = warehouse_material_identifiers_import_csv($config, $materialId, $warehouseId, (string)$file['tmp_name'], (string)($file['name'] ?? 'azonositok.csv'), $scanMode);
            $msg = ($scanMode === 'pair' ? 'CSV páros import kész. ' : 'CSV import kész. ')
                . 'Sorok: ' . (int)$result['total_rows'] . ', új: ' . (int)$result['inserted_rows'] . ', hibás: ' . (int)$result['error_rows'] . '.';
            if (!empty($result['errors'])) {
                $msg .= ' Első hibák: ' . implode(' | ', (array)$result['errors']);
            }
            flash_set('msg', $msg);
            header('Location: /material_identifiers.php?material_id=' . $materialId . '&warehouse_id=' . $warehouseId);
            exit;
        }

        if ($action === 'delete_identifier') {
            $identifierId = (int)($_POST['identifier_id'] ?? 0);
            warehouse_material_identifier_delete($config, $identifierId);
            flash_set('msg', 'Az azonosító törölve.');
            $query = http_build_query(array_filter([
                'material_id' => trim((string)($_POST['return_material_filter'] ?? '__none__')),
                'warehouse_id' => trim((string)($_POST['return_warehouse_filter'] ?? '__none__')),
                'q' => trim((string)($_POST['return_q'] ?? '')),
                'status' => trim((string)($_POST['return_status'] ?? 'in_stock')),
                'include_archived' => (int)($_POST['return_include_archived'] ?? 0) === 1 ? 1 : null,
                'page' => (int)($_POST['return_page'] ?? 1),
            ], static fn($v): bool => !($v === '' || $v === null || $v === 0 || $v === '0')));
            header('Location: /material_identifiers.php' . ($query !== '' ? '?' . $query : ''));
            exit;
        }
    } catch (Throwable $e) {
        flash_set('err', $e->getMessage());
        $fallback = http_build_query(array_filter([
            'material_id' => (int)($_POST['material_id'] ?? $_POST['return_material_id'] ?? 0),
            'warehouse_id' => (int)($_POST['warehouse_id'] ?? $_POST['return_warehouse_id'] ?? 0),
        ], static fn($v): bool => $v > 0));
        header('Location: /material_identifiers.php' . ($fallback !== '' ? '?' . $fallback : ''));
        exit;
    }
}

$msg = flash_get('msg');
$err = flash_get('err');
$bulkErrors = $_SESSION['_flash_identifier_bulk_errors'] ?? [];
unset($_SESSION['_flash_identifier_bulk_errors']);
if (!is_array($bulkErrors)) {
    $bulkErrors = [];
}
// A szűrhető / lapozható lista központi helperből jön, hogy ugyanaz a logika
// maradjon érvényben a törlés utáni visszalépésnél is.
$list = warehouse_material_identifiers_list($config, $_GET);
$currentMaterialId = (int)($list['material_id'] ?? ($_GET['material_id'] ?? 0));
$currentWarehouseId = (int)($list['warehouse_id'] ?? ($_GET['warehouse_id'] ?? 0));
$overview = ($featureReady && $currentMaterialId > 0 && $currentWarehouseId > 0)
    ? warehouse_material_identifier_overview($config, $currentWarehouseId, $currentMaterialId)
    : null;
$highlightId = (int)($_GET['highlight'] ?? 0);

$queryBase = [
    'material_id' => $list['material_filter'] ?? '__none__',
    'warehouse_id' => $list['warehouse_filter'] ?? '__none__',
    'q' => $list['q'] ?? '',
    'status' => $list['status'] ?? 'in_stock',
    'include_archived' => (int)($list['include_archived'] ?? 0) === 1 ? 1 : null,
    'page' => $list['page'] ?? 1,
];
$buildQuery = static function (array $overrides = []) use ($queryBase): string {
    $params = array_merge($queryBase, $overrides);
    return http_build_query(array_filter($params, static function ($value): bool {
        return !($value === '' || $value === null || $value === 0 || $value === '0');
    }));
};

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0">Anyagazonosítók</h1>
    <div class="text-secondary small">Egyedi azonosítós anyagok (pl. sorozatszám, IMEI, gyári szám) nyilvántartása raktáranként.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-sm btn-outline-secondary" href="/materials.php">Anyagtörzs</a>
    <a class="btn btn-sm btn-outline-secondary" href="/identifier_staging.php">Ideiglenes beolvasás</a>
    <a class="btn btn-sm btn-outline-secondary" href="/stock.php">Készlet</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!empty($bulkErrors)): ?>
<div class="alert alert-warning">
  <div class="fw-semibold mb-2">Hibalista a gyors rögzítéshez</div>
  <ul class="mb-0 small">
    <?php foreach ($bulkErrors as $bulkError): ?>
    <li><?= h((string)$bulkError) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!$featureReady): ?>
<div class="alert alert-warning">Az egyedi azonosítós bővítés adatbázis része még nincs telepítve. Futtasd a <code>database/warehousemgr_update_step12_material_identifiers.sql</code> fájlt.</div>
<?php else: ?>

<?php if ($overview): ?>
<div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-3">
  <div>
    <div class="fw-semibold"><code><?= h((string)($overview['material']['sku'] ?? '')) ?></code> · <?= h((string)($overview['material']['name'] ?? '')) ?></div>
    <div class="small text-secondary">Raktár: <?= h((string)($overview['warehouse']['name'] ?? '')) ?><?php if (!empty($overview['warehouse']['code'])): ?> (<code><?= h((string)$overview['warehouse']['code']) ?></code>)<?php endif; ?></div>
  </div>
  <div class="small text-end">
    <div><strong>Készlet:</strong> <?= h(warehouse_format_quantity($overview['stock_quantity'] ?? 0)) ?> <?= h((string)($overview['material']['unit'] ?? '')) ?></div>
    <div><strong>Rögzített azonosítók:</strong> <?= (int)($overview['tracked_count'] ?? 0) ?></div>
    <div><strong>Még rögzíthető:</strong> <?= (int)($overview['available_slots'] ?? 0) ?></div>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm mb-4">
      <div class="card-header">
        <div class="fw-semibold">Kézi felvitel</div>
        <div class="text-secondary small">Rögzíts egyedi kódot vagy összetartozó kódpárt a kiválasztott anyaghoz és raktárhoz.</div>
      </div>
      <div class="card-body">
        <?php if ($manageableWarehouses === []): ?>
          <div class="alert alert-warning mb-0">Nincs olyan raktárad, amelyhez kezelési jogosultságod lenne.</div>
        <?php else: ?>
        <form method="post" class="row g-3" id="manualIdentifierForm">
          <input type="hidden" name="action" value="add_identifier">
          <div class="col-12">
            <label class="form-label">Mód</label>
            <select class="form-select" name="scan_mode" id="manual_identifier_mode">
              <option value="single" selected>Egyszeres kód</option>
              <option value="pair">Páros kód</option>
            </select>
            <div class="form-text">Páros módban két összetartozó kód kerül egy rekordba.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Anyag</label>
            <select class="form-select" name="material_id" required>
              <option value="">— válassz anyagot —</option>
              <?php foreach ($materials as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= $currentMaterialId === (int)$m['id'] ? 'selected' : '' ?>><?= h((string)$m['sku']) ?> · <?= h((string)$m['name']) ?><?= !empty($m['identifier_label']) ? ' (' . h((string)$m['identifier_label']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Raktár</label>
            <select class="form-select" name="warehouse_id" required>
              <option value="">— válassz raktárat —</option>
              <?php foreach ($manageableWarehouses as $w): ?>
              <option value="<?= (int)$w['id'] ?>" <?= $currentWarehouseId === (int)$w['id'] ? 'selected' : '' ?>><?= h((string)$w['name']) ?><?php if (!empty($w['code'])): ?> (<?= h((string)$w['code']) ?>)<?php endif; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label" id="manual_identifier_primary_label">Azonosító</label>
            <input class="form-control" name="identifier_value" required placeholder="pl. SN123456 / IMEI / gyári szám">
          </div>
          <div class="col-12 d-none" id="manual_identifier_secondary_wrap">
            <label class="form-label">Második kód</label>
            <input class="form-control" name="secondary_identifier_value" placeholder="pl. belső kód / második azonosító">
            <div class="form-text">A két kód nem lehet azonos, és egyik sem szerepelhet már az adatbázisban.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Megjegyzés</label>
            <input class="form-control" name="note" placeholder="opcionális megjegyzés">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary" type="submit">Rögzítés</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm mb-4">
      <div class="card-header">
        <div class="fw-semibold">Gyors rögzítés / vonalkódolvasó</div>
        <div class="text-secondary small">Egyszeres és páros módban is használható. Páros módban csak a második Enter után kerül új sor a listába.</div>
      </div>
      <div class="card-body">
        <?php if ($manageableWarehouses === []): ?>
          <div class="alert alert-warning mb-0">Nincs olyan raktárad, amelyhez kezelési jogosultságod lenne.</div>
        <?php else: ?>
        <form method="post" class="row g-3" id="bulkIdentifierForm">
          <input type="hidden" name="action" value="bulk_add_identifiers">
          <div class="col-12">
            <label class="form-label">Anyag</label>
            <select class="form-select" name="material_id" required>
              <option value="">— válassz anyagot —</option>
              <?php foreach ($materials as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= $currentMaterialId === (int)$m['id'] ? 'selected' : '' ?>><?= h((string)$m['sku']) ?> · <?= h((string)$m['name']) ?><?= !empty($m['identifier_label']) ? ' (' . h((string)$m['identifier_label']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Raktár</label>
            <select class="form-select" name="warehouse_id" required>
              <option value="">— válassz raktárat —</option>
              <?php foreach ($manageableWarehouses as $w): ?>
              <option value="<?= (int)$w['id'] ?>" <?= $currentWarehouseId === (int)$w['id'] ? 'selected' : '' ?>><?= h((string)$w['name']) ?><?php if (!empty($w['code'])): ?> (<?= h((string)$w['code']) ?>)<?php endif; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Beolvasási mód</label>
            <select class="form-select" name="scan_mode" id="bulk_identifier_mode">
              <option value="single" selected>Egyszeres kód</option>
              <option value="pair">Páros kód</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Gyors scanner bemenet</label>
            <input class="form-control" type="text" id="bulk_identifier_scan_input" autocomplete="off" placeholder="ide olvasson a vonalkódolvasó">
            <div class="form-text" id="bulkIdentifierScanHelp">Egyszeres módban minden Enter után új sor készül.</div>
          </div>
          <div class="col-12">
            <div class="small text-secondary mb-2" id="bulkIdentifierPending">Páros módban még nincs függő első kód.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Beolvasott azonosítók</label>
            <textarea class="form-control font-monospace" name="identifier_lines" id="identifier_lines" rows="10" placeholder="egyszeres mód: egy sor = egy kód&#10;páros mód: egy sor = első kód[TAB]második kód" required></textarea>
            <div class="form-text">A rendszer ellenőrzi a mostani listán belüli ismétlődéseket, valamint az adatbázisban már létező első és második kódokat is.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Közös megjegyzés</label>
            <input class="form-control" name="bulk_note" placeholder="opcionális közös megjegyzés az új azonosítókhoz">
          </div>
          <div class="col-12">
            <div class="small border rounded p-2 bg-light" id="bulkIdentifierSummary">
              <div><strong>Rögzíthető sorok:</strong> <span data-role="lineCount">0</span></div>
              <div><strong>Egyedi kódok:</strong> <span data-role="uniqueCount">0</span></div>
              <div><strong>Duplikált kódok a mostani listában:</strong> <span data-role="duplicateCount">0</span></div>
            </div>
          </div>
          <div class="col-12 d-flex justify-content-between gap-2 flex-wrap">
            <button class="btn btn-outline-secondary" type="button" id="clearIdentifierLines">Mező ürítése</button>
            <button class="btn btn-primary" type="submit">Rögzítés</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm mb-4">
      <div class="card-header">
        <div class="fw-semibold">CSV import</div>
        <div class="text-secondary small">Egyszeres módban: 1. oszlop = azonosító, 2. oszlop = megjegyzés. Páros módban: 1. oszlop = első kód, 2. oszlop = második kód, 3. oszlop = megjegyzés.</div>
      </div>
      <div class="card-body">
        <?php if ($manageableWarehouses === []): ?>
          <div class="alert alert-warning mb-0">Nincs olyan raktárad, amelyhez kezelési jogosultságod lenne.</div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="row g-3" id="identifierCsvForm">
          <input type="hidden" name="action" value="import_identifiers_csv">
          <div class="col-12">
            <label class="form-label">Anyag</label>
            <select class="form-select" name="material_id" required>
              <option value="">— válassz anyagot —</option>
              <?php foreach ($materials as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= $currentMaterialId === (int)$m['id'] ? 'selected' : '' ?>><?= h((string)$m['sku']) ?> · <?= h((string)$m['name']) ?><?= !empty($m['identifier_label']) ? ' (' . h((string)$m['identifier_label']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Raktár</label>
            <select class="form-select" name="warehouse_id" required>
              <option value="">— válassz raktárat —</option>
              <?php foreach ($manageableWarehouses as $w): ?>
              <option value="<?= (int)$w['id'] ?>" <?= $currentWarehouseId === (int)$w['id'] ? 'selected' : '' ?>><?= h((string)$w['name']) ?><?php if (!empty($w['code'])): ?> (<?= h((string)$w['code']) ?>)<?php endif; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Import mód</label>
            <select class="form-select" name="scan_mode" id="csv_identifier_mode">
              <option value="single" selected>Egyszeres kód</option>
              <option value="pair">Páros kód</option>
            </select>
            <div class="form-text" id="csvIdentifierHelp">Egyszeres módban a második oszlop opcionális megjegyzés lehet.</div>
          </div>
          <div class="col-12">
            <label class="form-label">CSV fájl</label>
            <input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-outline-primary" type="submit">CSV import</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-12 col-xl-8">
    <div class="card shadow-sm mb-4">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <div class="fw-semibold">Rögzített azonosítók</div>
          <div class="text-secondary small">Kereshető, szűrhető lista az anyagokhoz felvitt egyedi azonosítókról.</div>
        </div>
      </div>
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
          <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Anyag</label>
            <select class="form-select form-select-sm" name="material_id">
              <option value="__none__" <?= ($list['material_filter'] ?? '__none__') === '__none__' ? 'selected' : '' ?>>— egyik sem —</option>
              <option value="__all__" <?= ($list['material_filter'] ?? '__none__') === '__all__' ? 'selected' : '' ?>>— mindegyik —</option>
              <?php foreach ($filterMaterials as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= (string)($list['material_filter'] ?? '__none__') === (string)(int)$m['id'] ? 'selected' : '' ?>><?= h((string)$m['sku']) ?> · <?= h((string)$m['name']) ?><?php if ($archiveFeatureReady && (int)($m['is_archived'] ?? 0) === 1): ?> · [archív]<?php endif; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Raktár</label>
            <select class="form-select form-select-sm" name="warehouse_id">
              <option value="__none__" <?= ($list['warehouse_filter'] ?? '__none__') === '__none__' ? 'selected' : '' ?>>— egyik sem —</option>
              <option value="__all__" <?= ($list['warehouse_filter'] ?? '__none__') === '__all__' ? 'selected' : '' ?>>— mindegyik —</option>
              <?php foreach ($accessibleWarehouses as $w): ?>
              <option value="<?= (int)$w['id'] ?>" <?= (string)($list['warehouse_filter'] ?? '__none__') === (string)(int)$w['id'] ? 'selected' : '' ?>><?= h((string)$w['name']) ?><?php if (!empty($w['code'])): ?> (<?= h((string)$w['code']) ?>)<?php endif; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Státusz</label>
            <select class="form-select form-select-sm" name="status">
              <option value="in_stock" <?= ($list['status'] ?? '') === 'in_stock' ? 'selected' : '' ?>>Raktáron</option>
              <option value="all" <?= ($list['status'] ?? '') === 'all' ? 'selected' : '' ?>>Mindegyik</option>
              <option value="issued" <?= ($list['status'] ?? '') === 'issued' ? 'selected' : '' ?>>Kiadva</option>
              <option value="archived" <?= ($list['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archiválva</option>
            </select>
          </div>
          <div class="col-12 col-md-8">
            <label class="form-label small mb-1">Keresés</label>
            <input class="form-control form-control-sm" type="text" name="q" value="<?= h((string)($list['q'] ?? '')) ?>" placeholder="Azonosító / cikkszám / megnevezés / raktár / megjegyzés">
          </div>
          <?php if ($archiveFeatureReady): ?>
          <div class="col-auto d-flex align-items-end">
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" id="mi_include_archived" name="include_archived" value="1" <?= ((int)($list['include_archived'] ?? 0) === 1) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="mi_include_archived">Archív is</label>
            </div>
          </div>
          <?php endif; ?>
          <div class="col-auto">
            <button class="btn btn-sm btn-primary" type="submit">Szűrés</button>
          </div>
          <div class="col-auto">
            <a class="btn btn-sm btn-outline-secondary" href="/material_identifiers.php">Alaphelyzet</a>
          </div>
        </form>

        <div class="small text-secondary mb-2">
          <?php if (($list['total'] ?? 0) > 0): ?>
            Tétel: <?= (int)$list['offset'] + 1 ?>–<?= (int)min(($list['offset'] ?? 0) + count($list['rows'] ?? []), $list['total']) ?> / <?= (int)$list['total'] ?> · Oldal: <?= (int)$list['page'] ?> / <?= (int)$list['pages'] ?> · 50 tétel / oldal<?php if ($archiveFeatureReady && (int)($list['include_archived'] ?? 0) === 1): ?> · archívval együtt<?php endif; ?>
          <?php else: ?>
            Nincs találat.
          <?php endif; ?>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th>Anyag</th>
                <th>Raktár</th>
                <th>Azonosító</th>
                <th>Státusz</th>
                <th>Megjegyzés</th>
                <th>Rögzítve</th>
                <th class="text-end">Művelet</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($list['rows'] ?? []) as $row): ?>
              <tr<?= $highlightId === (int)$row['id'] ? ' class="table-success"' : '' ?>>
                <td>
                  <div><code><?= h((string)$row['sku']) ?></code></div>
                  <div class="fw-semibold"><?= h((string)$row['material_name']) ?></div>
                  <?php if ($archiveFeatureReady && (((int)($row['material_is_archived'] ?? 0) === 1) || ((int)($row['identifier_is_archived'] ?? 0) === 1))): ?><div class="small mt-1"><span class="badge bg-warning text-dark">Archivált</span></div><?php endif; ?>
                </td>
                <td>
                  <div><?= h((string)$row['warehouse_name']) ?></div>
                  <div class="small text-secondary"><code><?= h((string)$row['warehouse_code']) ?></code></div>
                </td>
                <td><code><?= h((string)$row['identifier_value']) ?></code><?php if (!empty($row['secondary_identifier_value'])): ?> <span class="text-secondary">↔</span> <code><?= h((string)$row['secondary_identifier_value']) ?></code><?php endif; ?></td>
                <td>
                  <?php if ((string)$row['status'] === 'in_stock'): ?>
                    <span class="badge bg-success">Raktáron</span>
                  <?php elseif ((string)$row['status'] === 'issued'): ?>
                    <span class="badge bg-warning text-dark">Kiadva</span>
                  <?php else: ?>
                    <span class="badge bg-secondary"><?= h((string)$row['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= h((string)($row['note'] ?? '')) ?></td>
                <td><?= h((string)($row['created_at'] ?? '')) ?></td>
                <td class="text-end text-nowrap">
                  <?php if (warehouse_user_can_manage_warehouse($config, (int)$row['warehouse_id'])): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törölni szeretnéd ezt az azonosítót?\n\n<?= h(addslashes((string)$row['identifier_value'])) ?>');">
                    <input type="hidden" name="action" value="delete_identifier">
                    <input type="hidden" name="identifier_id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="return_material_filter" value="<?= h((string)($list['material_filter'] ?? '__none__')) ?>">
                    <input type="hidden" name="return_warehouse_filter" value="<?= h((string)($list['warehouse_filter'] ?? '__none__')) ?>">
                    <input type="hidden" name="return_q" value="<?= h((string)($list['q'] ?? '')) ?>">
                    <input type="hidden" name="return_status" value="<?= h((string)($list['status'] ?? 'in_stock')) ?>">
                    <input type="hidden" name="return_include_archived" value="<?= ((int)($list['include_archived'] ?? 0) === 1) ? '1' : '0' ?>">
                    <input type="hidden" name="return_page" value="<?= (int)($list['page'] ?? 1) ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Törlés</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($list['rows'])): ?>
              <tr><td colspan="7" class="text-secondary"><?php if (($list['material_filter'] ?? '__none__') === '__none__' || ($list['warehouse_filter'] ?? '__none__') === '__none__'): ?>Válassz anyagot és raktárat, vagy állítsd az egyiket „Mindegyik” értékre.<?php else: ?>Még nincs rögzített egyedi azonosító.<?php endif; ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if (($list['pages'] ?? 1) > 1): ?>
        <nav aria-label="Azonosítók lapozás" class="mt-3">
          <ul class="pagination pagination-sm mb-0 flex-wrap">
            <?php $prevPage = max(1, (int)$list['page'] - 1); ?>
            <li class="page-item <?= (int)$list['page'] <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="/material_identifiers.php?<?= h($buildQuery(['page' => $prevPage])) ?>">«</a>
            </li>
            <?php for ($p = max(1, (int)$list['page'] - 2); $p <= min((int)$list['pages'], (int)$list['page'] + 2); $p++): ?>
            <li class="page-item <?= $p === (int)$list['page'] ? 'active' : '' ?>">
              <a class="page-link" href="/material_identifiers.php?<?= h($buildQuery(['page' => $p])) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <?php $nextPage = min((int)$list['pages'], (int)$list['page'] + 1); ?>
            <li class="page-item <?= (int)$list['page'] >= (int)$list['pages'] ? 'disabled' : '' ?>">
              <a class="page-link" href="/material_identifiers.php?<?= h($buildQuery(['page' => $nextPage])) ?>">»</a>
            </li>
          </ul>
        </nav>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
(() => {
  const normalize = (value) => String(value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('hu-HU');

  const manualMode = document.getElementById('manual_identifier_mode');
  const manualPrimaryLabel = document.getElementById('manual_identifier_primary_label');
  const manualSecondaryWrap = document.getElementById('manual_identifier_secondary_wrap');
  const manualSecondaryInput = manualSecondaryWrap ? manualSecondaryWrap.querySelector('input[name="secondary_identifier_value"]') : null;

  const refreshManualMode = () => {
    if (!manualMode || !manualPrimaryLabel || !manualSecondaryWrap) {
      return;
    }
    const pair = manualMode.value === 'pair';
    manualPrimaryLabel.textContent = pair ? 'Első kód' : 'Azonosító';
    manualSecondaryWrap.classList.toggle('d-none', !pair);
    if (manualSecondaryInput) {
      manualSecondaryInput.required = pair;
      if (!pair) {
        manualSecondaryInput.value = '';
      }
    }
  };
  if (manualMode) {
    manualMode.addEventListener('change', refreshManualMode);
    refreshManualMode();
  }

  const modeSelect = document.getElementById('bulk_identifier_mode');
  const scanInput = document.getElementById('bulk_identifier_scan_input');
  const textarea = document.getElementById('identifier_lines');
  const pendingNode = document.getElementById('bulkIdentifierPending');
  const clearBtn = document.getElementById('clearIdentifierLines');
  const scanHelp = document.getElementById('bulkIdentifierScanHelp');
  const summary = document.getElementById('bulkIdentifierSummary');

  let pendingFirst = '';

  const appendLine = (value) => {
    const line = String(value || '').trim();
    if (!line || !textarea) {
      return;
    }
    const current = (textarea.value || '').replace(/\s+$/u, '');
    textarea.value = current ? current + "\n" + line : line;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  };

  const refreshPending = () => {
    if (!modeSelect || !pendingNode) {
      return;
    }
    if (modeSelect.value === 'pair') {
      if (pendingFirst) {
        pendingNode.textContent = 'Páros mód: 1/2 kód beolvasva – várja a második kódot (' + pendingFirst + ').';
        pendingNode.className = 'small text-warning mb-2';
      } else {
        pendingNode.textContent = 'Páros módban még nincs függő első kód.';
        pendingNode.className = 'small text-secondary mb-2';
      }
      if (scanHelp) {
        scanHelp.textContent = 'Páros módban az első beolvasás még nem hoz létre sort. A második Enter után kerül be a pár a listába.';
      }
    } else {
      pendingNode.textContent = 'Egyszeres módban minden Enter után azonnal új sor készül.';
      pendingNode.className = 'small text-secondary mb-2';
      if (scanHelp) {
        scanHelp.textContent = 'Egyszeres módban minden Enter után új sor készül a listában.';
      }
    }
  };

  const parseRowsForSummary = () => {
    if (!textarea) {
      return { rows: [], codes: [] };
    }
    const raw = textarea.value.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    const rows = raw.split('\n').map(v => v.trim()).filter(v => v !== '');
    const codes = [];
    rows.forEach((row) => {
      const parts = row.split(/\t+|\s*\|\s*|\s*;\s*/u).map(v => v.trim()).filter(Boolean);
      if (parts.length > 0) {
        codes.push(parts[0]);
      }
      if (parts.length > 1) {
        codes.push(parts[1]);
      }
    });
    return { rows, codes };
  };

  const refreshSummary = () => {
    if (!summary) {
      return;
    }
    const lineCountEl = summary.querySelector('[data-role="lineCount"]');
    const uniqueCountEl = summary.querySelector('[data-role="uniqueCount"]');
    const duplicateCountEl = summary.querySelector('[data-role="duplicateCount"]');
    const parsed = parseRowsForSummary();
    const seen = new Set();
    let duplicates = 0;
    parsed.codes.forEach((code) => {
      const key = normalize(code);
      if (!key) {
        return;
      }
      if (seen.has(key)) {
        duplicates += 1;
      } else {
        seen.add(key);
      }
    });
    if (lineCountEl) lineCountEl.textContent = String(parsed.rows.length);
    if (uniqueCountEl) uniqueCountEl.textContent = String(seen.size);
    if (duplicateCountEl) duplicateCountEl.textContent = String(duplicates);
  };

  if (modeSelect) {
    modeSelect.addEventListener('change', () => {
      pendingFirst = '';
      refreshPending();
      if (scanInput) {
        scanInput.focus();
      }
      refreshSummary();
    });
  }

  if (scanInput) {
    scanInput.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') {
        return;
      }
      event.preventDefault();
      const value = (scanInput.value || '').trim();
      if (!value) {
        return;
      }

      if (modeSelect && modeSelect.value === 'pair') {
        if (!pendingFirst) {
          pendingFirst = value;
        } else if (normalize(pendingFirst) === normalize(value)) {
          pendingNode.textContent = 'Hiba: a két kód nem lehet azonos (' + value + ').';
          pendingNode.className = 'small text-danger mb-2';
        } else {
          appendLine(pendingFirst + "\t" + value);
          pendingFirst = '';
        }
      } else {
        appendLine(value);
      }

      scanInput.value = '';
      refreshPending();
      refreshSummary();
    });
  }

  if (textarea) {
    textarea.addEventListener('input', refreshSummary);
    textarea.addEventListener('change', refreshSummary);
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      pendingFirst = '';
      if (textarea) {
        textarea.value = '';
      }
      if (scanInput) {
        scanInput.value = '';
        scanInput.focus();
      }
      refreshPending();
      refreshSummary();
    });
  }

  refreshPending();
  refreshSummary();

  const csvMode = document.getElementById('csv_identifier_mode');
  const csvHelp = document.getElementById('csvIdentifierHelp');
  const refreshCsvMode = () => {
    if (!csvMode || !csvHelp) {
      return;
    }
    csvHelp.textContent = csvMode.value === 'pair'
      ? 'Páros módban az 1. oszlop = első kód, a 2. oszlop = második kód, a 3. oszlop opcionális megjegyzés.'
      : 'Egyszeres módban az 1. oszlop = azonosító, a 2. oszlop opcionális megjegyzés.';
  };
  if (csvMode) {
    csvMode.addEventListener('change', refreshCsvMode);
    refreshCsvMode();
  }
})();
</script>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

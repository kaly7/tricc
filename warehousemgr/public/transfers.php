<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Raktárközi és külsős átadások fő oldala.
 * Itt történik a belső átadás, a partneres kiadás / visszavétel, valamint az elfogadás / elutasítás.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Raktárközi átadások';
$loggedIn = true;

$allWarehouses = warehouse_all($config);
$manageableWarehouses = warehouse_internal_manageable_warehouses($config, true);
$allActiveWarehouses = array_values(array_filter($allWarehouses, static function (array $row): bool {
    return (int)($row['is_active'] ?? 0) === 1 && warehouse_type_normalize((string)($row['warehouse_type'] ?? 'internal')) === 'internal';
}));
$externalTargetWarehouses = array_values(array_filter($allWarehouses, static function (array $row): bool {
    return (int)($row['is_active'] ?? 0) === 1 && warehouse_type_normalize((string)($row['warehouse_type'] ?? 'internal')) === 'external_partner';
}));
$activeMaterials = warehouse_material_select_options($config, true);
$canManageAny = count($manageableWarehouses) > 0;
$canManageExternal = $canManageAny && count($externalTargetWarehouses) > 0;
$transferSourceWarehouseIds = array_values(array_unique(array_merge(
    array_map(static fn(array $row): int => (int)$row['id'], $manageableWarehouses),
    array_map(static fn(array $row): int => (int)$row['id'], $externalTargetWarehouses)
)));
$transferStockMap = warehouse_transfer_available_stock_map($config, $transferSourceWarehouseIds);
$transferIdentifierMap = warehouse_transfer_available_identifier_map($config, $transferSourceWarehouseIds);
$transferItemsEnabled = warehouse_transfer_items_table_exists($config);
$identifierFeatureReady = warehouse_material_identifier_feature_ready($config);
$transferItemIdentifiersEnabled = warehouse_transfer_item_identifiers_table_exists($config);
$externalSignatureEnabled = warehouse_transfer_signature_columns_exist($config);

// A kliensoldali azonosító-ellenőrző / áthelyező műveletek JSON választ várnak,
// ezért itt közös segédfüggvényt használunk a konzisztens válaszformátumhoz.
$transferJsonResponse = static function (array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

// Az oldal egyszerre kezeli a belső átadásokat, a partneres kiadást / visszavételt,
// valamint az elfogadás / elutasítás és a szkennelt azonosító segédműveleteket.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $isJsonAction = in_array($action, ['lookup_scanned_identifier', 'relocate_scanned_identifier'], true);

    try {
        if ($action === 'lookup_scanned_identifier') {
            $sourceWarehouseId = (int)($_POST['source_warehouse_id'] ?? 0);
            $materialId = (int)($_POST['material_id'] ?? 0);
            $identifierValue = (string)($_POST['identifier_value'] ?? '');
            $lookup = warehouse_material_identifier_lookup_for_transfer($config, $sourceWarehouseId, $materialId, $identifierValue);
            $transferJsonResponse([
                'ok' => true,
                'lookup' => $lookup,
            ]);
        }

        if ($action === 'relocate_scanned_identifier') {
            $sourceWarehouseId = (int)($_POST['source_warehouse_id'] ?? 0);
            $identifierId = (int)($_POST['identifier_id'] ?? 0);
            $materialId = (int)($_POST['material_id'] ?? 0);
            $note = (string)($_POST['note'] ?? '');
            $result = warehouse_transfer_relocate_identifier_to_source($config, $identifierId, $sourceWarehouseId, $materialId, $note);
            $transferJsonResponse([
                'ok' => true,
                'message' => 'Az azonosító átkerült a kiválasztott forrásraktárba.',
                'result' => $result,
            ]);
        }

        if ($action === 'create_transfer') {
            if (!$canManageAny) {
                throw new RuntimeException('Nincs olyan raktárad, ahonnan átadást indíthatsz.');
            }
            if (!$transferItemsEnabled) {
                throw new RuntimeException('A többtételes átadáshoz előbb futtasd a stock_transfer_items frissítő SQL-t.');
            }
            $itemsInput = $_POST['items'] ?? [];
            if (!is_array($itemsInput)) {
                $itemsInput = [];
            }
            $transferId = warehouse_transfer_create_batch(
                $config,
                (int)($_POST['source_warehouse_id'] ?? 0),
                (int)($_POST['target_warehouse_id'] ?? 0),
                $itemsInput,
                (string)($_POST['reference_no'] ?? ''),
                (string)($_POST['note'] ?? '')
            );
            flash_set('msg', 'Átadás létrehozva: ' . warehouse_transfer_reference($transferId));
            header('Location: /transfers.php');
            exit;
        }

        if ($action === 'create_external_transfer') {
            if (!$canManageExternal) {
                throw new RuntimeException('Nincs olyan belső raktárad vagy külső partner raktárad, amellyel külsős átadást indíthatnál.');
            }
            if (!$transferItemsEnabled) {
                throw new RuntimeException('A többtételes átadáshoz előbb futtasd a stock_transfer_items frissítő SQL-t.');
            }
            $itemsInput = $_POST['external_items'] ?? [];
            if (!is_array($itemsInput)) {
                $itemsInput = [];
            }
            $transferId = warehouse_external_transfer_create_batch(
                $config,
                (int)($_POST['external_source_warehouse_id'] ?? 0),
                (int)($_POST['external_target_warehouse_id'] ?? 0),
                $itemsInput,
                isset($_POST['external_auto_reference']) && (string)($_POST['external_auto_reference'] ?? '') === '1',
                (string)($_POST['external_reference_no'] ?? ''),
                (string)($_POST['external_receiver_name'] ?? ''),
                (string)($_POST['external_receiver_phone'] ?? ''),
                (string)($_POST['external_receiver_email'] ?? ''),
                (string)($_POST['external_project_no'] ?? ''),
                (string)($_POST['external_note'] ?? ''),
                (string)($_POST['external_signature_data'] ?? '')
            );
            $directionMode = (string)($_POST['external_direction_mode'] ?? 'outbound');
            $flashLabel = $directionMode === 'inbound' ? 'Külső partnertől visszavétel rögzítve: ' : 'Külsős kiadás rögzítve: ';
            flash_set('msg', $flashLabel . warehouse_transfer_reference($transferId));
            header('Location: /transfers.php');
            exit;
        }

        if ($action === 'accept_transfer') {
            warehouse_transfer_accept(
                $config,
                (int)($_POST['transfer_id'] ?? 0),
                (string)($_POST['decision_note'] ?? '')
            );
            flash_set('msg', 'Az átadás elfogadva.');
            header('Location: /transfers.php?scope=incoming&status=pending');
            exit;
        }

        if ($action === 'reject_transfer') {
            warehouse_transfer_reject(
                $config,
                (int)($_POST['transfer_id'] ?? 0),
                (string)($_POST['decision_note'] ?? '')
            );
            flash_set('msg', 'Az átadás elutasítva.');
            header('Location: /transfers.php?scope=incoming&status=pending');
            exit;
        }

        if ($action === 'cancel_transfer') {
            warehouse_transfer_cancel(
                $config,
                (int)($_POST['transfer_id'] ?? 0),
                (string)($_POST['decision_note'] ?? '')
            );
            flash_set('msg', 'Az átadás törölve.');
            header('Location: /transfers.php?scope=outgoing&status=pending');
            exit;
        }

        throw new RuntimeException('Ismeretlen művelet.');
    } catch (Throwable $e) {
        if ($isJsonAction) {
            $transferJsonResponse([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        flash_set('err', $e->getMessage());
        header('Location: /transfers.php');
        exit;
    }
}

$msg = flash_get('msg');
$err = flash_get('err');
// A listaoldal külön helperen keresztül számolja a lapozást és a státusz szerinti szűrést.
$filters = warehouse_transfer_filter_values($_GET);
$perPage = 20;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalTransfers = warehouse_transfer_count($config, $filters);
$totalPages = max(1, (int)ceil($totalTransfers / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$listOffset = ($currentPage - 1) * $perPage;
$rows = warehouse_transfer_search($config, $filters, $perPage, $listOffset);
$listFrom = $totalTransfers > 0 ? ($listOffset + 1) : 0;
$listTo = $totalTransfers > 0 ? ($listOffset + count($rows)) : 0;
$filterWarehouses = warehouse_accessible_warehouses($config, false);
if (warehouse_module_admin($config)) {
    $filterWarehouses = warehouse_all($config);
}
$categoryOptions = warehouse_material_category_options($config, true);

$queryBase = [
    'scope' => $filters['scope'],
    'status' => $filters['status'],
    'warehouse_id' => $filters['warehouse_id'],
    'category_name' => $filters['category_name'] ?? '',
    'q' => $filters['q'],
];
$buildQuery = static function (array $overrides = []) use ($queryBase): string {
    $params = array_merge($queryBase, $overrides);
    return http_build_query(array_filter($params, static function ($value): bool {
        return !($value === '' || $value === null || $value === 0 || $value === '0');
    }));
};

$paginationWindow = 2;
$pageNumbers = [];
$startPage = max(1, $currentPage - $paginationWindow);
$endPage = min($totalPages, $currentPage + $paginationWindow);
for ($pageNo = $startPage; $pageNo <= $endPage; $pageNo++) {
    $pageNumbers[] = $pageNo;
}
$renderTransferPagination = static function () use ($totalPages, $currentPage, $buildQuery): void {
    if ($totalPages <= 1) {
        return;
    }
    ?>
    <nav aria-label="Átadások lapozása">
      <ul class="pagination pagination-sm flex-wrap mb-0">
        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="/transfers.php?<?= h($buildQuery(['page' => max(1, $currentPage - 1)])) ?>">&laquo;</a></li>
        <?php for ($pageNo = max(1, $currentPage - 2); $pageNo <= min($totalPages, $currentPage + 2); $pageNo++): ?>
          <li class="page-item <?= $pageNo === $currentPage ? 'active' : '' ?>"><a class="page-link" href="/transfers.php?<?= h($buildQuery(['page' => $pageNo])) ?>"><?= (int)$pageNo ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="/transfers.php?<?= h($buildQuery(['page' => min($totalPages, $currentPage + 1)])) ?>">&raquo;</a></li>
      </ul>
    </nav>
    <?php
};

if ((string)($_GET['export'] ?? '') === 'csv') {
    $exportTransfers = warehouse_transfer_search($config, $filters, 10000);
    $exportRows = [];
    foreach ($exportTransfers as $row) {
        $isExternalTransfer = warehouse_transfer_type_normalize((string)($row['transfer_type'] ?? 'internal')) === 'external';
        $sourceType = warehouse_type_normalize((string)($row['source_warehouse_type'] ?? 'internal'));
        $targetType = warehouse_type_normalize((string)($row['target_warehouse_type'] ?? 'internal'));
        $type = $isExternalTransfer
            ? (($sourceType === 'external_partner' && $targetType === 'internal') ? 'Külső partnertől visszavétel' : 'Külsős partner kiadás')
            : 'Belső raktárközi átadás';
        $itemsText = [];
        foreach (($row['items'] ?? []) as $item) {
            $itemText = trim((string)($item['sku'] ?? '') . ' ' . (string)($item['material_name'] ?? '')) . ' - ' . warehouse_format_quantity($item['quantity'] ?? 0) . ' ' . (string)($item['unit'] ?? '');
            $identifierValues = array_values(array_map(static fn(array $identifier): string => warehouse_material_identifier_display_value($identifier), (array)($item['identifiers'] ?? [])));
            if ($identifierValues !== []) {
                $itemText .= ' [' . warehouse_material_identifier_value_label((array)$item) . ': ' . implode(', ', $identifierValues) . ']';
            }
            $itemsText[] = $itemText;
        }
        $exportRows[] = [
            'Azonosító' => warehouse_transfer_reference((int)($row['id'] ?? 0)),
            'Típus' => $type,
            'Állapot' => warehouse_transfer_status_label((string)($row['status'] ?? '')),
            'Szállítólevél / Hivatkozás' => (string)($row['reference_no'] ?? ''),
            'Forrás raktár' => (string)($row['source_warehouse_name'] ?? ''),
            'Forrás kód' => (string)($row['source_warehouse_code'] ?? ''),
            'Cél raktár' => (string)($row['target_warehouse_name'] ?? ''),
            'Cél kód' => (string)($row['target_warehouse_code'] ?? ''),
            'Partner' => (string)($row['partner_name'] ?? ''),
            'Átvevő' => (string)($row['receiver_name'] ?? ''),
            'Telefon' => (string)($row['receiver_phone'] ?? ''),
            'E-mail' => (string)($row['receiver_email'] ?? ''),
            'Projekt szám' => (string)($row['project_no'] ?? ''),
            'Tételek' => implode(' | ', $itemsText),
            'Megjegyzés' => (string)($row['note'] ?? ''),
            'Döntési megjegyzés' => (string)($row['decision_note'] ?? ''),
            'Kezdeményezte' => (string)($row['requested_by_name'] ?? ''),
            'Kezdeményezés ideje' => (string)($row['requested_at'] ?? ''),
            'Elfogadta' => (string)($row['accepted_by_name'] ?? ''),
            'Elfogadás ideje' => (string)($row['accepted_at'] ?? ''),
            'Elutasította' => (string)($row['rejected_by_name'] ?? ''),
            'Elutasítás ideje' => (string)($row['rejected_at'] ?? ''),
            'Törölte' => (string)($row['cancelled_by_name'] ?? ''),
            'Törlés ideje' => (string)($row['cancelled_at'] ?? ''),
        ];
    }
    warehouse_csv_download('atadasok_' . date('Ymd_His') . '.csv', ['Azonosító', 'Típus', 'Állapot', 'Szállítólevél / Hivatkozás', 'Forrás raktár', 'Forrás kód', 'Cél raktár', 'Cél kód', 'Partner', 'Átvevő', 'Telefon', 'E-mail', 'Projekt szám', 'Tételek', 'Megjegyzés', 'Döntési megjegyzés', 'Kezdeményezte', 'Kezdeményezés ideje', 'Elfogadta', 'Elfogadás ideje', 'Elutasította', 'Elutasítás ideje', 'Törölte', 'Törlés ideje'], $exportRows);
}

$pendingIncomingCount = count(warehouse_transfer_search($config, ['scope' => 'incoming', 'status' => 'pending'], 1000));
$pendingOutgoingCount = count(warehouse_transfer_search($config, ['scope' => 'outgoing', 'status' => 'pending'], 1000));

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0">Raktárközi átadások</h1>
    <div class="text-secondary small">Átadás indítása, cél oldali elfogadás vagy elutasítás, teljes naplózással.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap justify-content-end">
    <span class="badge bg-warning text-dark align-self-center">Bejövő függő: <?= (int)$pendingIncomingCount ?></span>
    <span class="badge bg-secondary align-self-center">Kimenő függő: <?= (int)$pendingOutgoingCount ?></span>
    <a class="btn btn-sm btn-outline-secondary" href="/stock_movements.php">Mozgásnapló</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!$transferItemsEnabled): ?>
  <div class="alert alert-warning">A többtételes átadáshoz futtasd a mellékelt SQL-t: <code>database/warehousemgr_update_step7_transfer_items.sql</code>.</div>
<?php endif; ?>
<?php if ($canManageExternal && !$externalSignatureEnabled): ?>
  <div class="alert alert-warning">A külsős átadás aláírásmezőjéhez futtasd a mellékelt SQL-t: <code>database/warehousemgr_update_step10_external_transfer_signature.sql</code>.</div>
<?php endif; ?>
<?php if ($identifierFeatureReady && !$transferItemIdentifiersEnabled): ?>
  <div class="alert alert-warning">Az egyedi azonosítós átadásokhoz futtasd a mellékelt SQL-t: <code>database/warehousemgr_update_step13_transfer_item_identifiers.sql</code>.</div>
<?php endif; ?>

<?php if ($canManageAny): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="fw-semibold">Új átadás indítása</div>
        <div class="text-secondary small">Több anyag is felvehető egy átadásba. A készlet csak elfogadáskor mozdul.</div>
      </div>
      <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#transfer-create-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
        <span class="wm-panel-toggle-label">Megnyitás</span>
      </button>
    </div>
    <div id="transfer-create-panel" class="collapse" data-wm-panel="1" data-panel-key="transfers-create">
      <div class="card-body">
        <form method="post" class="row g-3" id="transfer-create-form">
          <input type="hidden" name="action" value="create_transfer">
          <div class="col-12 col-lg-4">
            <label class="form-label">Forrás raktár</label>
            <select class="form-select" name="source_warehouse_id" id="transfer_source_warehouse_id" required>
              <option value="">— válassz —</option>
              <?php foreach ($manageableWarehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>"><?= h((string)$w['name']) ?> (<?= h((string)$w['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label">Cél raktár</label>
            <select class="form-select" name="target_warehouse_id" required>
              <option value="">— válassz —</option>
              <?php foreach ($allActiveWarehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>"><?= h((string)$w['name']) ?> (<?= h((string)$w['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-2">
            <label class="form-label">Hivatkozás</label>
            <input class="form-control" name="reference_no" placeholder="belső bizonylat / igény">
          </div>
          <div class="col-12 col-lg-2">
            <label class="form-label">Megjegyzés</label>
            <input class="form-control" name="note" placeholder="átadás oka, megjegyzés">
          </div>

          <div class="col-12">
            <div class="border rounded p-3 bg-light-subtle">
              <div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
                <div>
                  <div class="fw-semibold">Tételek</div>
                  <div class="small text-secondary">Ugyanaz az anyag csak egyszer szerepelhet, az egyedi azonosítós tételeknél pedig kiválaszthatod vagy vonalkódolvasóval beolvashatod, mely azonosítók kerüljenek át.</div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <select class="form-select form-select-sm" id="transfer-category-filter" style="min-width:160px;">
                    <option value="">— minden kategória —</option>
                    <?php foreach ($categoryOptions as $categoryOption): ?>
                      <option value="<?= h($categoryOption) ?>"><?= h($categoryOption) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="transfer-category-btn">Szűrés</button>
                  <button type="button" class="btn btn-sm btn-outline-primary" id="add-transfer-item-btn">+ Anyag</button>
                </div>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th style="min-width: 220px;">Keresés</th>
                      <th style="min-width: 280px;">Anyag</th>
                      <th style="min-width: 180px;">Aktuális készlet</th>
                      <th style="min-width: 260px;">Átadandó mennyiség / azonosítók</th>
                      <th class="text-end" style="width: 110px;">Művelet</th>
                    </tr>
                  </thead>
                  <tbody id="transfer-item-rows"></tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-12">
            <div class="small text-secondary">Átadás indításakor még nem mozdul a készlet. A tényleges készletcsökkentés és készletnövelés csak akkor történik meg, amikor a cél raktár elfogadja az átadást.</div>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary" type="submit">Átadás indítása</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canManageExternal): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="fw-semibold" id="external-transfer-title">Külsős partneres kiadás</div>
        <div class="text-secondary small" id="external-transfer-subtitle">A kiválasztott műveletnek megfelelően azonnali kiadás vagy visszavétel történik, nincs elfogadási kör.</div>
      </div>
      <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#external-transfer-create-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
        <span class="wm-panel-toggle-label">Megnyitás</span>
      </button>
    </div>
    <div id="external-transfer-create-panel" class="collapse" data-wm-panel="1" data-panel-key="transfers-external-create">
      <div class="card-body">
        <form method="post" class="row g-3" id="external-transfer-create-form">
          <input type="hidden" name="action" value="create_external_transfer">
          <input type="hidden" name="external_direction_mode" id="external_direction_mode" value="outbound">
          <div class="col-12">
            <div class="border rounded p-3 bg-danger-subtle border-danger-subtle" id="external-direction-banner">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                  <div class="fw-semibold" id="external-direction-heading">Művelet: Kiadás</div>
                  <div class="small text-secondary" id="external-direction-help">Belső raktárból külső partner raktárba történő kiadás.</div>
                </div>
                <button type="button" class="btn btn-danger" id="external-direction-toggle">Átváltás bevételre</button>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label" id="external-source-label">Forrás raktár</label>
            <select class="form-select" name="external_source_warehouse_id" id="external_transfer_source_warehouse_id" required>
              <option value="">— válassz —</option>
              <?php foreach ($manageableWarehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>"><?= h((string)$w['name']) ?> (<?= h((string)$w['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label" id="external-target-label">Külső partner raktár</label>
            <select class="form-select" name="external_target_warehouse_id" id="external_transfer_target_warehouse_id" required>
              <option value="">— válassz —</option>
              <?php foreach ($externalTargetWarehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>"><?= h((string)$w['name']) ?> (<?= h((string)$w['code']) ?>)<?php if (!empty($w['partner_name'])): ?> · <?= h((string)$w['partner_name']) ?><?php endif; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label d-flex align-items-center justify-content-between gap-2">
              <span>Szállítólevél szám</span>
              <span class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="external_auto_reference" name="external_auto_reference" value="1" checked>
                <label class="form-check-label small" for="external_auto_reference">Automatikus kitöltés</label>
              </span>
            </label>
            <input class="form-control" id="external_reference_no" name="external_reference_no" placeholder="Automatikusan generálódik mentéskor" disabled>
          </div>

          <div class="col-12 col-lg-4">
            <label class="form-label">Aktuális átvevő neve</label>
            <input class="form-control" id="external_receiver_name" name="external_receiver_name" placeholder="partnerből előtöltve, de módosítható">
          </div>
          <div class="col-12 col-lg-3">
            <label class="form-label">Telefonszám</label>
            <input class="form-control" id="external_receiver_phone" name="external_receiver_phone" placeholder="partnerből előtöltve">
          </div>
          <div class="col-12 col-lg-3">
            <label class="form-label">E-mail</label>
            <input class="form-control" id="external_receiver_email" name="external_receiver_email" placeholder="partnerből előtöltve">
          </div>
          <div class="col-12 col-lg-2">
            <label class="form-label">Projekt szám</label>
            <input class="form-control" name="external_project_no" placeholder="opcionális">
          </div>

          <div class="col-12">
            <label class="form-label">Megjegyzés</label>
            <input class="form-control" name="external_note" placeholder="pl. átadás oka, további információ">
          </div>

          <div class="col-12">
            <div class="border rounded p-3 bg-light-subtle">
              <div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
                <div>
                  <div class="fw-semibold">Tételek</div>
                  <div class="small text-secondary">Ugyanaz az anyag csak egyszer szerepelhet, az egyedi azonosítós tételeknél pedig kiválaszthatod vagy vonalkódolvasóval beolvashatod, mely azonosítók kerüljenek át.</div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <select class="form-select form-select-sm" id="external-transfer-category-filter" style="min-width:160px;">
                    <option value="">— minden kategória —</option>
                    <?php foreach ($categoryOptions as $categoryOption): ?>
                      <option value="<?= h($categoryOption) ?>"><?= h($categoryOption) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="external-transfer-category-btn">Szűrés</button>
                  <button type="button" class="btn btn-sm btn-outline-primary" id="add-external-transfer-item-btn">+ Anyag</button>
                </div>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th style="min-width: 220px;">Keresés</th>
                      <th style="min-width: 280px;">Anyag</th>
                      <th style="min-width: 180px;">Aktuális készlet</th>
                      <th style="min-width: 260px;">Átadandó mennyiség / azonosítók</th>
                      <th class="text-end" style="width: 110px;">Művelet</th>
                    </tr>
                  </thead>
                  <tbody id="external-transfer-item-rows"></tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-12">
            <div class="border rounded p-3 bg-light-subtle">
              <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                <div>
                  <div class="fw-semibold">Átvevő aláírása</div>
                  <div class="small text-secondary" id="external-signature-help">Külsős partneres műveletnél kötelező. Egérrel vagy érintéssel is használható.</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="external-signature-clear">Törlés</button>
              </div>
              <div class="border rounded bg-white" style="max-width: 640px;">
                <canvas id="external-signature-canvas" style="width:100%;height:220px;display:block;touch-action:none;"></canvas>
              </div>
              <input type="hidden" name="external_signature_data" id="external_signature_data">
              <div class="small text-secondary mt-2" id="external-signature-status">Még nincs aláírás rögzítve.</div>
            </div>
          </div>

          <div class="col-12">
            <div class="small text-secondary" id="external-transfer-note">A külsős partneres kiadás mentéskor azonnal végbemegy: a belső készlet csökken, a partner raktár készlete nő.</div>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-danger" id="external-submit-btn" type="submit">Kiadás rögzítése</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php elseif ($canManageAny): ?>
  <div class="alert alert-info mb-4">Nincs aktív külső partner típusú raktár, ezért külsős átadás most nem indítható.</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div>
      <div class="fw-semibold">Átadások listája</div>
      <div class="text-secondary small">Szűrhető, exportálható és most már összecsukható lista.</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <div class="text-secondary small">
        <?php if ($totalTransfers > 0): ?><?= (int)$listFrom ?>–<?= (int)$listTo ?> / <?= (int)$totalTransfers ?> tétel · <?= (int)$currentPage ?>/<?= (int)$totalPages ?>. oldal<?php else: ?>0 tétel<?php endif; ?>
      </div>
      <a class="btn btn-sm btn-outline-success js-csv-export" data-export-label="CSV készül…" href="/transfers.php?<?= h($buildQuery(['export' => 'csv'])) ?>">CSV export</a>
      <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#transfer-list-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
        <span class="wm-panel-toggle-label">Megnyitás</span>
      </button>
    </div>
  </div>
  <div id="transfer-list-panel" class="collapse" data-wm-panel="1" data-panel-key="transfers-list" data-default-open="1">
    <div class="card-body">
    <form method="get" class="row g-3 mb-3">
      <div class="col-12 col-lg-3">
        <label class="form-label">Nézet</label>
        <select class="form-select" name="scope">
          <option value="all" <?= $filters['scope'] === 'all' ? 'selected' : '' ?>>Összes</option>
          <option value="incoming" <?= $filters['scope'] === 'incoming' ? 'selected' : '' ?>>Bejövő</option>
          <option value="outgoing" <?= $filters['scope'] === 'outgoing' ? 'selected' : '' ?>>Kimenő</option>
        </select>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label">Állapot</label>
        <select class="form-select" name="status">
          <option value="" <?= $filters['status'] === '' ? 'selected' : '' ?>>Összes</option>
          <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Függőben</option>
          <option value="accepted" <?= $filters['status'] === 'accepted' ? 'selected' : '' ?>>Elfogadva</option>
          <option value="rejected" <?= $filters['status'] === 'rejected' ? 'selected' : '' ?>>Elutasítva</option>
          <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>Törölve</option>
        </select>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label">Raktár</label>
        <select class="form-select" name="warehouse_id">
          <option value="0">— mind —</option>
          <?php foreach ($filterWarehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= $filters['warehouse_id'] === (int)$w['id'] ? 'selected' : '' ?>><?= h((string)$w['name']) ?> (<?= h((string)$w['code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-2">
        <label class="form-label">Kategória</label>
        <select class="form-select" name="category_name">
          <option value="">— mind —</option>
          <?php foreach ($categoryOptions as $categoryOption): ?>
            <option value="<?= h($categoryOption) ?>" <?= (($filters['category_name'] ?? '') === $categoryOption) ? 'selected' : '' ?>><?= h($categoryOption) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-1">
        <label class="form-label">Keresés</label>
        <input class="form-control" name="q" value="<?= h($filters['q']) ?>" placeholder="anyag, cikkszám...">
      </div>
      <div class="col-12 col-lg-1 d-flex align-items-end justify-content-end">
        <button class="btn btn-primary w-100" type="submit">OK</button>
      </div>
    </form>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div class="small text-secondary">
        <?php if ($totalTransfers > 0): ?><?= (int)$listFrom ?>–<?= (int)$listTo ?>. tétel / <?= (int)$totalTransfers ?> összesen · 20 tétel / oldal<?php else: ?>Nincs megjeleníthető átadás.<?php endif; ?>
      </div>
      <?php $renderTransferPagination(); ?>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Átadás</th>
            <th>Tételek</th>
            <th>Állapot</th>
            <th>Kezdeményezte</th>
            <th>Döntés</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $rowIndex => $row): ?>
            <?php
              $transferType = warehouse_transfer_type_normalize((string)($row['transfer_type'] ?? 'internal'));
              $isExternal = $transferType === 'external';
              $externalDirectionLabel = '';
              if ($isExternal) {
                  $sourceType = warehouse_type_normalize((string)($row['source_warehouse_type'] ?? 'internal'));
                  $targetType = warehouse_type_normalize((string)($row['target_warehouse_type'] ?? 'internal'));
                  $externalDirectionLabel = ($sourceType === 'external_partner' && $targetType === 'internal') ? 'Visszavétel' : 'Kiadás';
              }
              $canAcceptReject = !$isExternal && (string)$row['status'] === 'pending' && warehouse_user_can_manage_warehouse_local($config, (int)$row['target_warehouse_id']);
              $canCancel = !$isExternal && (string)$row['status'] === 'pending' && warehouse_user_can_manage_warehouse($config, (int)$row['source_warehouse_id']);
            ?>
            <tr>
              <td>
                <div class="fw-bold">#<?= (int)$row['id'] ?></div>
                <div class="small text-secondary">Lista: <?= (int)($listOffset + $rowIndex + 1) ?>.</div>
                <div class="text-secondary small"><?= h(warehouse_transfer_reference((int)$row['id'])) ?></div>
                <div class="small mt-1 d-flex gap-1 flex-wrap"><span class="badge bg-info-subtle text-dark border"><?= h(warehouse_transfer_type_label((string)($row['transfer_type'] ?? 'internal'))) ?></span><?php if ($externalDirectionLabel !== ''): ?><span class="badge <?= $externalDirectionLabel === 'Visszavétel' ? 'bg-success-subtle text-success-emphasis border border-success-subtle' : 'bg-danger-subtle text-danger-emphasis border border-danger-subtle' ?>"><?= h($externalDirectionLabel) ?></span><?php endif; ?></div>
                <?php if (!empty($row['reference_no'])): ?><div class="text-secondary small"><?= h((string)$row['reference_no']) ?></div><?php endif; ?>
              </td>
              <td>
                <div><span class="text-secondary">Forrás:</span> <strong><?= h((string)$row['source_warehouse_name']) ?></strong> (<?= h((string)$row['source_warehouse_code']) ?>)</div>
                <div><span class="text-secondary">Cél:</span> <strong><?= h((string)$row['target_warehouse_name']) ?></strong> (<?= h((string)$row['target_warehouse_code']) ?>)</div>
                <?php if (!empty($row['partner_name'])): ?><div class="small mt-1"><span class="text-secondary">Partner:</span> <?= h((string)$row['partner_name']) ?></div><?php endif; ?>
                <?php if (!empty($row['receiver_name'])): ?><div class="small"><span class="text-secondary">Átvevő:</span> <?= h((string)$row['receiver_name']) ?></div><?php endif; ?>
                <?php if (!empty($row['receiver_phone']) || !empty($row['receiver_email'])): ?><div class="small text-secondary"><?= h(trim((string)($row['receiver_phone'] ?? ''))) ?><?php if (!empty($row['receiver_phone']) && !empty($row['receiver_email'])): ?> · <?php endif; ?><?= h(trim((string)($row['receiver_email'] ?? ''))) ?></div><?php endif; ?>
                <?php if (!empty($row['project_no'])): ?><div class="small"><span class="text-secondary">Projekt:</span> <?= h((string)$row['project_no']) ?></div><?php endif; ?>
                <?php if (!empty($row['receiver_signature_data'] ?? '')): ?>
                  <div class="small mt-2">
                    <span class="text-secondary d-block mb-1">Átvevő aláírása:</span>
                    <img src="<?= h((string)$row['receiver_signature_data']) ?>" alt="Átvevő aláírása" class="img-thumbnail" style="max-width:220px; max-height:90px; background:#fff;">
                  </div>
                <?php endif; ?>
                <?php if (!empty($row['note'])): ?><div class="small mt-1 text-secondary"><?= nl2br(h((string)$row['note'])) ?></div><?php endif; ?>
                <div class="small text-secondary mt-1"><?= h((string)$row['requested_at']) ?></div>
              </td>
              <td style="min-width: 320px;">
                <?php foreach (($row['items'] ?? []) as $item): ?>
                  <?php $identifierValues = array_values(array_map(static fn(array $identifier): string => warehouse_material_identifier_display_value($identifier), (array)($item['identifiers'] ?? []))); ?>
                  <div class="border rounded px-2 py-1 mb-1 bg-light-subtle">
                    <div><code><?= h((string)($item['sku'] ?? '')) ?></code> <span class="fw-semibold"><?= h((string)($item['material_name'] ?? '')) ?></span></div>
                    <div class="small text-secondary d-flex justify-content-between gap-2">
                      <span><?= h((string)(($item['category_name'] ?? '') !== '' ? $item['category_name'] : '—')) ?></span>
                      <span class="fw-semibold"><?= h(warehouse_format_quantity($item['quantity'] ?? 0)) ?> <?= h((string)($item['unit'] ?? '')) ?></span>
                    </div>
                    <?php if ($identifierValues !== []): ?>
                      <div class="small mt-1">
                        <span class="text-secondary"><?= h(warehouse_material_identifier_value_label((array)$item)) ?>:</span>
                        <span><?= h(implode(', ', $identifierValues)) ?></span>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($row['items'])): ?>
                  <div class="text-secondary small">Nincs tétel.</div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= h(warehouse_transfer_badge_class((string)$row['status'])) ?>"><?= h(warehouse_transfer_status_label((string)$row['status'])) ?></span>
              </td>
              <td>
                <div><?= h((string)($row['requested_by_name'] ?? '—')) ?></div>
                <?php if (!empty($row['requested_at'])): ?><div class="small text-secondary"><?= h((string)$row['requested_at']) ?></div><?php endif; ?>
              </td>
              <td style="min-width: 280px;">
                <?php if ((string)$row['status'] === 'accepted'): ?>
                  <div class="small"><strong><?= h((string)($row['accepted_by_name'] ?? '')) ?></strong></div>
                  <div class="small text-secondary"><?= h((string)($row['accepted_at'] ?? '')) ?></div>
                <?php elseif ((string)$row['status'] === 'rejected'): ?>
                  <div class="small"><strong><?= h((string)($row['rejected_by_name'] ?? '')) ?></strong></div>
                  <div class="small text-secondary"><?= h((string)($row['rejected_at'] ?? '')) ?></div>
                <?php elseif ((string)$row['status'] === 'cancelled'): ?>
                  <div class="small"><strong><?= h((string)($row['cancelled_by_name'] ?? '')) ?></strong></div>
                  <div class="small text-secondary"><?= h((string)($row['cancelled_at'] ?? '')) ?></div>
                <?php endif; ?>

                <?php if (!$isExternal && (string)$row['status'] === 'accepted'): ?>
                  <div class="d-flex gap-2 flex-wrap mt-2 align-items-center">
                    <a class="btn btn-sm btn-outline-primary" href="/transfer_pdf.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">PDF</a>
                    <a class="btn btn-sm btn-outline-secondary" href="/transfer_pdf.php?id=<?= (int)$row['id'] ?>&amp;download=1">Letöltés</a>
                  </div>
                <?php endif; ?>

                <?php if ($isExternal && (string)$row['status'] === 'accepted'): ?>
                  <div class="d-flex gap-2 flex-wrap mt-2 align-items-center">
                    <a class="btn btn-sm btn-outline-primary" href="/external_transfer_pdf.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">PDF</a>
                    <a class="btn btn-sm btn-outline-secondary" href="/external_transfer_pdf.php?id=<?= (int)$row['id'] ?>&amp;download=1">Letöltés</a>
                    <?php if (!empty($row['receiver_email']) && filter_var((string)$row['receiver_email'], FILTER_VALIDATE_EMAIL)): ?>
                      <form method="post" action="/external_transfer_email.php" class="m-0 js-confirm-form" data-confirm-message="<?= h('Elküldöd a szállítólevelet a következő címre? ' . (string)$row['receiver_email']) ?>">
                        <input type="hidden" name="transfer_id" value="<?= (int)$row['id'] ?>">
                        <button class="btn btn-sm btn-outline-success" type="submit">E-mail küldés</button>
                      </form>
                    <?php else: ?>
                      <span class="badge bg-light text-secondary border">Nincs e-mail cím</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if (!empty($row['decision_note'])): ?>
                  <div class="small mt-1 text-secondary"><?= nl2br(h((string)$row['decision_note'])) ?></div>
                <?php endif; ?>

                <?php if ($canAcceptReject || $canCancel): ?>
                  <div class="border rounded p-2 mt-2 bg-light">
                    <form method="post" class="d-flex flex-column gap-2">
                      <input type="hidden" name="transfer_id" value="<?= (int)$row['id'] ?>">
                      <input class="form-control form-control-sm" name="decision_note" placeholder="megjegyzés / indoklás">
                      <div class="d-flex gap-2 flex-wrap">
                        <?php if ($canAcceptReject): ?>
                          <button class="btn btn-sm btn-success" type="submit" name="action" value="accept_transfer">Elfogadás</button>
                          <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="reject_transfer" onclick="return confirm('Biztosan elutasítod az átadást?');">Elutasítás</button>
                        <?php endif; ?>
                        <?php if ($canCancel): ?>
                          <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="cancel_transfer" onclick="return confirm('Biztosan törlöd a függő átadást?');">Törlés</button>
                        <?php endif; ?>
                      </div>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-secondary py-4">Nincs megjeleníthető átadás.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
      <div class="small text-secondary">
        <?php if ($totalTransfers > 0): ?><?= (int)$listFrom ?>–<?= (int)$listTo ?>. tétel / <?= (int)$totalTransfers ?> összesen · 20 tétel / oldal<?php else: ?>Nincs megjeleníthető átadás.<?php endif; ?>
      </div>
      <?php $renderTransferPagination(); ?>
    </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

<?php if ($canManageAny): ?>
<script>
(() => {
  const enabled = <?= $transferItemsEnabled ? 'true' : 'false' ?>;
  if (!enabled) return;

  const materials = <?= json_encode(array_map(static function (array $m): array {
      return [
          'id' => (int)$m['id'],
          'sku' => (string)$m['sku'],
          'name' => (string)$m['name'],
          'unit' => (string)($m['unit'] ?? ''),
          'category_name' => (string)($m['category_name'] ?? ''),
          'label' => (string)$m['name'] . ' [' . (string)$m['sku'] . ']',
          'is_identified' => (int)($m['is_identified'] ?? 0),
          'identifier_label' => warehouse_material_identifier_value_label($m),
      ];
  }, $activeMaterials), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const stockMap = <?= json_encode($transferStockMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const identifierMap = <?= json_encode($transferIdentifierMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const externalWarehouseMap = <?= json_encode(array_reduce($externalTargetWarehouses, static function (array $carry, array $row): array {
      $carry[(int)$row['id']] = [
          'partner_name' => (string)($row['partner_name'] ?? ''),
          'receiver_name' => (string)($row['partner_receiver_name'] ?? ''),
          'phone' => (string)($row['partner_phone'] ?? ''),
          'email' => (string)($row['partner_email'] ?? ''),
      ];
      return $carry;
  }, []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const internalWarehouseOptions = <?= json_encode(array_map(static function (array $w): array {
      return [
          'id' => (int)$w['id'],
          'name' => (string)$w['name'],
          'code' => (string)$w['code'],
      ];
  }, $manageableWarehouses), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const externalWarehouseOptions = <?= json_encode(array_map(static function (array $w): array {
      return [
          'id' => (int)$w['id'],
          'name' => (string)$w['name'],
          'code' => (string)$w['code'],
          'partner_name' => (string)($w['partner_name'] ?? ''),
      ];
  }, $externalTargetWarehouses), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const materialsById = new Map(materials.map((material) => [Number(material.id), material]));
  let rowIndex = 0;

  function initFormEnterGuard(form) {
    if (!form) return;
    form.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const tagName = target.tagName.toUpperCase();
      if (tagName === 'TEXTAREA') return;
      if (tagName === 'BUTTON') return;
      if (target.getAttribute('type') === 'submit') return;
      event.preventDefault();
    });
  }

  function getMaterial(materialId) {
    return materialsById.get(Number(materialId)) || null;
  }

  function getStockInfo(warehouseId, materialId) {
    if (!warehouseId || !materialId) return null;
    return (stockMap[String(warehouseId)] && stockMap[String(warehouseId)][String(materialId)])
      ? stockMap[String(warehouseId)][String(materialId)]
      : null;
  }

  function formatStock(warehouseId, materialId) {
    const stockInfo = getStockInfo(warehouseId, materialId);
    return stockInfo ? stockInfo.display : '0';
  }

  function materialAvailableInWarehouse(warehouseId, materialId) {
    return true;
  }

  function availableMaterialsForWarehouse(warehouseId) {
    if (!warehouseId) return [];
    return materials;
  }

  function availableIdentifiersFor(warehouseId, materialId) {
    if (!warehouseId || !materialId) return [];
    return (identifierMap[String(warehouseId)] && identifierMap[String(warehouseId)][String(materialId)])
      ? identifierMap[String(warehouseId)][String(materialId)]
      : [];
  }

  function ensureWarehouseMaps(warehouseId) {
    const key = String(warehouseId);
    if (!stockMap[key]) {
      stockMap[key] = {};
    }
    if (!identifierMap[key]) {
      identifierMap[key] = {};
    }
  }

  function applyPartialStockMap(partialMap) {
    if (!partialMap || typeof partialMap !== 'object') return;
    Object.entries(partialMap).forEach(([warehouseId, materialRows]) => {
      ensureWarehouseMaps(warehouseId);
      if (!materialRows || typeof materialRows !== 'object') return;
      Object.entries(materialRows).forEach(([materialId, info]) => {
        stockMap[String(warehouseId)][String(materialId)] = info;
      });
    });
  }

  function applyPartialIdentifierMap(partialMap) {
    if (!partialMap || typeof partialMap !== 'object') return;
    Object.entries(partialMap).forEach(([warehouseId, materialRows]) => {
      ensureWarehouseMaps(warehouseId);
      const warehouseKey = String(warehouseId);
      if (!materialRows || typeof materialRows !== 'object') return;
      Object.entries(materialRows).forEach(([materialId, rows]) => {
        identifierMap[warehouseKey][String(materialId)] = Array.isArray(rows) ? rows : [];
      });
    });
  }

  function setWarehouseMaterialIdentifiers(warehouseId, materialId, rows) {
    if (!warehouseId || !materialId) return;
    ensureWarehouseMaps(warehouseId);
    identifierMap[String(warehouseId)][String(materialId)] = Array.isArray(rows) ? rows : [];
  }

  async function postTransferAction(payload) {
    const body = new URLSearchParams();
    Object.entries(payload).forEach(([key, value]) => {
      if (value === undefined || value === null) return;
      body.append(key, String(value));
    });

    const response = await fetch('/transfers.php', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body,
    });

    let data = null;
    try {
      data = await response.json();
    } catch (error) {
      data = null;
    }

    if (!response.ok || !data || data.ok !== true) {
      throw new Error((data && data.error) ? String(data.error) : 'A kérés feldolgozása nem sikerült.');
    }

    return data;
  }

  function setRowScanBusy(row, busy) {
    const button = row.querySelector('.transfer-item-scan-process');
    const textarea = row.querySelector('.transfer-item-scan-input');
    if (button instanceof HTMLButtonElement) {
      button.disabled = busy;
      button.textContent = busy ? 'Feldolgozás...' : 'Lista feldolgozása';
    }
    if (textarea instanceof HTMLTextAreaElement) {
      textarea.readOnly = busy;
    }
  }

  function toggleScanUi(row, material, warehouseId) {
    const scanWrap = row.querySelector('.transfer-item-scan-wrap');
    const scanHint = row.querySelector('.transfer-item-scan-hint');
    if (!(scanWrap instanceof HTMLElement)) return;
    if (!material || Number(material.is_identified) !== 1) {
      scanWrap.classList.add('d-none');
      const resultBox = row.querySelector('.transfer-item-scan-result');
      if (resultBox instanceof HTMLElement) {
        resultBox.classList.add('d-none');
        resultBox.innerHTML = '';
      }
      const input = row.querySelector('.transfer-item-scan-input');
      if (input instanceof HTMLTextAreaElement) {
        input.value = '';
      }
      return;
    }
    scanWrap.classList.remove('d-none');
    if (scanHint instanceof HTMLElement) {
      const label = material.identifier_label || 'Azonosító';
      scanHint.textContent = warehouseId
        ? label + ': minden sor egy beolvasott kód. Ha másik raktárban van, rákérdezünk az áthelyezésre.'
        : 'Előbb válassz forrás raktárat a beolvasáshoz.';
    }
  }

  function renderScanResult(row, result) {
    const box = row.querySelector('.transfer-item-scan-result');
    if (!(box instanceof HTMLElement)) return;

    const added = Array.isArray(result.added) ? result.added : [];
    const relocated = Array.isArray(result.relocated) ? result.relocated : [];
    const skipped = Array.isArray(result.skipped) ? result.skipped : [];
    const errors = Array.isArray(result.errors) ? result.errors : [];
    const duplicate = Array.isArray(result.duplicate) ? result.duplicate : [];

    if (added.length + relocated.length + skipped.length + errors.length + duplicate.length === 0) {
      box.classList.add('d-none');
      box.innerHTML = '';
      return;
    }

    const groups = [];
    if (added.length) {
      groups.push('<div><strong>Hozzáadva:</strong> ' + added.map((item) => String(item)).join(', ') + '</div>');
    }
    if (relocated.length) {
      groups.push('<div><strong>Átmozgatva és hozzáadva:</strong> ' + relocated.map((item) => String(item)).join(', ') + '</div>');
    }
    if (duplicate.length) {
      groups.push('<div><strong>Már be volt jelölve / duplikált:</strong> ' + duplicate.map((item) => String(item)).join(', ') + '</div>');
    }
    if (skipped.length) {
      groups.push('<div><strong>Kihagyva:</strong> ' + skipped.map((item) => String(item)).join(', ') + '</div>');
    }
    if (errors.length) {
      groups.push('<div><strong>Hibalista:</strong><ul class="mb-0 mt-1">' + errors.map((item) => '<li>' + String(item) + '</li>').join('') + '</ul></div>');
    }

    const isError = errors.length > 0;
    box.className = 'transfer-item-scan-result alert py-2 px-3 mt-2 mb-0 ' + (isError ? 'alert-warning' : 'alert-success');
    box.innerHTML = groups.join('');
    box.classList.remove('d-none');
  }

  function setIdentifierChecked(row, identifierId) {
    const input = row.querySelector('.transfer-item-identifier-checkbox[value="' + String(identifierId) + '"]');
    if (!(input instanceof HTMLInputElement)) {
      return false;
    }
    if (input.checked) {
      return false;
    }
    input.checked = true;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  function parseScanLines(raw) {
    const values = [];
    const seen = new Set();
    String(raw || '')
      .split(/\r?\n+/)
      .map((line) => line.trim())
      .filter((line) => line !== '')
      .forEach((line) => {
        const key = line.toLowerCase();
        if (seen.has(key)) {
          values.push({ value: line, duplicateInBatch: true });
          return;
        }
        seen.add(key);
        values.push({ value: line, duplicateInBatch: false });
      });
    return values;
  }

  function initTransferBuilder(config) {
    const createForm = document.getElementById(config.formId);
    const sourceSelect = document.getElementById(config.sourceId);
    const rowsContainer = document.getElementById(config.rowsId);
    const addBtn = document.getElementById(config.addBtnId);
    const categoryFilter = config.categoryFilterId ? document.getElementById(config.categoryFilterId) : null;
    const categoryBtn = config.categoryBtnId ? document.getElementById(config.categoryBtnId) : null;
    if (!createForm || !rowsContainer || !addBtn) return;

    initFormEnterGuard(createForm);

    function selectedMaterialIds(excludeRow = null) {
      return Array.from(rowsContainer.querySelectorAll('.transfer-item-row')).reduce((acc, row) => {
        if (excludeRow && row === excludeRow) return acc;
        const value = parseInt(row.querySelector('.transfer-item-select')?.value || '0', 10);
        if (value > 0) acc.push(value);
        return acc;
      }, []);
    }

    function setQuantityMode(row, material, selectedIdentifierCount = 0) {
      const quantityInput = row.querySelector('.transfer-item-quantity');
      if (!(quantityInput instanceof HTMLInputElement)) return;

      if (material && Number(material.is_identified) === 1) {
        quantityInput.readOnly = true;
        quantityInput.classList.add('bg-light');
        quantityInput.value = selectedIdentifierCount > 0 ? String(selectedIdentifierCount) : '';
        quantityInput.placeholder = selectedIdentifierCount > 0 ? 'automatikus' : 'jelölj azonosítót';
      } else {
        quantityInput.readOnly = false;
        quantityInput.classList.remove('bg-light');
        quantityInput.placeholder = 'pl. 5 vagy 1,5';
      }
    }

    function renderIdentifierChooser(row) {
      const select = row.querySelector('.transfer-item-select');
      const identifiersWrap = row.querySelector('.transfer-item-identifiers-wrap');
      const identifiersBox = row.querySelector('.transfer-item-identifiers');
      const identifiersStatus = row.querySelector('.transfer-item-identifiers-status');
      const quantityInput = row.querySelector('.transfer-item-quantity');
      const materialId = parseInt(select?.value || '0', 10);
      const warehouseId = parseInt(sourceSelect?.value || '0', 10);
      const material = getMaterial(materialId);

      if (!(identifiersWrap instanceof HTMLElement) || !(identifiersBox instanceof HTMLElement) || !(identifiersStatus instanceof HTMLElement)) {
        return;
      }

      toggleScanUi(row, material, warehouseId);

      if (!material || Number(material.is_identified) !== 1) {
        identifiersWrap.classList.add('d-none');
        identifiersBox.innerHTML = '';
        identifiersStatus.textContent = '';
        if (quantityInput instanceof HTMLInputElement) {
          quantityInput.disabled = false;
        }
        setQuantityMode(row, material, 0);
        return;
      }

      identifiersWrap.classList.remove('d-none');
      if (quantityInput instanceof HTMLInputElement) {
        quantityInput.disabled = false;
      }

      const identifierLabel = material.identifier_label || 'Azonosító';
      const availableIdentifiers = availableIdentifiersFor(warehouseId, materialId);
      const selectedValues = new Set(
        Array.from(identifiersBox.querySelectorAll('input[type="checkbox"]:checked'))
          .map((input) => parseInt(input.value || '0', 10))
          .filter((value) => value > 0)
      );

      identifiersBox.innerHTML = '';

      if (!warehouseId) {
        identifiersStatus.textContent = 'Előbb válassz forrás raktárat.';
        setQuantityMode(row, material, 0);
        return;
      }

      if (availableIdentifiers.length === 0) {
        identifiersStatus.textContent = 'Ehhez az anyaghoz nincs választható ' + identifierLabel.toLowerCase() + ' ebben a raktárban.';
        setQuantityMode(row, material, 0);
        return;
      }

      identifiersStatus.textContent = identifierLabel + ': 0 / ' + availableIdentifiers.length + ' kiválasztva';

      const list = document.createElement('div');
      list.className = 'border rounded bg-white p-2';
      list.style.maxHeight = '180px';
      list.style.overflowY = 'auto';

      availableIdentifiers.forEach((identifier) => {
        const item = document.createElement('label');
        item.className = 'form-check d-block small mb-1';
        item.innerHTML = `
          <input class="form-check-input me-1 transfer-item-identifier-checkbox" type="checkbox" name="${config.itemNamePrefix}[${row.dataset.rowIndex}][identifier_ids][]" value="${String(identifier.id)}">
          <span class="form-check-label">${String(identifier.value)}</span>
        `;
        const input = item.querySelector('input');
        if (input && selectedValues.has(Number(identifier.id))) {
          input.checked = true;
        }
        input?.addEventListener('change', () => {
          const checkedCount = row.querySelectorAll('.transfer-item-identifier-checkbox:checked').length;
          identifiersStatus.textContent = identifierLabel + ': ' + checkedCount + ' / ' + availableIdentifiers.length + ' kiválasztva';
          setQuantityMode(row, material, checkedCount);
        });
        list.appendChild(item);
      });

      identifiersBox.appendChild(list);
      const checkedCount = row.querySelectorAll('.transfer-item-identifier-checkbox:checked').length;
      identifiersStatus.textContent = identifierLabel + ': ' + checkedCount + ' / ' + availableIdentifiers.length + ' kiválasztva';
      setQuantityMode(row, material, checkedCount);
    }

    function renderOptions(row, preserveSelected = true) {
      const searchInput = row.querySelector('.transfer-item-search');
      const select = row.querySelector('.transfer-item-select');
      const warehouseId = parseInt(sourceSelect?.value || '0', 10);
      const currentValue = preserveSelected ? parseInt(select.value || '0', 10) : 0;
      const query = (searchInput.value || '').trim().toLowerCase();
      const blocked = new Set(selectedMaterialIds(row));

      const activeCat = categoryFilter ? (categoryFilter.value || '') : '';
      const filtered = availableMaterialsForWarehouse(warehouseId).filter((material) => {
        if (blocked.has(material.id) && material.id !== currentValue) {
          return false;
        }
        if (activeCat !== '' && material.category_name !== activeCat) {
          return false;
        }
        if (query === '') {
          return true;
        }
        return material.sku.toLowerCase().includes(query) || material.name.toLowerCase().includes(query);
      });

      const currentAvailable = filtered.some((material) => material.id === currentValue);
      select.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      if (!warehouseId) {
        placeholder.textContent = '— előbb raktárat válassz —';
      } else if (filtered.length === 0) {
        placeholder.textContent = query === '' ? '— ebben a raktárban nincs készlet —' : '— nincs találat —';
      } else {
        placeholder.textContent = '— válassz —';
      }
      select.appendChild(placeholder);

      filtered.forEach((material) => {
        const option = document.createElement('option');
        option.value = String(material.id);
        option.textContent = material.label;
        option.dataset.materialUnit = material.unit || '';
        option.dataset.isIdentified = String(material.is_identified || 0);
        option.dataset.identifierLabel = material.identifier_label || 'Azonosító';
        if (material.id === currentValue && currentAvailable) {
          option.selected = true;
        }
        select.appendChild(option);
      });

      if (!currentAvailable) {
        select.value = '';
      }
    }

    function updateStockHint(row) {
      const select = row.querySelector('.transfer-item-select');
      const hint = row.querySelector('.transfer-item-stock');
      const materialId = parseInt(select?.value || '0', 10);
      const warehouseId = parseInt(sourceSelect?.value || '0', 10);
      const material = getMaterial(materialId);
      if (!(hint instanceof HTMLElement)) return;
      if (!materialId) {
        hint.textContent = '—';
        return;
      }
      const qty = formatStock(warehouseId, materialId);
      hint.textContent = qty + (material?.unit ? ' ' + material.unit : '');
    }

    function refreshRows() {
      rowsContainer.querySelectorAll('.transfer-item-row').forEach((row) => {
        renderOptions(row, true);
        updateStockHint(row);
        renderIdentifierChooser(row);
      });
    }

    async function processScannedList(row) {
      const materialId = parseInt(row.querySelector('.transfer-item-select')?.value || '0', 10);
      const warehouseId = parseInt(sourceSelect?.value || '0', 10);
      const material = getMaterial(materialId);
      const scanInput = row.querySelector('.transfer-item-scan-input');

      if (!(scanInput instanceof HTMLTextAreaElement)) {
        return;
      }
      if (!warehouseId) {
        alert('Előbb válassz forrás raktárat.');
        sourceSelect?.focus();
        return;
      }
      if (!material || Number(material.is_identified) !== 1) {
        alert('Ehhez a művelethez előbb egy egyedi azonosítós anyagot kell kiválasztani.');
        return;
      }

      const lines = parseScanLines(scanInput.value);
      if (lines.length === 0) {
        alert('Nincs feldolgozható beolvasott azonosító.');
        return;
      }

      const summary = {
        added: [],
        relocated: [],
        duplicate: [],
        skipped: [],
        errors: [],
      };

      setRowScanBusy(row, true);
      try {
        for (const entry of lines) {
          const value = entry.value;
          if (entry.duplicateInBatch) {
            summary.duplicate.push(value + ' (duplikált a mostani listában)');
            continue;
          }

          try {
            const lookupResponse = await postTransferAction({
              action: 'lookup_scanned_identifier',
              source_warehouse_id: warehouseId,
              material_id: materialId,
              identifier_value: value,
            });
            const lookup = lookupResponse.lookup || {};

            if (lookup.status === 'in_source') {
              refreshRows();
              const addedNow = setIdentifierChecked(row, lookup.identifier?.id);
              if (addedNow) {
                summary.added.push(lookup.identifier?.display_value || lookup.identifier?.identifier_value || value);
              } else {
                summary.duplicate.push(value + ' (már ki volt választva)');
              }
              continue;
            }

            if (lookup.status === 'in_other_warehouse') {
              const identifier = lookup.identifier || {};
              const fromLabel = [identifier.warehouse_name || '', identifier.warehouse_code ? '(' + identifier.warehouse_code + ')' : ''].filter(Boolean).join(' ');
              const confirmMessage = (identifier.display_value || identifier.identifier_value || value) + ' jelenleg a következő raktárban van: ' + fromLabel + '.\n\nÁtkerüljön a most kiválasztott forrásraktárba? Ez azonnali készletmozgást rögzít.';
              if (!window.confirm(confirmMessage)) {
                summary.skipped.push((identifier.display_value || identifier.identifier_value || value) + ' (felhasználó kihagyta)');
                continue;
              }

              const relocateResponse = await postTransferAction({
                action: 'relocate_scanned_identifier',
                source_warehouse_id: warehouseId,
                material_id: materialId,
                identifier_id: identifier.id,
                note: 'Vonalkódos átadás-előkészítés (' + config.formId + ')',
              });
              const result = relocateResponse.result || {};
              applyPartialStockMap(result.stock_map || {});
              setWarehouseMaterialIdentifiers(result.from_warehouse_id, materialId, result.from_identifiers || []);
              setWarehouseMaterialIdentifiers(result.to_warehouse_id, materialId, result.to_identifiers || []);
              refreshRows();
              const addedNow = setIdentifierChecked(row, result.identifier_id || identifier.id);
              if (addedNow) {
                summary.relocated.push((result.display_value || result.identifier_value || identifier.display_value || identifier.identifier_value || value) + ' [' + (result.from_warehouse_code || '') + ' → ' + (result.to_warehouse_code || '') + ']');
              } else {
                summary.relocated.push(result.display_value || result.identifier_value || identifier.display_value || identifier.identifier_value || value);
              }
              continue;
            }

            if (lookup.status === 'not_available') {
              const statusLabel = lookup.identifier?.raw_status ? ' (állapot: ' + lookup.identifier.raw_status + ')' : '';
              summary.errors.push(value + ' már szerepel, de nem készleten van' + statusLabel + '.');
              continue;
            }

            summary.errors.push(value + ' nem található ennél az anyagnál.');
          } catch (error) {
            summary.errors.push(value + ': ' + (error instanceof Error ? error.message : 'ismeretlen hiba'));
          }
        }
      } finally {
        setRowScanBusy(row, false);
      }

      renderScanResult(row, summary);
      const hasNewSelection = summary.added.length > 0 || summary.relocated.length > 0;
      if (hasNewSelection) {
        scanInput.value = '';
      }
    }

    function addRow() {
      const row = document.createElement('tr');
      row.className = 'transfer-item-row';
      const idx = rowIndex++;
      row.dataset.rowIndex = String(idx);
      row.innerHTML = `
        <td>
          <input type="text" class="form-control form-control-sm transfer-item-search" placeholder="cikkszám vagy megnevezés">
        </td>
        <td>
          <select class="form-select form-select-sm transfer-item-select" name="${config.itemNamePrefix}[${idx}][material_id]"></select>
        </td>
        <td>
          <div class="small transfer-item-stock text-secondary">—</div>
        </td>
        <td>
          <input class="form-control form-control-sm transfer-item-quantity" name="${config.itemNamePrefix}[${idx}][quantity]" placeholder="pl. 5 vagy 1,5">
          <div class="transfer-item-identifiers-wrap d-none mt-2">
            <div class="small text-secondary transfer-item-identifiers-status mb-1"></div>
            <div class="transfer-item-identifiers"></div>
          </div>
          <div class="transfer-item-scan-wrap d-none mt-2">
            <label class="form-label small mb-1">Vonalkód / gyors lista</label>
            <textarea class="form-control form-control-sm transfer-item-scan-input" rows="4" placeholder="minden sor egy azonosító"></textarea>
            <div class="small text-secondary mt-1 transfer-item-scan-hint"></div>
            <div class="d-flex gap-2 flex-wrap mt-2">
              <button type="button" class="btn btn-sm btn-outline-secondary transfer-item-scan-process">Lista feldolgozása</button>
              <button type="button" class="btn btn-sm btn-outline-light border transfer-item-scan-clear">Törlés</button>
            </div>
            <div class="transfer-item-scan-result d-none"></div>
          </div>
        </td>
        <td class="text-end">
          <button type="button" class="btn btn-sm btn-outline-danger transfer-item-delete">Törlés</button>
        </td>
      `;

      row.querySelector('.transfer-item-search')?.addEventListener('input', () => {
        renderOptions(row, false);
        updateStockHint(row);
        renderIdentifierChooser(row);
      });
      row.querySelector('.transfer-item-select')?.addEventListener('change', () => {
        renderScanResult(row, {});
        refreshRows();
      });
      row.querySelector('.transfer-item-delete')?.addEventListener('click', () => {
        row.remove();
        if (!rowsContainer.querySelector('.transfer-item-row')) {
          addRow();
        }
        refreshRows();
      });
      row.querySelector('.transfer-item-scan-process')?.addEventListener('click', async () => {
        await processScannedList(row);
      });
      row.querySelector('.transfer-item-scan-clear')?.addEventListener('click', () => {
        const scanInput = row.querySelector('.transfer-item-scan-input');
        if (scanInput instanceof HTMLTextAreaElement) {
          scanInput.value = '';
        }
        renderScanResult(row, {});
      });

      rowsContainer.appendChild(row);
      renderOptions(row, false);
      updateStockHint(row);
      renderIdentifierChooser(row);
      refreshRows();
    }

    createForm.addEventListener('submit', (event) => {
      const invalidRow = Array.from(rowsContainer.querySelectorAll('.transfer-item-row')).find((row) => {
        const materialId = parseInt(row.querySelector('.transfer-item-select')?.value || '0', 10);
        if (materialId < 1) {
          return false;
        }
        const material = getMaterial(materialId);
        if (!material || Number(material.is_identified) !== 1) {
          return false;
        }
        return row.querySelectorAll('.transfer-item-identifier-checkbox:checked').length < 1;
      });

      if (invalidRow) {
        event.preventDefault();
        alert('Az egyedi azonosítós anyagnál legalább egy azonosítót ki kell választani vagy be kell olvasni.');
        invalidRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });

    addBtn.addEventListener('click', addRow);
    sourceSelect?.addEventListener('change', () => {
      rowsContainer.querySelectorAll('.transfer-item-row').forEach((row) => renderScanResult(row, {}));
      refreshRows();
    });
    categoryBtn?.addEventListener('click', () => {
      refreshRows();
    });

    addRow();
  }

  initTransferBuilder({
    formId: 'transfer-create-form',
    sourceId: 'transfer_source_warehouse_id',
    rowsId: 'transfer-item-rows',
    addBtnId: 'add-transfer-item-btn',
    categoryFilterId: 'transfer-category-filter',
    categoryBtnId: 'transfer-category-btn',
    itemNamePrefix: 'items'
  });

  initTransferBuilder({
    formId: 'external-transfer-create-form',
    sourceId: 'external_transfer_source_warehouse_id',
    rowsId: 'external-transfer-item-rows',
    addBtnId: 'add-external-transfer-item-btn',
    categoryFilterId: 'external-transfer-category-filter',
    categoryBtnId: 'external-transfer-category-btn',
    itemNamePrefix: 'external_items'
  });

  const autoRef = document.getElementById('external_auto_reference');
  const refInput = document.getElementById('external_reference_no');
  const sourceSelectExternal = document.getElementById('external_transfer_source_warehouse_id');
  const targetSelect = document.getElementById('external_transfer_target_warehouse_id');
  const receiverName = document.getElementById('external_receiver_name');
  const receiverPhone = document.getElementById('external_receiver_phone');
  const receiverEmail = document.getElementById('external_receiver_email');
  const directionInput = document.getElementById('external_direction_mode');
  const directionToggle = document.getElementById('external-direction-toggle');
  const directionBanner = document.getElementById('external-direction-banner');
  const directionHeading = document.getElementById('external-direction-heading');
  const directionHelp = document.getElementById('external-direction-help');
  const sourceLabel = document.getElementById('external-source-label');
  const targetLabel = document.getElementById('external-target-label');
  const transferTitle = document.getElementById('external-transfer-title');
  const transferSubtitle = document.getElementById('external-transfer-subtitle');
  const transferNote = document.getElementById('external-transfer-note');
  const signatureHelp = document.getElementById('external-signature-help');
  const submitBtn = document.getElementById('external-submit-btn');

  function syncAutoReference() {
    if (!autoRef || !refInput) return;
    const isAuto = !!autoRef.checked;
    refInput.disabled = isAuto;
    if (isAuto) {
      refInput.placeholder = 'Automatikusan generálódik mentéskor';
    } else {
      refInput.placeholder = 'kézzel megadható, de nem kötelező';
    }
  }

  function currentExternalDirection() {
    return directionInput && directionInput.value === 'inbound' ? 'inbound' : 'outbound';
  }

  function fillWarehouseSelect(selectEl, options, selectedValue) {
    if (!(selectEl instanceof HTMLSelectElement)) return;
    const keepValue = String(selectedValue || '');
    selectEl.innerHTML = '<option value="">— válassz —</option>';
    options.forEach((row) => {
      const option = document.createElement('option');
      option.value = String(row.id);
      option.textContent = row.partner_name ? `${row.name} (${row.code}) · ${row.partner_name}` : `${row.name} (${row.code})`;
      if (keepValue !== '' && keepValue === String(row.id)) {
        option.selected = true;
      }
      selectEl.appendChild(option);
    });
    if (keepValue !== '' && !options.some((row) => String(row.id) === keepValue)) {
      selectEl.value = '';
    }
  }

  function syncExternalPartnerData() {
    const direction = currentExternalDirection();
    const externalSelect = direction === 'inbound' ? sourceSelectExternal : targetSelect;
    if (!(externalSelect instanceof HTMLSelectElement)) return;
    const warehouseId = parseInt(externalSelect.value || '0', 10);
    const info = externalWarehouseMap[String(warehouseId)] || null;
    if (!info) {
      if (receiverName) receiverName.value = '';
      if (receiverPhone) receiverPhone.value = '';
      if (receiverEmail) receiverEmail.value = '';
      return;
    }
    if (receiverName) receiverName.value = info.receiver_name || info.partner_name || '';
    if (receiverPhone) receiverPhone.value = info.phone || '';
    if (receiverEmail) receiverEmail.value = info.email || '';
  }

  function applyExternalDirection(mode, preserveSelection = false) {
    if (!directionInput) return;
    const nextMode = mode === 'inbound' ? 'inbound' : 'outbound';
    const prevSource = sourceSelectExternal ? sourceSelectExternal.value : '';
    const prevTarget = targetSelect ? targetSelect.value : '';
    directionInput.value = nextMode;

    if (nextMode === 'outbound') {
      if (transferTitle) transferTitle.textContent = 'Külsős partneres kiadás';
      if (transferSubtitle) transferSubtitle.textContent = 'Azonnali kiadás történik a belső raktárból a külső partner raktárába.';
      if (directionHeading) directionHeading.textContent = 'Művelet: Kiadás';
      if (directionHelp) directionHelp.textContent = 'Belső raktárból külső partner raktárba történő kiadás.';
      if (sourceLabel) sourceLabel.textContent = 'Forrás raktár';
      if (targetLabel) targetLabel.textContent = 'Külső partner raktár';
      if (transferNote) transferNote.textContent = 'A külsős partneres kiadás mentéskor azonnal végbemegy: a belső készlet csökken, a partner raktár készlete nő.';
      if (signatureHelp) signatureHelp.textContent = 'Külsős partneres műveletnél kötelező. Egérrel vagy érintéssel is használható.';
      if (submitBtn) {
        submitBtn.textContent = 'Kiadás rögzítése';
        submitBtn.classList.remove('btn-success');
        submitBtn.classList.add('btn-danger');
      }
      if (directionToggle) {
        directionToggle.textContent = 'Átváltás bevételre';
        directionToggle.classList.remove('btn-success');
        directionToggle.classList.add('btn-danger');
      }
      if (directionBanner) {
        directionBanner.classList.remove('bg-success-subtle', 'border-success-subtle');
        directionBanner.classList.add('bg-danger-subtle', 'border-danger-subtle');
      }
      fillWarehouseSelect(sourceSelectExternal, internalWarehouseOptions, preserveSelection ? prevSource : '');
      fillWarehouseSelect(targetSelect, externalWarehouseOptions, preserveSelection ? prevTarget : '');
    } else {
      if (transferTitle) transferTitle.textContent = 'Külső partnertől visszavétel';
      if (transferSubtitle) transferSubtitle.textContent = 'Azonnali bevétel történik a külső partner raktárából a kiválasztott belső raktárba.';
      if (directionHeading) directionHeading.textContent = 'Művelet: Bevétel / visszavétel';
      if (directionHelp) directionHelp.textContent = 'Külső partner raktárból belső raktárba történő visszavétel.';
      if (sourceLabel) sourceLabel.textContent = 'Külső partner raktár';
      if (targetLabel) targetLabel.textContent = 'Cél raktár';
      if (transferNote) transferNote.textContent = 'A visszavétel mentéskor azonnal végbemegy: a partner raktár készlete csökken, a belső raktár készlete nő.';
      if (signatureHelp) signatureHelp.textContent = 'Visszavételnél is kötelező. Egérrel vagy érintéssel is használható.';
      if (submitBtn) {
        submitBtn.textContent = 'Visszavétel rögzítése';
        submitBtn.classList.remove('btn-danger');
        submitBtn.classList.add('btn-success');
      }
      if (directionToggle) {
        directionToggle.textContent = 'Átváltás kiadásra';
        directionToggle.classList.remove('btn-danger');
        directionToggle.classList.add('btn-success');
      }
      if (directionBanner) {
        directionBanner.classList.remove('bg-danger-subtle', 'border-danger-subtle');
        directionBanner.classList.add('bg-success-subtle', 'border-success-subtle');
      }
      fillWarehouseSelect(sourceSelectExternal, externalWarehouseOptions, preserveSelection ? prevSource : '');
      fillWarehouseSelect(targetSelect, internalWarehouseOptions, preserveSelection ? prevTarget : '');
    }

    syncExternalPartnerData();
    document.getElementById('external-transfer-item-rows')?.querySelectorAll('.transfer-item-row').forEach((row) => renderScanResult(row, {}));
    if (typeof refreshRows === 'function') { /* no-op marker */ }
  }

  const signatureEnabled = <?= $externalSignatureEnabled ? 'true' : 'false' ?>;
  const signatureCanvas = document.getElementById('external-signature-canvas');
  const signatureInput = document.getElementById('external_signature_data');
  const signatureStatus = document.getElementById('external-signature-status');
  const signatureClearBtn = document.getElementById('external-signature-clear');
  const externalForm = document.getElementById('external-transfer-create-form');

  function initSignaturePad() {
    if (!signatureEnabled || !signatureCanvas || !signatureInput || !externalForm) return;

    const ctx = signatureCanvas.getContext('2d');
    if (!ctx) return;

    const signaturePanel = signatureCanvas.closest('.collapse');
    let drawing = false;
    let hasInk = false;

    function resizeCanvas(force = false) {
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      const rect = signatureCanvas.getBoundingClientRect();
      const width = Math.max(Math.floor(rect.width), 0);
      const height = Math.max(Math.floor(rect.height), 0);

      if (!force && (width <= 0 || height <= 0)) {
        return false;
      }

      const snapshot = hasInk ? signatureCanvas.toDataURL('image/png') : '';
      signatureCanvas.width = Math.max(Math.floor(width * ratio), 1);
      signatureCanvas.height = Math.max(Math.floor(height * ratio), 1);
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.scale(ratio, ratio);
      ctx.lineWidth = 2;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#111';
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, Math.max(width, 1), Math.max(height, 1));
      if (snapshot && width > 0 && height > 0) {
        const img = new Image();
        img.onload = () => {
          ctx.drawImage(img, 0, 0, width, height);
        };
        img.src = snapshot;
      }
      return width > 0 && height > 0;
    }

    function ensureCanvasReady() {
      const rect = signatureCanvas.getBoundingClientRect();
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      const expectedWidth = Math.max(Math.floor(rect.width * ratio), 1);
      const expectedHeight = Math.max(Math.floor(rect.height * ratio), 1);
      if (signatureCanvas.width !== expectedWidth || signatureCanvas.height !== expectedHeight) {
        resizeCanvas();
      }
    }

    function pointFromEvent(event) {
      const rect = signatureCanvas.getBoundingClientRect();
      const source = event.touches && event.touches[0] ? event.touches[0] : (event.changedTouches && event.changedTouches[0] ? event.changedTouches[0] : event);
      return { x: source.clientX - rect.left, y: source.clientY - rect.top };
    }

    function updateSignatureState() {
      signatureInput.value = hasInk ? signatureCanvas.toDataURL('image/png') : '';
      if (signatureStatus) {
        signatureStatus.textContent = hasInk ? 'Aláírás rögzítve.' : 'Még nincs aláírás rögzítve.';
      }
    }

    function startDrawing(event) {
      ensureCanvasReady();
      event.preventDefault();
      const point = pointFromEvent(event);
      ctx.beginPath();
      ctx.moveTo(point.x, point.y);
      drawing = true;
    }

    function draw(event) {
      if (!drawing) return;
      event.preventDefault();
      const point = pointFromEvent(event);
      ctx.lineTo(point.x, point.y);
      ctx.stroke();
      hasInk = true;
      updateSignatureState();
    }

    function stopDrawing(event) {
      if (!drawing) return;
      event.preventDefault();
      drawing = false;
      updateSignatureState();
    }

    function clearSignature() {
      hasInk = false;
      resizeCanvas();
      updateSignatureState();
    }

    signatureCanvas.addEventListener('mousedown', startDrawing);
    signatureCanvas.addEventListener('mousemove', draw);
    window.addEventListener('mouseup', stopDrawing);

    signatureCanvas.addEventListener('touchstart', startDrawing, { passive: false });
    signatureCanvas.addEventListener('touchmove', draw, { passive: false });
    signatureCanvas.addEventListener('touchend', stopDrawing, { passive: false });
    signatureCanvas.addEventListener('touchcancel', stopDrawing, { passive: false });

    signatureClearBtn?.addEventListener('click', clearSignature);
    window.addEventListener('resize', () => resizeCanvas());
    signatureCanvas.addEventListener('pointerdown', ensureCanvasReady, { passive: true });
    signatureCanvas.addEventListener('focus', ensureCanvasReady, { passive: true });
    if (signaturePanel) {
      signaturePanel.addEventListener('shown.bs.collapse', () => {
        requestAnimationFrame(() => {
          resizeCanvas();
          updateSignatureState();
        });
      });
    }

    externalForm.addEventListener('submit', (event) => {
      if (!hasInk) {
        event.preventDefault();
        alert('A külsős átadáshoz az átvevő aláírása kötelező.');
        signatureCanvas.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      updateSignatureState();
    });

    requestAnimationFrame(() => {
      if (!resizeCanvas()) {
        setTimeout(() => {
          resizeCanvas();
          updateSignatureState();
        }, 120);
      } else {
        updateSignatureState();
      }
    });
  }

  autoRef?.addEventListener('change', syncAutoReference);
  sourceSelectExternal?.addEventListener('change', syncExternalPartnerData);
  targetSelect?.addEventListener('change', syncExternalPartnerData);
  directionToggle?.addEventListener('click', () => {
    applyExternalDirection(currentExternalDirection() === 'outbound' ? 'inbound' : 'outbound');
  });
  applyExternalDirection(currentExternalDirection(), true);
  document.querySelectorAll('.js-confirm-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      const message = form.getAttribute('data-confirm-message') || 'Biztosan folytatod?';
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

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

  syncAutoReference();
  syncExternalPartnerData();
  initSignaturePad();
})();
</script>
<?php endif; ?>

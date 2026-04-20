<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Törlési archívumok listázása.
 * A korábban archivált / törölt raktárstruktúrák JSON csomagjai innen nézhetők meg.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Archivált raktárak';
$loggedIn = true;

if (!warehouse_module_admin($config)) {
    http_response_code(403);
    echo '403 - Ehhez az oldalhoz warehousemgr admin jogosultság szükséges.';
    exit;
}

$archiveDir = warehouse_storage_path('archive/warehouse_delete');
if (!is_dir($archiveDir)) {
    @mkdir($archiveDir, 0775, true);
}

$scanArchives = static function (string $dir): array {
    $items = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $filename = basename($path);
        $payload = null;
        $raw = @file_get_contents($path);
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } catch (Throwable $e) {
                $payload = null;
            }
        }

        $items[] = [
            'filename' => $filename,
            'path' => $path,
            'mtime' => (int)@filemtime($path),
            'size' => (int)@filesize($path),
            'payload' => $payload,
            'generated_at' => (string)($payload['generated_at'] ?? ''),
            'root_name' => (string)($payload['root_warehouse_name'] ?? ''),
            'root_id' => (int)($payload['root_warehouse_id'] ?? 0),
            'warehouse_count' => is_array($payload['warehouses'] ?? null) ? count($payload['warehouses']) : 0,
            'stock_count' => is_array($payload['warehouse_stock'] ?? null) ? count($payload['warehouse_stock']) : 0,
            'movement_count' => is_array($payload['stock_movements'] ?? null) ? count($payload['stock_movements']) : 0,
            'transfer_count' => is_array($payload['stock_transfers'] ?? null) ? count($payload['stock_transfers']) : 0,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return [$b['mtime'], $b['filename']] <=> [$a['mtime'], $a['filename']];
    });

    return $items;
};

$archives = $scanArchives($archiveDir);
$selectedFilename = trim((string)($_GET['file'] ?? ''));
$selectedArchive = null;
foreach ($archives as $item) {
    if ($item['filename'] === $selectedFilename) {
        $selectedArchive = $item;
        break;
    }
}
if ($selectedArchive === null && $archives) {
    $selectedArchive = $archives[0];
    $selectedFilename = (string)$selectedArchive['filename'];
}

if ($selectedArchive && isset($_GET['download']) && $_GET['download'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $selectedArchive['filename']) . '"');
    readfile($selectedArchive['path']);
    exit;
}

$archive = is_array($selectedArchive['payload'] ?? null) ? $selectedArchive['payload'] : null;

$materialLabels = [];
if ($archive) {
    $materialIds = [];
    foreach (['warehouse_stock', 'stock_movements', 'stock_transfer_items'] as $key) {
        foreach (($archive[$key] ?? []) as $row) {
            $mid = (int)($row['material_id'] ?? 0);
            if ($mid > 0) {
                $materialIds[$mid] = $mid;
            }
        }
    }

    if ($materialIds !== []) {
        $pdo = warehouse_pdo($config);
        $placeholders = implode(',', array_fill(0, count($materialIds), '?'));
        $st = $pdo->prepare("SELECT id, sku, name FROM material_items WHERE id IN ($placeholders)");
        $st->execute(array_values($materialIds));
        foreach ($st->fetchAll() as $row) {
            $label = trim((string)($row['sku'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            $materialLabels[(int)$row['id']] = trim($label . ($label !== '' && $name !== '' ? ' · ' : '') . $name);
        }
    }
}

$warehouseName = static function (array $archive, int $warehouseId): string {
    foreach (($archive['warehouses'] ?? []) as $row) {
        if ((int)($row['id'] ?? 0) === $warehouseId) {
            return (string)($row['name'] ?? ('#' . $warehouseId));
        }
    }
    return '#' . $warehouseId;
};

$partnerName = static function (array $archive, int $partnerId): string {
    foreach (($archive['partners'] ?? []) as $row) {
        if ((int)($row['id'] ?? 0) === $partnerId) {
            return (string)($row['partner_name'] ?? ('#' . $partnerId));
        }
    }
    return '#' . $partnerId;
};

$materialLabel = static function (int $materialId) use ($materialLabels): string {
    return $materialLabels[$materialId] ?? ('#' . $materialId);
};

$formatDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $value;
    }
};

$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', ' ') . ' KB';
    return number_format($bytes / 1048576, 2, ',', ' ') . ' MB';
};

$movementTimeline = [];
if ($archive) {
    foreach (($archive['stock_movements'] ?? []) as $row) {
        $wid = (int)($row['warehouse_id'] ?? 0);
        $mid = (int)($row['material_id'] ?? 0);
        $movementTimeline[] = [
            'ts' => (string)($row['created_at'] ?? ''),
            'group' => 'movement',
            'title' => warehouse_movement_type_label((string)($row['movement_type'] ?? '')),
            'detail' => $warehouseName($archive, $wid) . ' / ' . $materialLabel($mid) . ' / ' . warehouse_quantity_display($row['quantity_change'] ?? ''),
            'badge' => 'bg-primary-subtle text-primary-emphasis',
        ];
    }
    foreach (($archive['stock_transfers'] ?? []) as $row) {
        $sourceName = $warehouseName($archive, (int)($row['source_warehouse_id'] ?? 0));
        $targetName = $warehouseName($archive, (int)($row['target_warehouse_id'] ?? 0));
        $referenceNo = trim((string)($row['reference_no'] ?? ''));
        $label = ((string)($row['transfer_type'] ?? '') === 'external') ? 'Külsős átadás' : 'Raktárközi átadás';
        foreach ([
            'requested_at' => ['Kezdeményezve', 'bg-secondary-subtle text-secondary-emphasis'],
            'accepted_at' => ['Elfogadva', 'bg-success-subtle text-success-emphasis'],
            'rejected_at' => ['Elutasítva', 'bg-danger-subtle text-danger-emphasis'],
            'cancelled_at' => ['Törölve', 'bg-warning-subtle text-warning-emphasis'],
        ] as $field => [$stateLabel, $badge]) {
            $ts = trim((string)($row[$field] ?? ''));
            if ($ts === '') {
                continue;
            }
            $movementTimeline[] = [
                'ts' => $ts,
                'group' => 'transfer',
                'title' => $label . ' – ' . $stateLabel,
                'detail' => trim($sourceName . ' → ' . $targetName . ($referenceNo !== '' ? ' / ' . $referenceNo : '')),
                'badge' => $badge,
            ];
        }
    }
    foreach (($archive['audit_log'] ?? []) as $row) {
        $movementTimeline[] = [
            'ts' => (string)($row['created_at'] ?? ''),
            'group' => 'audit',
            'title' => (string)($row['action_key'] ?? 'audit'),
            'detail' => (string)($row['entity_type'] ?? '') . ' #' . (int)($row['entity_id'] ?? 0),
            'badge' => 'bg-dark-subtle text-dark-emphasis',
        ];
    }
    usort($movementTimeline, static function (array $a, array $b): int {
        return strcmp((string)$b['ts'], (string)$a['ts']);
    });
}

$stockTotal = 0.0;
if ($archive) {
    foreach (($archive['warehouse_stock'] ?? []) as $row) {
        $stockTotal += (float)($row['quantity'] ?? 0);
    }
}

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0">Archivált raktárak</h1>
    <div class="text-secondary small">A törölt raktárak JSON archívuma emberileg olvasható nézetben.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/warehouses.php">Raktárak</a>
    <a class="btn btn-sm btn-outline-secondary" href="/audit_log.php">Admin napló</a>
  </div>
</div>

<div class="row g-4">
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h2 class="h6 mb-0">Archív fájlok</h2>
          <span class="badge bg-secondary"><?= count($archives) ?></span>
        </div>
        <?php if (!$archives): ?>
          <div class="text-secondary">Még nincs archivált raktár.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($archives as $item): ?>
              <?php $active = $selectedFilename === $item['filename']; ?>
              <a class="list-group-item list-group-item-action <?= $active ? 'active' : '' ?>" href="/warehouse_archives.php?file=<?= urlencode((string)$item['filename']) ?>">
                <div class="fw-semibold"><?= h($item['root_name'] !== '' ? $item['root_name'] : $item['filename']) ?></div>
                <div class="small <?= $active ? 'text-white-50' : 'text-secondary' ?>"><?= h($item['filename']) ?></div>
                <div class="small <?= $active ? 'text-white-50' : 'text-secondary' ?> mt-1">
                  <?= h($formatDate($item['generated_at'] !== '' ? $item['generated_at'] : date('c', (int)$item['mtime']))) ?> · <?= h($formatBytes((int)$item['size'])) ?>
                </div>
                <div class="small mt-1 <?= $active ? 'text-white-50' : 'text-secondary' ?>">
                  Raktárak: <?= (int)$item['warehouse_count'] ?> · Készlet: <?= (int)$item['stock_count'] ?> · Mozgás: <?= (int)$item['movement_count'] ?>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <?php if (!$archive): ?>
      <div class="card shadow-sm">
        <div class="card-body text-secondary">Válassz egy archív fájlt a bal oldali listából.</div>
      </div>
    <?php else: ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
            <div>
              <h2 class="h5 mb-1"><?= h((string)($archive['root_warehouse_name'] ?? 'Archivált raktár')) ?></h2>
              <div class="text-secondary small">Fájl: <?= h((string)$selectedFilename) ?></div>
              <div class="text-secondary small">Létrejött: <?= h($formatDate((string)($archive['generated_at'] ?? ''))) ?></div>
              <div class="text-secondary small">Törlő auth user ID: <?= (int)($archive['deleted_by_auth_user_id'] ?? 0) ?></div>
            </div>
            <div class="d-flex gap-2">
              <a class="btn btn-sm btn-outline-secondary" href="/warehouse_archives.php?file=<?= urlencode((string)$selectedFilename) ?>&download=json">JSON letöltése</a>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-6 col-md-3">
              <div class="border rounded p-3 h-100">
                <div class="text-secondary small">Raktárak</div>
                <div class="fs-5 fw-semibold"><?= count($archive['warehouses'] ?? []) ?></div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="border rounded p-3 h-100">
                <div class="text-secondary small">Készletsorok</div>
                <div class="fs-5 fw-semibold"><?= count($archive['warehouse_stock'] ?? []) ?></div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="border rounded p-3 h-100">
                <div class="text-secondary small">Mozgások</div>
                <div class="fs-5 fw-semibold"><?= count($archive['stock_movements'] ?? []) ?></div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="border rounded p-3 h-100">
                <div class="text-secondary small">Összes készlet</div>
                <div class="fs-5 fw-semibold"><?= h(warehouse_quantity_display($stockTotal)) ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h2 class="h6 mb-3">Történések röviden</h2>
          <?php if (!$movementTimeline): ?>
            <div class="text-secondary">Nincs kapcsolódó esemény.</div>
          <?php else: ?>
            <div class="d-flex flex-column gap-2">
              <?php foreach ($movementTimeline as $event): ?>
                <div class="border rounded p-3">
                  <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <span class="badge <?= h((string)$event['badge']) ?>"><?= h((string)$event['title']) ?></span>
                    <span class="text-secondary small"><?= h($formatDate((string)$event['ts'])) ?></span>
                  </div>
                  <div><?= h((string)$event['detail']) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="accordion" id="warehouse-archive-accordion">
        <div class="accordion-item">
          <h2 class="accordion-header" id="arc-heading-warehouses"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#arc-collapse-warehouses" aria-expanded="true">Raktár adatok</button></h2>
          <div id="arc-collapse-warehouses" class="accordion-collapse collapse show" data-bs-parent="#warehouse-archive-accordion">
            <div class="accordion-body">
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>ID</th><th>Név</th><th>Kód</th><th>Típus</th><th>Szülő</th><th>Partner</th><th>Aktív</th><th>Létrehozva</th></tr></thead>
                  <tbody>
                  <?php foreach (($archive['warehouses'] ?? []) as $row): ?>
                    <tr>
                      <td><?= (int)($row['id'] ?? 0) ?></td>
                      <td><?= h((string)($row['name'] ?? '')) ?></td>
                      <td><?= h((string)($row['code'] ?? '')) ?></td>
                      <td><?= h(warehouse_type_label((string)($row['warehouse_type'] ?? 'internal'))) ?></td>
                      <td><?= (int)($row['parent_id'] ?? 0) > 0 ? h($warehouseName($archive, (int)$row['parent_id'])) : '—' ?></td>
                      <td><?= (int)($row['partner_id'] ?? 0) > 0 ? h($partnerName($archive, (int)$row['partner_id'])) : '—' ?></td>
                      <td><?= ((int)($row['is_active'] ?? 0) === 1) ? 'Igen' : 'Nem' ?></td>
                      <td><?= h($formatDate((string)($row['created_at'] ?? ''))) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="arc-heading-stock"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#arc-collapse-stock">Készlet</button></h2>
          <div id="arc-collapse-stock" class="accordion-collapse collapse" data-bs-parent="#warehouse-archive-accordion">
            <div class="accordion-body">
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Raktár</th><th>Anyag</th><th class="text-end">Mennyiség</th><th>Frissítve</th></tr></thead>
                  <tbody>
                  <?php foreach (($archive['warehouse_stock'] ?? []) as $row): ?>
                    <tr>
                      <td><?= h($warehouseName($archive, (int)($row['warehouse_id'] ?? 0))) ?></td>
                      <td><?= h($materialLabel((int)($row['material_id'] ?? 0))) ?></td>
                      <td class="text-end"><?= h(warehouse_quantity_display($row['quantity'] ?? '')) ?></td>
                      <td><?= h($formatDate((string)($row['updated_at'] ?? ''))) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($archive['warehouse_stock'])): ?><tr><td colspan="4" class="text-secondary">Nincs készletadat.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="arc-heading-movements"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#arc-collapse-movements">Mozgások</button></h2>
          <div id="arc-collapse-movements" class="accordion-collapse collapse" data-bs-parent="#warehouse-archive-accordion">
            <div class="accordion-body">
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Dátum</th><th>Raktár</th><th>Anyag</th><th>Típus</th><th class="text-end">Változás</th><th class="text-end">Előtte</th><th class="text-end">Utána</th><th>Hivatkozás</th></tr></thead>
                  <tbody>
                  <?php foreach (($archive['stock_movements'] ?? []) as $row): ?>
                    <tr>
                      <td><?= h($formatDate((string)($row['created_at'] ?? ''))) ?></td>
                      <td><?= h($warehouseName($archive, (int)($row['warehouse_id'] ?? 0))) ?></td>
                      <td><?= h($materialLabel((int)($row['material_id'] ?? 0))) ?></td>
                      <td><?= h(warehouse_movement_type_label((string)($row['movement_type'] ?? ''))) ?></td>
                      <td class="text-end"><?= h(warehouse_quantity_display($row['quantity_change'] ?? '')) ?></td>
                      <td class="text-end"><?= h(warehouse_quantity_display($row['quantity_before'] ?? '')) ?></td>
                      <td class="text-end"><?= h(warehouse_quantity_display($row['quantity_after'] ?? '')) ?></td>
                      <td><?= h((string)($row['reference_no'] ?? '—')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($archive['stock_movements'])): ?><tr><td colspan="8" class="text-secondary">Nincs mozgás.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="arc-heading-transfers"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#arc-collapse-transfers">Átadások</button></h2>
          <div id="arc-collapse-transfers" class="accordion-collapse collapse" data-bs-parent="#warehouse-archive-accordion">
            <div class="accordion-body">
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Referencia</th><th>Típus</th><th>Forrás</th><th>Cél</th><th>Partner</th><th>Átvevő</th><th>Állapot</th><th>Kezdeményezve</th></tr></thead>
                  <tbody>
                  <?php foreach (($archive['stock_transfers'] ?? []) as $row): ?>
                    <tr>
                      <td><?= h((string)($row['reference_no'] ?? '—')) ?></td>
                      <td><?= h(((string)($row['transfer_type'] ?? '') === 'external') ? 'Külsős' : 'Belső') ?></td>
                      <td><?= h($warehouseName($archive, (int)($row['source_warehouse_id'] ?? 0))) ?></td>
                      <td><?= h($warehouseName($archive, (int)($row['target_warehouse_id'] ?? 0))) ?></td>
                      <td><?= (int)($row['partner_id'] ?? 0) > 0 ? h((string)($row['partner_name'] ?? $partnerName($archive, (int)$row['partner_id']))) : '—' ?></td>
                      <td><?= h((string)($row['receiver_name'] ?? '—')) ?></td>
                      <td><?= h((string)($row['status'] ?? '—')) ?></td>
                      <td><?= h($formatDate((string)($row['requested_at'] ?? ''))) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($archive['stock_transfers'])): ?><tr><td colspan="8" class="text-secondary">Nincs átadás.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="accordion-item">
          <h2 class="accordion-header" id="arc-heading-audit"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#arc-collapse-audit">Audit</button></h2>
          <div id="arc-collapse-audit" class="accordion-collapse collapse" data-bs-parent="#warehouse-archive-accordion">
            <div class="accordion-body">
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Dátum</th><th>Művelet</th><th>Entity</th><th>Felhasználó ID</th><th>URI</th></tr></thead>
                  <tbody>
                  <?php foreach (($archive['audit_log'] ?? []) as $row): ?>
                    <tr>
                      <td><?= h($formatDate((string)($row['created_at'] ?? ''))) ?></td>
                      <td><?= h((string)($row['action_key'] ?? '')) ?></td>
                      <td><?= h((string)($row['entity_type'] ?? '')) ?> #<?= (int)($row['entity_id'] ?? 0) ?></td>
                      <td><?= (int)($row['auth_user_id'] ?? 0) ?></td>
                      <td class="small"><?= h((string)($row['request_uri'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($archive['audit_log'])): ?><tr><td colspan="5" class="text-secondary">Nincs audit adat.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Szállítólevelek';
$loggedIn = true;

$allWarehouses = warehouse_all($config);
$signatureEnabled = warehouse_transfer_signature_columns_exist($config);

$pageSize = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $pageSize;

$rawFilters = array_merge($_GET, ['status' => 'accepted', 'scope' => 'all']);
$filters = warehouse_transfer_filter_values($rawFilters);
$transferTypeFilter = $filters['transfer_type'] ?? '';

$total = warehouse_transfer_count($config, $filters);
$totalPages = max(1, (int)ceil($total / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $pageSize;
}

$rows = warehouse_transfer_search($config, $filters, $pageSize, $offset);

$buildQuery = static function (array $overrides = []) use ($filters, $page, $transferTypeFilter): string {
    $base = [
        'warehouse_id'  => $filters['warehouse_id'] > 0 ? $filters['warehouse_id'] : null,
        'q'             => $filters['q'] !== '' ? $filters['q'] : null,
        'transfer_type' => $transferTypeFilter !== '' ? $transferTypeFilter : null,
        'page'          => $page > 1 ? $page : null,
    ];
    return http_build_query(array_filter(array_merge($base, $overrides), static fn($v): bool => $v !== null && $v !== ''));
};

require_once __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h1 class="h4 mb-0">Szállítólevelek</h1>
  <a class="btn btn-sm btn-outline-secondary" href="/transfers.php">← Átadások</a>
</div>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-12 col-md-3 col-lg-2">
    <label class="form-label small mb-1">Típus</label>
    <select class="form-select form-select-sm" name="transfer_type">
      <option value="" <?= $transferTypeFilter === '' ? 'selected' : '' ?>>— mind —</option>
      <option value="internal" <?= $transferTypeFilter === 'internal' ? 'selected' : '' ?>>Belső</option>
      <option value="external" <?= $transferTypeFilter === 'external' ? 'selected' : '' ?>>Külső</option>
    </select>
  </div>
  <div class="col-12 col-md-4 col-lg-3">
    <label class="form-label small mb-1">Raktár</label>
    <select class="form-select form-select-sm" name="warehouse_id">
      <option value="">— mind —</option>
      <?php foreach ($allWarehouses as $wh): ?>
        <option value="<?= (int)$wh['id'] ?>" <?= (int)$filters['warehouse_id'] === (int)$wh['id'] ? 'selected' : '' ?>>
          <?= h((string)$wh['name']) ?> [<?= h((string)$wh['code']) ?>]
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-4 col-lg-4">
    <label class="form-label small mb-1">Keresés</label>
    <input class="form-control form-control-sm" type="text" name="q" value="<?= h((string)$filters['q']) ?>" placeholder="partner, átvevő, anyag, referencia…">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-primary" type="submit">Szűrés</button>
    <?php if ($filters['warehouse_id'] > 0 || $filters['q'] !== '' || $transferTypeFilter !== ''): ?>
      <a class="btn btn-sm btn-outline-secondary ms-1" href="/transfer_history.php">Törlés</a>
    <?php endif; ?>
  </div>
</form>

<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
  <div class="text-secondary small">
    <?= $total ?> szállítólevél
    <?php if ($totalPages > 1): ?> · <?= $page ?> / <?= $totalPages ?>. oldal<?php endif; ?>
  </div>
</div>

<?php if ($rows === []): ?>
  <div class="alert alert-secondary">Nincs a szűrésnek megfelelő lezárt átadás.</div>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>Referencia</th>
        <th>Dátum</th>
        <th style="min-width:160px;">Forrás → Cél</th>
        <th>Partner / Átvevő</th>
        <th style="min-width:180px;">Anyag(ok)</th>
        <?php if ($signatureEnabled): ?>
          <th>Aláírás</th>
        <?php endif; ?>
        <th class="text-end">Műveletek</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <?php
          $isExternal = warehouse_transfer_type_normalize((string)($row['transfer_type'] ?? 'internal')) === 'external';
          $hasSignature = $signatureEnabled && !empty($row['receiver_signature_data']);
          $acceptedAt = !empty($row['accepted_at']) ? date('Y.m.d H:i', strtotime((string)$row['accepted_at'])) : '—';
          $items = $row['items'] ?? [];
          $isSingleItem = count($items) <= 1;
        ?>
        <tr>
          <td>
            <?php if (!empty($row['reference_no'])): ?>
              <span class="fw-semibold"><?= h((string)$row['reference_no']) ?></span>
            <?php else: ?>
              <span class="text-secondary">#<?= (int)$row['id'] ?></span>
            <?php endif; ?>
            <?php if ($isExternal): ?>
              <span class="badge bg-secondary ms-1 small">külső</span>
            <?php endif; ?>
          </td>
          <td class="text-nowrap small"><?= h($acceptedAt) ?></td>
          <td class="small">
            <span class="fw-semibold"><?= h((string)($row['source_warehouse_name'] ?? '')) ?></span>
            <span class="text-secondary">[<?= h((string)($row['source_warehouse_code'] ?? '')) ?>]</span>
            <br>→ <?= h((string)($row['target_warehouse_name'] ?? '')) ?>
            <span class="text-secondary">[<?= h((string)($row['target_warehouse_code'] ?? '')) ?>]</span>
          </td>
          <td class="small">
            <?php if (!empty($row['partner_name'])): ?>
              <div class="fw-semibold"><?= h((string)$row['partner_name']) ?></div>
            <?php endif; ?>
            <?php if (!empty($row['receiver_name'])): ?>
              <div class="text-secondary"><?= h((string)$row['receiver_name']) ?></div>
            <?php endif; ?>
            <?php if (!empty($row['project_no'])): ?>
              <div class="text-secondary">Projekt: <?= h((string)$row['project_no']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($items !== []): ?>
              <?php foreach ($items as $item): ?>
                <div>
                  <code><?= h((string)($item['sku'] ?? '')) ?></code>
                  <?= h((string)($item['name'] ?? $item['material_name'] ?? '')) ?>
                  <span class="text-secondary">
                    <?= h(warehouse_format_quantity($item['quantity'] ?? 0)) ?>
                    <?= h((string)($item['unit'] ?? '')) ?>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php elseif (!empty($row['material_name'])): ?>
              <div>
                <code><?= h((string)($row['sku'] ?? '')) ?></code>
                <?= h((string)$row['material_name']) ?>
                <span class="text-secondary">
                  <?= h(warehouse_format_quantity($row['quantity'] ?? 0)) ?>
                  <?= h((string)($row['unit'] ?? '')) ?>
                </span>
              </div>
            <?php endif; ?>
          </td>
          <?php if ($signatureEnabled): ?>
            <td>
              <?php if ($hasSignature): ?>
                <img src="<?= h((string)$row['receiver_signature_data']) ?>"
                     alt="aláírás"
                     style="max-height:36px;max-width:72px;border:1px solid #dee2e6;border-radius:4px;background:#fff;"
                     title="<?= !empty($row['receiver_signature_signed_at']) ? h(date('Y.m.d H:i', strtotime((string)$row['receiver_signature_signed_at']))) : '' ?>">
              <?php else: ?>
                <span class="text-secondary">—</span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <td class="text-end text-nowrap">
            <?php if ($isExternal): ?>
              <a class="btn btn-sm btn-outline-primary"
                 href="/external_transfer_pdf.php?id=<?= (int)$row['id'] ?>"
                 target="_blank"
                 title="Szállítólevél megtekintése">PDF</a>
              <a class="btn btn-sm btn-outline-secondary ms-1"
                 href="/external_transfer_pdf.php?id=<?= (int)$row['id'] ?>&download=1"
                 title="Szállítólevél letöltése">⬇</a>
            <?php else: ?>
              <a class="btn btn-sm btn-outline-primary"
                 href="/transfer_pdf.php?id=<?= (int)$row['id'] ?>"
                 target="_blank"
                 title="Átadási bizonylat megtekintése">PDF</a>
              <a class="btn btn-sm btn-outline-secondary ms-1"
                 href="/transfer_pdf.php?id=<?= (int)$row['id'] ?>&download=1"
                 title="Átadási bizonylat letöltése">⬇</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm flex-wrap">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="?<?= h($buildQuery(['page' => $page - 1])) ?>">‹</a>
      </li>
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= h($buildQuery(['page' => $p])) ?>"><?= $p ?></a>
          </li>
        <?php elseif (abs($p - $page) === 3): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
      <?php endfor; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
        <a class="page-link" href="?<?= h($buildQuery(['page' => $page + 1])) ?>">›</a>
      </li>
    </ul>
  </nav>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../app/views/layout/footer.php'; ?>

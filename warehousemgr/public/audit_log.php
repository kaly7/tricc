<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Audit napló listázó oldal.
 * A felhasználói műveletek szűrhető és exportálható listáját adja.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Admin napló';
$loggedIn = true;

if (!warehouse_module_admin($config)) {
    http_response_code(403);
    echo '403 - Ehhez az oldalhoz warehousemgr admin jogosultság szükséges.';
    exit;
}

$filters = warehouse_audit_filter_values($_GET);
$options = warehouse_audit_filter_options($config);
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = warehouse_audit_count($config, $filters);
$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;
$rows = warehouse_audit_search($config, $filters, $perPage, $offset);

$queryBase = [
    'q' => $filters['q'],
    'action_key' => $filters['action_key'],
    'entity_type' => $filters['entity_type'],
    'auth_user_id' => $filters['auth_user_id'],
    'date_from' => $filters['date_from'],
    'date_to' => $filters['date_to'],
    'page' => $page,
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
    <h1 class="h4 m-0">Admin napló</h1>
    <div class="text-secondary small">Kereshető és szűrhető audit lista a raktárkezelő műveleteiről.</div>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <form method="get" class="row g-3">
      <div class="col-12 col-lg-4">
        <label class="form-label">Keresés</label>
        <input class="form-control" name="q" value="<?= h($filters['q']) ?>" placeholder="művelet, user, entity, részlet, IP, URL...">
      </div>
      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label">Művelet</label>
        <select class="form-select" name="action_key">
          <option value="">— mind —</option>
          <?php foreach ($options['actions'] as $action): ?>
            <option value="<?= h((string)$action) ?>" <?= $filters['action_key'] === (string)$action ? 'selected' : '' ?>><?= h((string)$action) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label">Entity</label>
        <select class="form-select" name="entity_type">
          <option value="">— mind —</option>
          <?php foreach ($options['entities'] as $entity): ?>
            <option value="<?= h((string)$entity) ?>" <?= $filters['entity_type'] === (string)$entity ? 'selected' : '' ?>><?= h((string)$entity) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label">Felhasználó</label>
        <select class="form-select" name="auth_user_id">
          <option value="0">— mind —</option>
          <?php foreach ($options['users'] as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $filters['auth_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h((string)$u['resolved_name']) ?></option>
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
      <div class="col-12 d-flex justify-content-between">
        <a class="btn btn-outline-secondary" href="/audit_log.php">Szűrők törlése</a>
        <button class="btn btn-primary" type="submit">Szűrés</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
      <div>
        <h2 class="h6 mb-1">Találatok</h2>
        <div class="text-secondary small">Összesen <?= (int)$total ?> sor, oldalanként <?= $perPage ?>.</div>
      </div>
      <div class="text-secondary small">
        <?php if ($total > 0): ?>
          Megjelenítve: <?= $offset + 1 ?>–<?= min($offset + count($rows), $total) ?>
        <?php else: ?>
          Nincs találat.
        <?php endif; ?>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle table-sm">
        <thead>
          <tr>
            <th>Dátum</th>
            <th>Felhasználó</th>
            <th>Művelet</th>
            <th>Entity</th>
            <th>Részletek</th>
            <th>Kérés</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <div class="fw-bold"><?= h((string)$row['created_at']) ?></div>
              <?php if (!empty($row['ip_address'])): ?><div class="text-secondary small">IP: <?= h((string)$row['ip_address']) ?></div><?php endif; ?>
            </td>
            <td>
              <div class="fw-bold"><?= h((string)$row['resolved_name']) ?></div>
              <?php if (!empty($row['username'])): ?><div class="text-secondary small"><?= h((string)$row['username']) ?></div><?php endif; ?>
              <?php if (!empty($row['email'])): ?><div class="text-secondary small"><?= h((string)$row['email']) ?></div><?php endif; ?>
            </td>
            <td><code><?= h((string)$row['action_key']) ?></code></td>
            <td>
              <div><?= h((string)$row['entity_type']) ?></div>
              <div class="text-secondary small">ID: <?= h((string)($row['entity_id'] ?? '')) ?></div>
            </td>
            <td class="small"><?= warehouse_detail_pairs_html((array)$row['details']) ?></td>
            <td class="small">
              <div><span class="text-secondary">Method:</span> <?= h((string)($row['request_method'] ?? '')) ?></div>
              <div><span class="text-secondary">URI:</span> <?= h((string)($row['request_uri'] ?? '')) ?></div>
              <?php if (!empty($row['user_agent'])): ?><div class="text-secondary" style="max-width:320px; white-space:normal;"><?= h((string)$row['user_agent']) ?></div><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-secondary">Nincs találat a megadott szűrőkre.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
    <nav aria-label="Audit napló lapozás" class="mt-3">
      <ul class="pagination pagination-sm mb-0 flex-wrap">
        <?php $prevPage = max(1, $page - 1); ?>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="/audit_log.php?<?= h($buildQuery(['page' => $prevPage])) ?>">«</a>
        </li>
        <?php
          $startPage = max(1, $page - 2);
          $endPage = min($pages, $page + 2);
          for ($p = $startPage; $p <= $endPage; $p++):
        ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="/audit_log.php?<?= h($buildQuery(['page' => $p])) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
        <?php $nextPage = min($pages, $page + 1); ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
          <a class="page-link" href="/audit_log.php?<?= h($buildQuery(['page' => $nextPage])) ?>">»</a>
        </li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

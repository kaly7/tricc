<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
require_login();
require_admin();
$pdo = db();

$page_num = max(1, (int)($_GET['p'] ?? 1));
$perPage  = 50;
$offset   = ($page_num - 1) * $perPage;

$filterUser   = trim((string)($_GET['user'] ?? ''));
$filterAction = trim((string)($_GET['action'] ?? ''));
$filterDate   = trim((string)($_GET['date'] ?? ''));

$where  = '1=1';
$params = [];
if ($filterAction !== '') { $where .= ' AND al.action LIKE ?'; $params[] = '%' . $filterAction . '%'; }
if ($filterDate   !== '') { $where .= ' AND DATE(al.created_at)=?'; $params[] = $filterDate; }

try {
  $total = (int)$pdo->prepare("SELECT COUNT(*) FROM audit_log al WHERE $where")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM audit_log al WHERE $where") : 0;
  $stCount = $pdo->prepare("SELECT COUNT(*) FROM audit_log al WHERE $where");
  $stCount->execute($params);
  $total = (int)$stCount->fetchColumn();

  $st = $pdo->prepare("SELECT al.* FROM audit_log al WHERE $where ORDER BY al.created_at DESC LIMIT $perPage OFFSET $offset");
  $st->execute($params);
  $rows = $st->fetchAll();
} catch (Throwable $e) { $rows = []; $total = 0; }

// Auth user névtér a loghoz
$authUsers = [];
try {
  $pdo2 = new PDO('mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4', 'ppdb', 'abrakadabra', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  foreach ($pdo2->query("SELECT id, full_name, username FROM users")->fetchAll() as $au) {
    $authUsers[(int)$au['id']] = $au['full_name'] ?: $au['username'];
  }
} catch (Throwable $e) {}

$pages = (int)ceil($total / $perPage);

$title = 'Napló';
$page  = 'admin_log';
require '_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Audit napló</h4>
  <span class="text-muted small"><?= number_format($total, 0, '.', ' ') ?> bejegyzés</span>
</div>

<!-- Szűrők -->
<form class="row g-2 mb-3" method="get">
  <div class="col-auto">
    <input type="text" name="action" class="form-control form-control-sm" placeholder="Művelet..." value="<?= e($filterAction) ?>">
  </div>
  <div class="col-auto">
    <input type="date" name="date" class="form-control form-control-sm" value="<?= e($filterDate) ?>">
  </div>
  <div class="col-auto">
    <button type="submit" class="btn btn-secondary btn-sm">Szűrés</button>
    <a href="admin_log.php" class="btn btn-outline-secondary btn-sm">Törlés</a>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm table-hover">
    <thead class="table-dark">
      <tr><th>Időpont</th><th>Felhasználó</th><th>Művelet</th><th>Entitás</th><th>Részletek</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr class="audit-row">
        <td class="text-nowrap"><?= e(date('Y.m.d H:i:s', strtotime($row['created_at']))) ?></td>
        <td><?= e($authUsers[(int)$row['user_id']] ?? "#{$row['user_id']}") ?></td>
        <td><code><?= e($row['action']) ?></code></td>
        <td class="text-muted small">
          <?php if ($row['entity_type']): ?>
            <?= e($row['entity_type']) ?> #<?= (int)$row['entity_id'] ?>
          <?php endif; ?>
        </td>
        <td class="text-muted small">
          <?php if ($row['details_json']): ?>
            <span title="<?= e($row['details_json']) ?>">
              <?php $d = json_decode($row['details_json'], true); echo e(implode(', ', array_map(fn($k,$v) => "$k: $v", array_keys($d??[]), array_values($d??[])))); ?>
            </span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Lapozó -->
<?php if ($pages > 1): ?>
  <nav><ul class="pagination pagination-sm">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <li class="page-item <?= $i === $page_num ? 'active' : '' ?>">
        <a class="page-link" href="?p=<?= $i ?>&action=<?= urlencode($filterAction) ?>&date=<?= urlencode($filterDate) ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
  </ul></nav>
<?php endif; ?>

<?php require '_footer.php'; ?>

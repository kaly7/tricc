<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) {
    http_response_code(403);
    exit('Nincs jogosultság.');
}
$rows = tracker_audit_rows($config, 300);
$employeeMap = tracker_employee_name_map($config);
$title = 'Munkaidő / Napló';
require __DIR__ . '/../app/views/layout/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Audit napló</h1>
    <div class="text-muted">A legutóbbi műveletek listája. Csak admin számára látható.</div>
  </div>
</div>
<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Időpont</th><th>Művelet</th><th>Érintett dolgozó</th><th>Entitás</th><th>Megjegyzés</th></tr></thead>
        <tbody>
          <?php if (!$rows): ?>
          <tr><td colspan="5" class="text-muted">Még nincs naplózott esemény.</td></tr>
          <?php else: foreach ($rows as $row): ?>
          <tr>
            <td><?= h((string)$row['created_at']) ?></td>
            <td><code><?= h((string)$row['action_key']) ?></code></td>
            <td><?= h($employeeMap[(int)$row['target_employee_id']] ?? ((int)$row['target_employee_id'] > 0 ? '#' . $row['target_employee_id'] : '—')) ?></td>
            <td><?= h((string)$row['entity_type']) ?> #<?= (int)$row['entity_id'] ?></td>
            <td><?= h((string)$row['note']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>

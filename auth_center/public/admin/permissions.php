<?php
declare(strict_types=1);
require_once __DIR__ . '/_admin_bootstrap.php';

$targetUserId = (int)($_GET['user_id'] ?? 0);
if ($targetUserId <= 0) { echo "Hiányzó user_id."; exit; }

$st = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id=?");
$st->execute([$targetUserId]);
$targetUser = $st->fetch();
if (!$targetUser) { echo "Nincs ilyen user."; exit; }

$msg = '';
$err = '';

$modules = $pdo->query("SELECT id, module_key, module_name, port, is_enabled FROM modules ORDER BY module_name")->fetchAll();
$roles = $pdo->query("SELECT id, role_key, role_name FROM roles ORDER BY id")->fetchAll();

$assignStmt = $pdo->prepare("
  SELECT umr.module_id, r.role_key
  FROM user_module_roles umr
  JOIN roles r ON r.id = umr.role_id
  WHERE umr.user_id=?
");
$assignStmt->execute([$targetUserId]);
$assigned = [];
foreach ($assignStmt->fetchAll() as $a) {
  $assigned[(int)$a['module_id']] = (string)$a['role_key'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pdo->beginTransaction();
  try {
    $pdo->prepare("DELETE FROM user_module_roles WHERE user_id=?")->execute([$targetUserId]);

    foreach ($modules as $m) {
      $mid = (int)$m['id'];
      $field = 'role_' . $mid;
      $roleKey = (string)($_POST[$field] ?? '');
      if ($roleKey === '' || $roleKey === 'none') continue;

      $ridStmt = $pdo->prepare("SELECT id FROM roles WHERE role_key=? LIMIT 1");
      $ridStmt->execute([$roleKey]);
      $rid = $ridStmt->fetchColumn();
      if (!$rid) continue;

      $ins = $pdo->prepare("INSERT INTO user_module_roles (user_id, module_id, role_id) VALUES (?,?,?)");
      $ins->execute([$targetUserId, $mid, (int)$rid]);
    }

    $pdo->commit();
    $msg = 'Jogosultságok mentve.';

    $assignStmt->execute([$targetUserId]);
    $assigned = [];
    foreach ($assignStmt->fetchAll() as $a) {
      $assigned[(int)$a['module_id']] = (string)$a['role_key'];
    }
  } catch (Throwable $e) {
    $pdo->rollBack();
    $err = 'Hiba: ' . $e->getMessage();
  }
}

function role_select(array $roles, string $name, string $current): string {
  $out = '<select class="form-select form-select-sm" name="' . h($name) . '">';
  $out .= '<option value="none">— nincs —</option>';
  foreach ($roles as $r) {
    $key = (string)$r['role_key'];
    $sel = ($key === $current) ? ' selected' : '';
    $out .= '<option value="' . h($key) . '"' . $sel . '>' . h((string)$r['role_name']) . '</option>';
  }
  $out .= '</select>';
  return $out;
}

require __DIR__ . '/../../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0">Jogosultságok</h1>
    <div class="text-secondary small">User: <b><?= h((string)$targetUser['full_name']) ?></b> (<?= h((string)$targetUser['username']) ?>)</div>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="/admin/users.php">Vissza</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="post">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>Modul</th>
              <th>Port</th>
              <th>Szerepkör</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($modules as $m): ?>
              <?php $mid = (int)$m['id']; $cur = $assigned[$mid] ?? 'none'; ?>
              <tr>
                <td>
                  <div class="fw-bold"><?= h((string)$m['module_name']) ?></div>
                  <div class="text-secondary small"><?= h((string)$m['module_key']) ?></div>
                </td>
                <td><?= (int)$m['port'] ?></td>
                <td style="width:260px;"><?= role_select($roles, 'role_'.$mid, (string)$cur) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button class="btn btn-primary" type="submit">Mentés</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../../app/views/layout/footer.php'; ?>

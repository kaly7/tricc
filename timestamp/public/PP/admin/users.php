<?php
require __DIR__ . '/../partials/header.php';
require_login($pdo);
if (!is_admin($pdo)) { http_response_code(403); echo "Nincs jogosultság."; require __DIR__ . '/../partials/footer.php'; exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') csrf_verify();

if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $pass = $_POST['password'] ?? '';
    if ($name && $email && $pass) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, role, is_active, password_hash, created_at) VALUES (?,?,?,?,?,NOW())");
        $stmt->execute([$name, $email, $role, $is_active, password_hash($pass, PASSWORD_DEFAULT)]);
    }
    redirect('admin/users.php');
} elseif ($action === 'toggle' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
    redirect('admin/users.php');
} elseif ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    redirect('admin/users.php');
}

$rows = $pdo->query("SELECT id, name, email, role, is_active, created_at FROM users ORDER BY id DESC")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4">Felhasználók</h1>
</div>

<div class="card mb-4"><div class="card-body">
  <h2 class="h6">Új felhasználó</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="row g-2">
      <div class="col-md-3"><input class="form-control" name="name" placeholder="Név" required></div>
      <div class="col-md-3"><input class="form-control" type="email" name="email" placeholder="E-mail" required></div>
      <div class="col-md-2"><select class="form-select" name="role"><option value="user">Felhasználó</option><option value="admin">Admin</option></select></div>
      <div class="col-md-2"><input class="form-control" type="password" name="password" placeholder="Jelszó" required></div>
      <div class="col-md-1 form-check d-flex align-items-center">
        <input class="form-check-input" type="checkbox" name="is_active" id="ia" checked><label class="form-check-label ms-2" for="ia">Aktív</label>
      </div>
      <div class="col-md-1"><button class="btn btn-primary w-100">Hozzáad</button></div>
    </div>
  </form>
</div></div>

<div class="table-responsive">
<table class="table table-sm">
  <thead><tr><th>#</th><th>Név</th><th>E-mail</th><th>Szerep</th><th>Állapot</th><th>Létrehozva</th><th>Művelet</th></tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= htmlspecialchars($r['email']) ?></td>
        <td><?= htmlspecialchars($r['role']) ?></td>
        <td><?= $r['is_active'] ? '<span class="badge bg-success">✓</span>' : '<span class="badge bg-danger">✗</span>' ?></td>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
        <td class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-secondary" href="users.php?action=toggle&id=<?= (int)$r['id'] ?>"><?= $r['is_active'] ? 'Inaktiválás' : 'Aktiválás' ?></a>
          <a class="btn btn-sm btn-outline-danger" href="users.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Biztosan törlöd?')">Törlés</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>

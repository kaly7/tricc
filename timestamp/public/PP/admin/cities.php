<?php
require __DIR__ . '/../partials/header.php';
require_login($pdo);
if (!is_admin($pdo)) { http_response_code(403); echo "Nincs jogosultság."; require __DIR__ . '/../partials/footer.php'; exit; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify();
  $name = trim($_POST['name'] ?? '');
  if ($name) {
    $stmt = $pdo->prepare("INSERT INTO cities (name, is_active, created_at) VALUES (?, 1, NOW())");
    $stmt->execute([$name]);
  }
  redirect('admin/cities.php');
}
if (isset($_GET['toggle'])) {
  $id = (int)$_GET['toggle'];
  $pdo->prepare("UPDATE cities SET is_active = 1-is_active WHERE id=?")->execute([$id]);
  redirect('admin/cities.php');
}
if (isset($_GET['delete'])) {
  $id = (int)$_GET['delete'];
  $pdo->prepare("DELETE FROM cities WHERE id=?")->execute([$id]);
  redirect('admin/cities.php');
}

$rows = $pdo->query("SELECT * FROM cities ORDER BY name")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4">Települések</h1>
</div>

<form class="row g-2 mb-3" method="post">
  <?= csrf_field() ?>
  <div class="col-md-6"><input class="form-control" name="name" placeholder="Új település neve" required></div>
  <div class="col-md-2"><button class="btn btn-primary w-100">Hozzáad</button></div>
</form>

<div class="table-responsive">
<table class="table table-sm">
  <thead><tr><th>#</th><th>Név</th><th>Állapot</th><th>Művelet</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= htmlspecialchars($r['name']) ?></td>
      <td><?= $r['is_active'] ? '<span class="badge bg-success">✓</span>' : '<span class="badge bg-danger">✗</span>' ?></td>
      <td class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="cities.php?toggle=<?= (int)$r['id'] ?>"><?= $r['is_active'] ? 'Inaktiválás' : 'Aktiválás' ?></a>
        <a class="btn btn-sm btn-outline-danger" href="cities.php?delete=<?= (int)$r['id'] ?>" onclick="return confirm('Biztosan törlöd?')">Törlés</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>

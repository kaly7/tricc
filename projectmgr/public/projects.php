<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/ProjectService.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\ProjectService;

Auth::start(); Middleware::requireAuth();
$projects = ProjectService::all();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Projektek</h1>
  <a class="btn btn-success" href="/project_create.php">Új projekt</a>
</div>
<div class="card p-0">
  <table class="table table-striped m-0">
    <thead><tr><th>ID</th><th>Kód</th><th>Név</th><th>Tulaj</th><th>Létrehozva</th></tr></thead>
    <tbody>
      <?php foreach($projects as $p): ?>
        <tr>
          <td><?= (int)$p['id'] ?></td>
          <td><?= htmlspecialchars($p['code']) ?></td>
          <td><?= htmlspecialchars($p['name']) ?></td>
          <td><?= htmlspecialchars($p['owner_name']) ?></td>
          <td><?= htmlspecialchars($p['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';

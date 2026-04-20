<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$project_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$project_id) { http_response_code(400); exit('Hibás projekt ID'); }

$proj = $pdo->prepare('SELECT number,name FROM projects WHERE id=?');
$proj->execute([$project_id]);
$project = $proj->fetch(PDO::FETCH_ASSOC);
if (!$project) { http_response_code(404); exit('Projekt nem található'); }

$action = trim($_GET['action'] ?? '');
$where = ' WHERE pa.project_id=? ';
$params = [$project_id];
if ($action !== '') {
  $where .= ' AND pa.action = ? ';
  $params[] = $action;
}
$sql = 'SELECT pa.*, u.name AS user_name FROM project_activity pa LEFT JOIN users u ON u.id=pa.user_id '
     . $where
     . ' ORDER BY pa.id DESC LIMIT 500';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$acts = $pdo->prepare('SELECT DISTINCT action FROM project_activity WHERE project_id=? ORDER BY action');
$acts->execute([$project_id]);
$actList = $acts->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Projekt napló – <?= htmlspecialchars($project['number'].' — '.$project['name']) ?></h1>
  <a class="btn btn-secondary" href="/pm_project_edit.php?id=<?= (int)$project_id ?>">Vissza</a>
</div>

<div class="card p-3 mb-3">
  <form method="get" class="row g-2">
    <input type="hidden" name="id" value="<?= (int)$project_id ?>">
    <div class="col-md-6">
      <label class="form-label">Szűrés műveletre</label>
      <select name="action" class="form-select" onchange="this.form.submit()">
        <option value="">— összes —</option>
        <?php foreach($actList as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>" <?= $a===$action?'selected':''; ?>><?= htmlspecialchars($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<div class="card p-0">
  <table class="table table-striped m-0">
    <thead><tr>
      <th>ID</th><th>Idő</th><th>Felhasználó</th><th>Művelet</th><th>Részletek</th><th>IP</th>
    </tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['created_at']) ?></td>
          <td><?= htmlspecialchars($r['user_name'] ?? '—') ?></td>
          <td><code><?= htmlspecialchars($r['action']) ?></code></td>
          <td style="white-space: pre-wrap;"><?= htmlspecialchars($r['details'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; if (!$rows): ?>
        <tr><td colspan="6" class="text-muted">Még nincs bejegyzés.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php';

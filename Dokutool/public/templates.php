<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$err=null; $ok=null;

// Delete (POST)
if (isset($_POST['action']) && $_POST['action']==='delete') {
  try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      $pdo->prepare("DELETE FROM templates WHERE id=:id")->execute([':id'=>$id]);
      $ok = "Sablon törölve.";
    }
  } catch(Throwable $e) { $err = "Törlés hiba: ".$e->getMessage(); }
}

// Create quick new (POST)
if (isset($_POST['action']) && $_POST['action']==='create') {
  try {
    $name = trim($_POST['name'] ?? '');
    if ($name==='') throw new Exception('Név kötelező.');
    $slug = strtolower(preg_replace('/[^a-z0-9]+/','-', iconv('UTF-8','ASCII//TRANSLIT',$name)));
    if ($slug==='') $slug = 'sablon-'.date('YmdHis');
    $stmt = $pdo->prepare("INSERT INTO templates (name, slug, content_html) VALUES (:n, :s, :c)");
    $stmt->execute([':n'=>$name, ':s'=>$slug, ':c'=>'<p>Új sablon. Helykitöltők: {{ partner.megnevezes }}, {{ project.megnevezes }}, {{ image.logo }}</p>']);
    $newId = (int)$pdo->lastInsertId();
    header("Location: template_edit.php?id=".$newId); exit;
  } catch(Throwable $e) { $err = "Létrehozási hiba: ".$e->getMessage(); }
}

// List
$q = trim($_GET['q'] ?? '');
$params = [];
$sql = "SELECT * FROM templates";
if ($q!=='') { $sql .= " WHERE name LIKE :q OR slug LIKE :q"; $params[':q'] = '%'.$q.'%'; }
$sql .= " ORDER BY updated_at DESC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container">
  <div class="card hdr">
    <form method="get" action="templates.php" style="display:flex; gap:8px;">
      <div class="input">
        <label>Keresés</label>
        <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="név vagy slug">
      </div>
      <div style="align-self:flex-end;">
        <button class="btn" type="submit">Keres</button>
        <a class="btn ghost" href="templates.php">Minden</a>
      </div>
    </form>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="btn" href="index.php">← Főmenü</a>
      <form method="post" action="templates.php" style="display:flex; gap:8px;">
        <input type="hidden" name="action" value="create">
        <div class="input"><label>Új sablon neve</label><input name="name" required placeholder="pl. Ajánlat alap"></div>
        <button class="btn primary" type="submit">+ Létrehoz</button>
      </form>
    </div>
  </div>

  <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>
  <?php if($ok): ?><div class="ok"><?=htmlspecialchars($ok)?></div><?php endif; ?>

  <div class="card">
    <table class="table">
      <thead><tr><th>ID</th><th>Név</th><th>Slug</th><th>Frissítve</th><th style="width:240px;">Művelet</th></tr></thead>
      <tbody>
        <?php if (!empty($rows)): foreach($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><code><?= htmlspecialchars($r['slug']) ?></code></td>
            <td><?= htmlspecialchars($r['updated_at']) ?></td>
            <td>
              <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <a class="btn" href="template_edit.php?id=<?= (int)$r['id'] ?>">Szerkeszt</a>
                <form method="post" onsubmit="return confirm('Biztosan törlöd?');" style="display:inline;">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn ghost" type="submit">Törlés</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="5"><em>Még nincs sablon.</em></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>

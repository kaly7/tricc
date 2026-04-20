<?php
require_once __DIR__ . '/../functions.php';
require_login(); require_admin();

$error=null; $ok=null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name=trim($_POST['name']??''); 
        $location=trim($_POST['location']??''); 
        $note=trim($_POST['note']??''); 
        $active=isset($_POST['active'])?1:0;
        if ($name){
            db()->prepare("INSERT INTO projects (name, location, note, active) VALUES (:n,:l,:no,:a)")
              ->execute([':n'=>$name, ':l'=>$location, ':no'=>$note, ':a'=>$active]);
            $ok='Projekt hozzáadva.';
        } else { $error='A projekt neve kötelező.'; }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $newActive = (int)($_POST['new_active'] ?? 0);
        $stmt = db()->prepare("UPDATE projects SET active=:a WHERE id=:id");
        $stmt->execute([':a'=>$newActive, ':id'=>$id]);
        $ok = $newActive ? 'Projekt aktiválva.' : 'Projekt inaktiválva.';
    }
}

$projects=db()->query("SELECT * FROM projects ORDER BY active DESC, name")->fetchAll();

include __DIR__ . '/common_header.php';
?>
<div class="card">
  <h2>Projektek</h2>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="notice"><?= h($ok) ?></div><?php endif; ?>

  <div class="grid cols-2">
    <div>
      <h3>Új projekt</h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create">
        <label>Név</label><input name="name" required>
        <label>Helyszín</label><input name="location">
        <label>Megjegyzés</label><textarea name="note"></textarea>
        <label><input type="checkbox" name="active" checked> Aktív</label>
        <div class="mt10"><button>Hozzáadás</button></div>
      </form>
    </div>

    <div>
      <h3>Lista</h3>
      <div class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Név</th>
            <th>Helyszín</th>
            <th>Megjegyzés</th>
            <th>Állapot</th>
            <th>Létrehozva</th>
            <th>Művelet</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td data-label="Név"><?= h($p['name']) ?></td>
              <td data-label="Helyszín"><?= h($p['location']) ?></td>
              <td data-label="Megjegyzés"><?= h($p['note']) ?></td>
              <td data-label="Állapot">
                <?php if ($p['active']): ?>
                  <span class="badge">aktív</span>
                <?php else: ?>
                  <span class="badge">nem aktív</span>
                <?php endif; ?>
              </td>
              <td data-label="Létrehozva"><?= h($p['created_at']) ?></td>
              <td data-label="Művelet">
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <?php if ($p['active']): ?>
                    <input type="hidden" name="new_active" value="0">
                    <button class="secondary">Inaktivál</button>
                  <?php else: ?>
                    <input type="hidden" name="new_active" value="1">
                    <button>Aktivál</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$projects): ?>
            <tr><td colspan="6">Nincs projekt.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/common_footer.php'; ?>

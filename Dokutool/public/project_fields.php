<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

// (Fejlesztéshez) hiba kijelzés bekapcsolása – élesben kivehető
ini_set('display_errors', 1);
error_reporting(E_ALL);

$err = null; $ok = null;

if (($_POST['action'] ?? '') === 'create') {
  try {
    $name = strtolower(trim($_POST['name'] ?? ''));
    if (!preg_match('/^[a-z0-9_]{2,64}$/', $name)) {
      throw new Exception('Azonosító: csak kisbetű/szám/alsóvonás (2–64 karakter).');
    }
    $label = trim($_POST['label'] ?? '');
    if ($label === '') throw new Exception('Címke kötelező.');
    $type = $_POST['type'] ?? 'text';
    $required = isset($_POST['required']) ? 1 : 0;
    $sort = (int)($_POST['sort_order'] ?? 0);

    $options = null;
    if (in_array($type, ['select']) && trim($_POST['options'] ?? '') !== '') {
      $ops = array_values(array_filter(array_map('trim', explode("\n", $_POST['options']))));
      $options = json_encode($ops, JSON_UNESCAPED_UNICODE);
    }

    $stmt = $pdo->prepare("INSERT INTO project_fields (name,label,type,options_json,required,sort_order,active)
                           VALUES (:name,:label,:type,:opts,:req,:sort,1)");
    $stmt->execute([
      ':name' => $name, ':label' => $label, ':type' => $type,
      ':opts' => $options, ':req' => $required, ':sort' => $sort
    ]);
    $ok = 'Mező létrehozva.';
  } catch (Throwable $e) { $err = 'Hiba: '.$e->getMessage(); }
}

if (($_POST['action'] ?? '') === 'toggle') {
  try {
    $id = (int)($_POST['id'] ?? 0);
    $active = (int)($_POST['active'] ?? 1);
    $pdo->prepare("UPDATE project_fields SET active=:a WHERE id=:id")->execute([':a'=>$active, ':id'=>$id]);
    $ok = 'Állapot módosítva.';
  } catch (Throwable $e) { $err = 'Hiba: '.$e->getMessage(); }
}

if (($_POST['action'] ?? '') === 'delete') {
  try {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM project_fields WHERE id=:id")->execute([':id'=>$id]);
    $ok = 'Mező törölve.';
  } catch (Throwable $e) { $err = 'Hiba: '.$e->getMessage(); }
}

$fields = $pdo->query("SELECT * FROM project_fields ORDER BY sort_order ASC, id ASC")->fetchAll();
?>
  <div class="container">
    <div class="hdr card">
      <div><strong>Projekt mezők</strong></div>
      <div style="display:flex; gap:8px;">
        <a class="btn" href="index.php">← Főmenü</a>
        <a class="btn" href="projects.php">Projektek</a>
      </div>
    </div>

    <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>
    <?php if($ok): ?><div class="ok"><?=$ok?></div><?php endif; ?>

    <div class="card">
      <h3>Új mező</h3>
      <form method="post" class="grid cols-3">
        <input type="hidden" name="action" value="create">
        <div class="input"><label>Azonosító (pl. helyszin_tipus)</label><input name="name" required></div>
        <div class="input"><label>Címke</label><input name="label" required></div>
        <div class="input"><label>Típus</label>
          <select name="type">
            <option value="text">Szöveg</option>
            <option value="number">Szám</option>
            <option value="date">Dátum</option>
            <option value="email">Email</option>
            <option value="tel">Telefon</option>
            <option value="textarea">Többsoros</option>
            <option value="select">Választólista</option>
            <option value="checkbox">Jelölőnégyzet</option>
          </select>
        </div>
        <div class="input" style="grid-column:1/-1;"><label>Választólista opciók (soronként egy, csak 'select' típusnál)</label><textarea name="options" rows="4" placeholder="opció 1
opció 2"></textarea></div>
        <div class="input"><label>Kötelező</label><input type="checkbox" name="required" value="1"></div>
        <div class="input"><label>Sorrend</label><input type="number" name="sort_order" value="0"></div>
        <div style="align-self:end;"><button class="btn primary" type="submit">Létrehoz</button></div>
      </form>
    </div>

    <div class="card">
      <h3>Mezők</h3>
      <table class="table">
        <thead><tr><th>ID</th><th>Azonosító</th><th>Címke</th><th>Típus</th><th>Kötelező</th><th>Aktív</th><th>Sorrend</th><th>Művelet</th></tr></thead>
        <tbody>
          <?php foreach($fields as $f): ?>
          <tr>
            <td><?=$f['id']?></td>
            <td><?=htmlspecialchars($f['name'])?></td>
            <td><?=htmlspecialchars($f['label'])?></td>
            <td><?=$f['type']?></td>
            <td><?=$f['required']?'Igen':'Nem'?></td>
            <td><?=$f['active']?'Igen':'Nem'?></td>
            <td><?=$f['sort_order']?></td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?=$f['id']?>">
                <input type="hidden" name="active" value="<?=$f['active']?0:1?>">
                <button class="btn" type="submit"><?=$f['active']?'Kikapcsol':'Bekapcsol'?></button>
              </form>
              <form method="post" style="display:inline;" onsubmit="return confirm('Biztosan törlöd?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=$f['id']?>">
                <button class="btn ghost" type="submit">Törlés</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($fields)): ?><tr><td colspan="8"><em>Még nincs mező.</em></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php include __DIR__ . '/footer.php'; ?>

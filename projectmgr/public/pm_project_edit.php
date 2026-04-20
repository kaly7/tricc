<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/app/Activity.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers; use App\Activity;

Auth::start(); Middleware::requireAuth();

$pdo = Db::pdo();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($_SERVER['REQUEST_METHOD']==='POST' && !$id) {
  $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
}
if (!$id) { http_response_code(400); exit('Hibás projekt ID'); }

// Core update
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form'] ?? '')==='core') {
  if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $number = preg_replace('~[^A-Za-z0-9\-_]~','', trim($_POST['number'] ?? ''));
  $name   = trim($_POST['name'] ?? '');
  $start  = trim($_POST['start_date'] ?? '') ?: null;
  $desc   = trim($_POST['description'] ?? '');
  $arch   = (int)($_POST['archived'] ?? 0);
  if (!$number || !$name) { Helpers::flash('err','Szám és név kötelező'); header('Location: /pm_project_edit.php?id='.$id); exit; }
  $st = $pdo->prepare('UPDATE projects SET number=?, code=?, name=?, start_date=?, description=?, archived=? WHERE id=?');
  $st->execute([$number,$number,$name,$start,$desc,$arch,$id]);
  Activity::log($id, (int)Auth::user()['id'], 'project.update', ['number'=>$number,'name'=>$name,'archived'=>$arch]);
  Helpers::flash('ok','Projekt adatok frissítve');
  header('Location: /pm_project_edit.php?id='.$id); exit;
}

// Milestones
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form'] ?? '')==='ms') {
  if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $action = $_POST['action'] ?? '';
  if ($action==='add') {
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['due_date'] ?? '') ?: null;
    $notes= trim($_POST['notes'] ?? '');
    if ($name) {
      $st = $pdo->prepare('INSERT INTO project_milestones (project_id,name,due_date,notes) VALUES (?,?,?,?)');
      $st->execute([$id,$name,$date,$notes]);
      Activity::log($id, (int)Auth::user()['id'], 'milestone.add', ['name'=>$name,'date'=>$date]);
      Helpers::flash('ok','Mérföldkő hozzáadva');
    }
  } elseif ($action==='del') {
    $mid = (int)($_POST['mid'] ?? 0);
    if ($mid>0) {
      $name = $pdo->prepare('SELECT name FROM project_milestones WHERE id=? AND project_id=?');
      $name->execute([$mid,$id]);
      $mn = $name->fetchColumn();
      $st = $pdo->prepare('DELETE FROM project_milestones WHERE id=? AND project_id=?');
      $st->execute([$mid,$id]);
      Activity::log($id, (int)Auth::user()['id'], 'milestone.delete', ['id'=>$mid,'name'=>$mn]);
      Helpers::flash('ok','Mérföldkő törölve');
    }
  }
  header('Location: /pm_project_edit.php?id='.$id); exit;
}

// Custom fields
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form'] ?? '')==='cf') {
  if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $action = $_POST['action'] ?? '';
  if ($action==='add') {
    $k = trim($_POST['field_key'] ?? '');
    $v = trim($_POST['field_value'] ?? '');
    if ($k!=='') {
      $st=$pdo->prepare('INSERT INTO project_fields (project_id,field_key,field_value) VALUES (?,?,?)');
      $st->execute([$id,$k,$v]);
      Activity::log($id, (int)Auth::user()['id'], 'field.add', ['key'=>$k]);
      Helpers::flash('ok','Extra mező hozzáadva');
    }
  } elseif ($action==='del') {
    $fid = (int)($_POST['fid'] ?? 0);
    if ($fid>0) {
      $k = $pdo->prepare('SELECT field_key FROM project_fields WHERE id=? AND project_id=?');
      $k->execute([$fid,$id]);
      $kk = $k->fetchColumn();
      $st=$pdo->prepare('DELETE FROM project_fields WHERE id=? AND project_id=?');
      $st->execute([$fid,$id]);
      Activity::log($id, (int)Auth::user()['id'], 'field.delete', ['id'=>$fid,'key'=>$kk]);
      Helpers::flash('ok','Extra mező törölve');
    }
  }
  header('Location: /pm_project_edit.php?id='.$id); exit;
}

// Per-project dir template
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form'] ?? '')==='pdir') {
  if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }
  $action = $_POST['action'] ?? '';
  if ($action==='add') {
    $path = trim($_POST['path'] ?? '');
    $sort = (int)($_POST['sort'] ?? 0);
    if ($path!=='') {
      $st=$pdo->prepare('INSERT INTO project_dir_templates (project_id,path,sort) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sort=VALUES(sort)');
      $st->execute([$id,$path,$sort]);
      Activity::log($id, (int)Auth::user()['id'], 'pdir.add', ['path'=>$path,'sort'=>$sort]);
      Helpers::flash('ok','Projekt-specifikus mappa hozzáadva/frissítve');
    }
  } elseif ($action==='del') {
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid>0) {
      $p = $pdo->prepare('SELECT path FROM project_dir_templates WHERE id=? AND project_id=?');
      $p->execute([$pid,$id]);
      $pp = $p->fetchColumn();
      $st=$pdo->prepare('DELETE FROM project_dir_templates WHERE id=? AND project_id=?');
      $st->execute([$pid,$id]);
      Activity::log($id, (int)Auth::user()['id'], 'pdir.delete', ['id'=>$pid,'path'=>$pp]);
      Helpers::flash('ok','Projekt-specifikus mappa törölve');
    }
  } elseif ($action==='mkdirs') {
    $cfg = require dirname(__DIR__).'/config/config.php';
    $uploadRoot = rtrim($cfg['upload_root'],'/');
    $q = $pdo->prepare('SELECT root_dir FROM projects WHERE id=?');
    $q->execute([$id]);
    $rootRel = (string)($q->fetchColumn());
    if ($rootRel!=='') {
      $absRoot = $uploadRoot.'/'.$rootRel;
      if (!is_dir($absRoot)) @mkdir($absRoot, 0775, true);
      $g = $pdo->query('SELECT path FROM dir_templates ORDER BY sort, path')->fetchAll(PDO::FETCH_COLUMN);
      $p = $pdo->prepare('SELECT path FROM project_dir_templates WHERE project_id=? ORDER BY sort, path');
      $p->execute([$id]);
      $pp = $p->fetchAll(PDO::FETCH_COLUMN);
      $all = [];
      foreach (array_merge($g ?: [], $pp ?: []) as $d) { $all[$d]=true; }
      foreach (array_keys($all) as $d) {
        $p = $absRoot.'/'.preg_replace("~[\\/]+~", "/", $d);
        @mkdir($p, 0775, true);
      }
      Activity::log($id, (int)Auth::user()['id'], 'dirs.sync', ['count'=>count($all)]);
      Helpers::flash('ok','Hiányzó könyvtárak létrehozva');
    }
  }
  header('Location: /pm_project_edit.php?id='.$id); exit;
}

// Load current data
$st = $pdo->prepare('SELECT * FROM projects WHERE id=?');
$st->execute([$id]);
$proj = $st->fetch(PDO::FETCH_ASSOC);
if (!$proj) { http_response_code(404); exit('Projekt nem található'); }

$ms = $pdo->prepare('SELECT * FROM project_milestones WHERE project_id=? ORDER BY due_date IS NULL, due_date');
$ms->execute([$id]);
$milestones = $ms->fetchAll(PDO::FETCH_ASSOC);

$cf = $pdo->prepare('SELECT * FROM project_fields WHERE project_id=? ORDER BY field_key');
$cf->execute([$id]);
$fields = $cf->fetchAll(PDO::FETCH_ASSOC);

$mem = $pdo->prepare('SELECT pm.user_id, pm.role, u.name, u.email FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=? ORDER BY u.name');
$mem->execute([$id]);
$members = $mem->fetchAll(PDO::FETCH_ASSOC);

$gdirs = $pdo->query('SELECT * FROM dir_templates ORDER BY sort, path')->fetchAll(PDO::FETCH_ASSOC);
$pdirs = $pdo->prepare('SELECT * FROM project_dir_templates WHERE project_id=? ORDER BY sort, path');
$pdirs->execute([$id]);
$pdirs_rows = $pdirs->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Projekt szerkesztése: <?= htmlspecialchars($proj['number'].' — '.$proj['name']) ?></h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/pm_projects.php">Vissza</a>
    <a class="btn btn-outline-primary" href="/pm_project_assign.php?id=<?= (int)$proj['id'] ?>">Felhasználók rendelése</a>
    <a class="btn btn-outline-info" href="/pm_project_log.php?id=<?= (int)$proj['id'] ?>">Projekt napló</a>
    <a class="btn btn-outline-success" href="/pm_files.php?id=<?= (int)$proj['id'] ?>">Fájlok</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card p-3 mb-3">
      <h2 class="h6 mb-3">Alapadatok</h2>
      <form method="post" action="/pm_project_edit.php?id=<?= (int)$id ?>">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="form" value="core">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="mb-2">
          <label class="form-label">Projekt száma</label>
          <input name="number" class="form-control" value="<?= htmlspecialchars($proj['number']) ?>" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Név</label>
          <input name="name" class="form-control" value="<?= htmlspecialchars($proj['name']) ?>" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Indítás dátuma</label>
          <input name="start_date" type="date" class="form-control" value="<?= htmlspecialchars($proj['start_date'] ?? '') ?>">
        </div>
        <div class="mb-2">
          <label class="form-label">Leírás</label>
          <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($proj['description'] ?? '') ?></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">Állapot</label>
          <select name="archived" class="form-select">
            <option value="0" <?= ((int)$proj['archived']===0)?'selected':''; ?>>Aktív</option>
            <option value="1" <?= ((int)$proj['archived']===1)?'selected':''; ?>>Archivált</option>
          </select>
        </div>
        <button class="btn btn-primary mt-2">Mentés</button>
      </form>
    </div>

    <div class="card p-3">
      <h2 class="h6 mb-3">Mérföldkövek</h2>
      <form method="post" action="/pm_project_edit.php?id=<?= (int)$id ?>" class="row g-2 align-items-end">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="form" value="ms">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="add">
        <div class="col-md-5">
          <label class="form-label">Név</label>
          <input name="name" class="form-control" placeholder="pl. Átadás">
        </div>
        <div class="col-md-3">
          <label class="form-label">Dátum</label>
          <input name="due_date" type="date" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">Megjegyzés</label>
          <input name="notes" class="form-control">
        </div>
        <div class="col-12">
          <button class="btn btn-sm btn-primary">Hozzáad</button>
        </div>
      </form>
      <table class="table table-sm mt-3">
        <thead><tr><th>Név</th><th>Dátum</th><th>Megjegyzés</th><th></th></tr></thead>
        <tbody>
          <?php foreach($milestones as $m): ?>
            <tr>
              <td><?= htmlspecialchars($m['name']) ?></td>
              <td><?= htmlspecialchars($m['due_date'] ?? '') ?></td>
              <td><?= htmlspecialchars($m['notes'] ?? '') ?></td>
              <td>
                <form method="post" action="/pm_project_edit.php?id=<?= (int)$id ?>" class="d-inline" onsubmit="return confirm('Törlöd a mérföldkövet?');">
                  <?= \App\Csrf::field() ?>
                  <input type="hidden" name="form" value="ms">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">
                  <input type="hidden" name="action" value="del">
                  <input type="hidden" name="mid" value="<?= (int)$m['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Törlés</button>
                </form>
              </td>
            </tr>
          <?php endforeach; if (!$milestones): ?>
            <tr><td colspan="4" class="text-muted">Még nincs mérföldkő.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-3 mb-3">
      <h2 class="h6 mb-2">Tagok (hozzárendelve)</h2>
      <table class="table table-sm m-0">
        <thead><tr><th>Név</th><th>Szerep</th></tr></thead>
        <tbody>
          <?php foreach($members as $m): ?>
            <tr>
              <td><?= htmlspecialchars($m['name'].' <'.$m['email'].'>') ?></td>
              <td><?= htmlspecialchars($m['role']) ?></td>
            </tr>
          <?php endforeach; if (!$members): ?>
            <tr><td colspan="2" class="text-muted">Nincsenek még tagok.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card p-3">
      <h2 class="h6 mb-2">Könyvtárséma</h2>
      <p class="text-muted mb-2">A gomb a <em>globális sablon</em> és a <em>projekt-specifikus</em> listák egyesítésével hozza létre a hiányzó könyvtárakat.</p>
      <form method="post" action="/pm_project_edit.php?id=<?= (int)$id ?>" class="mb-3">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="form" value="pdir">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="mkdirs">
        <button class="btn btn-sm btn-primary">Hiányzó könyvtárak létrehozása</button>
        <a class="btn btn-sm btn-outline-secondary" href="/pm_dir_template.php">Globális sablon kezelése</a>
        <a class="btn btn-sm btn-outline-success" href="/pm_files.php?id=<?= (int)$id ?>">Fájlok</a>
      </form>

      <div class="row g-3">
        <div class="col-md-6">
          <h3 class="h6">Globális sablon</h3>
          <table class="table table-sm">
            <thead><tr><th>Útvonal</th><th>Sorrend</th></tr></thead>
            <tbody>
              <?php foreach($gdirs as $g): ?>
                <tr><td><?= htmlspecialchars($g['path']) ?></td><td><?= (int)$g['sort'] ?></td></tr>
              <?php endforeach; if(!$gdirs): ?>
                <tr><td colspan="2" class="text-muted">Még nincs beállítva.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="col-md-6">
          <h3 class="h6">Projekt-specifikus</h3>
          <form method="post" action="/pm_project_edit.php?id=<?= (int)$id ?>" class="row g-2 align-items-end">
            <?= \App\Csrf::field() ?>
            <input type="hidden" name="form" value="pdir">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-8">
              <label class="form-label">Útvonal</label>
              <input name="path" class="form-control" placeholder="pl. 07_Logok">
            </div>
            <div class="col-4">
              <label class="form-label">Sorrend</label>
              <input name="sort" type="number" class="form-control" value="0">
            </div>
            <div class="col-12">
              <button class="btn btn-sm btn-primary">Hozzáadás / Frissítés</button>
            </div>
          </form>
          <table class="table table-sm mt-2">
            <thead><tr><th>Útvonal</th><th>Sorrend</th><th></th></tr></thead>
            <tbody>
              <?php foreach($pdirs_rows as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['path']) ?></td>
                  <td><?= (int)$r['sort'] ?></td>
                  <td>
                    <form method="post" action="/pm_project_edit.php?id=<?= (int)$id ?>" class="d-inline" onsubmit="return confirm('Törlöd?');">
                      <?= \App\Csrf::field() ?>
                      <input type="hidden" name="form" value="pdir">
                      <input type="hidden" name="id" value="<?= (int)$id ?>">
                      <input type="hidden" name="action" value="del">
                      <input type="hidden" name="pid" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">Törlés</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; if(!$pdirs_rows): ?>
                <tr><td colspan="3" class="text-muted">Ehhez a projekthez még nincs egyedi bejegyzés.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';

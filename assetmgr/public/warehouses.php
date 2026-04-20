<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require __DIR__.'/../app/warehouses.php';
require_login();
require_role('admin');
$pdo = db();
$title='Raktárak';
$page='Raktárak';

if (!warehouses_schema_ready($pdo)) {
  require __DIR__.'/_header.php';
  ?>
  <div class="container" style="max-width:960px">
    <div class="alert alert-warning">
      A raktár modul még nincs migrálva. Futtasd: <code>migrations/warehouses_phase1.sql</code>
    </div>
  </div>
  <?php
  require __DIR__.'/_footer.php';
  exit;
}

$users = auth_active_users_for_warehouse_admins();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  if ((string)($_POST['action'] ?? '') === 'save_warehouse') {
    $id=(int)($_POST['id'] ?? 0);
    $name=trim((string)($_POST['name'] ?? ''));
    $location=trim((string)($_POST['location'] ?? ''));
    $note=trim((string)($_POST['note'] ?? ''));
    $isActive=isset($_POST['is_active']) ? 1 : 0;
    $admins=$_POST['admin_user_ids'] ?? [];
    if (!is_array($admins)) $admins=[];
    $admins=array_values(array_unique(array_filter(array_map('intval',$admins), fn($v)=>$v>0)));

    if ($name==='') {
      flash_set('err','A raktár neve kötelező.');
      header('Location: warehouses.php');
      exit;
    }

    $pdo->beginTransaction();
    try {
      if ($id>0) {
        $pdo->prepare("UPDATE warehouses SET name=?, location=?, note=?, is_active=? WHERE id=?")
            ->execute([$name, $location!==''?$location:null, $note!==''?$note:null, $isActive, $id]);
      } else {
        $pdo->prepare("INSERT INTO warehouses (name, location, note, is_active) VALUES (?,?,?,?)")
            ->execute([$name, $location!==''?$location:null, $note!==''?$note:null, $isActive]);
        $id=(int)$pdo->lastInsertId();
      }
      $pdo->prepare("DELETE FROM warehouse_admins WHERE warehouse_id=?")->execute([$id]);
      if ($admins) {
        $st=$pdo->prepare("INSERT INTO warehouse_admins (warehouse_id, user_id) VALUES (?,?)");
        foreach($admins as $uid) $st->execute([$id,$uid]);
      }
      $pdo->commit();
      flash_set('ok','Raktár mentve.');
    } catch (Throwable $e) {
      $pdo->rollBack();
      flash_set('err','Hiba raktár mentésekor: '.$e->getMessage());
    }
    header('Location: warehouses.php');
    exit;
  }
}

$warehouses=$pdo->query("SELECT * FROM warehouses ORDER BY is_active DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$adminsByWh=[];
foreach($pdo->query("SELECT warehouse_id,user_id FROM warehouse_admins")->fetchAll(PDO::FETCH_ASSOC) as $r){
  $adminsByWh[(int)$r['warehouse_id']][]=(int)$r['user_id'];
}
$editId=(int)($_GET['edit'] ?? 0);
$edit=['id'=>0,'name'=>'','location'=>'','note'=>'','is_active'=>1];
if($editId>0){ foreach($warehouses as $w){ if((int)$w['id']===$editId){ $edit=$w; break; } } }

require __DIR__.'/_header.php';
?>
<div class="container" style="max-width:980px">
  <div class="row g-3">
    <div class="col-12 col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="mb-3"><?= $editId>0 ? 'Raktár szerkesztése' : 'Új raktár' ?></h5>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_warehouse">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="mb-2">
              <label class="form-label">Raktár neve</label>
              <input class="form-control" name="name" required value="<?= e((string)($edit['name'] ?? '')) ?>">
            </div>
            <div class="mb-2">
              <label class="form-label">Helyszín</label>
              <input class="form-control" name="location" value="<?= e((string)($edit['location'] ?? '')) ?>">
            </div>
            <div class="mb-2">
              <label class="form-label">Megjegyzés</label>
              <textarea class="form-control" name="note" rows="3"><?= e((string)($edit['note'] ?? '')) ?></textarea>
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= !empty($edit['is_active']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_active">Aktív</label>
            </div>
            <div class="mb-3">
              <label class="form-label">Raktár-adminok</label>
              <div class="border rounded p-2" style="max-height:260px; overflow:auto;">
                <?php $selectedAdmins = array_map('intval', $adminsByWh[(int)($edit['id'] ?? 0)] ?? []); ?>
                <?php foreach ($users as $usr): $uid=(int)$usr['id']; ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="admin_user_ids[]" value="<?= $uid ?>" id="adm<?= $uid ?>" <?= in_array($uid, $selectedAdmins, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="adm<?= $uid ?>"><?= e((string)($usr['full_name'] ?: $usr['username'] ?: $usr['email'])) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit">Mentés</button>
              <?php if ($editId > 0): ?><a class="btn btn-outline-secondary" href="warehouses.php">Új</a><?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="mb-3">Raktárak</h5>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Név</th><th>Helyszín</th><th>Státusz</th><th>Adminok</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($warehouses as $w): ?>
                  <?php
                    $wid=(int)$w['id']; $admNames=[];
                    foreach (($adminsByWh[$wid] ?? []) as $uid) {
                      foreach ($users as $usr) {
                        if ((int)$usr['id']===(int)$uid) { $admNames[]=(string)($usr['full_name'] ?: $usr['username'] ?: $usr['email']); break; }
                      }
                    }
                  ?>
                  <tr>
                    <td><?= e((string)$w['name']) ?></td>
                    <td><?= e((string)($w['location'] ?? '')) ?></td>
                    <td><?= !empty($w['is_active']) ? '<span class="badge bg-success">aktív</span>' : '<span class="badge bg-secondary">inaktív</span>' ?></td>
                    <td class="small"><?= e(implode(', ', $admNames)) ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" href="warehouses.php?edit=<?= $wid ?>">Szerkeszt</a>
                      <a class="btn btn-sm btn-outline-secondary" href="warehouse_stock.php?warehouse_id=<?= $wid ?>">Készlet</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$warehouses): ?><tr><td colspan="5" class="text-secondary">Még nincs raktár.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__.'/_footer.php'; ?>

<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');

$u=current_user();
$canEdit = (($u['role'] ?? '') !== 'viewer');

$pdo=db();
require_once __DIR__.'/../app/categories.php';
$cats = fetch_categories_tree($pdo);

$title='Kategóriák';
$page='Kategóriák';

if ($_SERVER['REQUEST_METHOD']==='POST' && $canEdit) {
  verify_csrf();
  $name=trim((string)($_POST['name'] ?? ''));
  $parent=(int)($_POST['parent_id'] ?? 0);
  if ($name==='') { flash_set('err','Név kötelező.'); header('Location: categories.php'); exit; }
  $pdo->prepare("INSERT INTO categories (name,parent_id,sort_order) VALUES (?,?,0)")->execute([$name, $parent>0?$parent:null]);
  flash_set('ok','Kategória létrehozva.');
  header('Location: categories.php');
  exit;
}

require __DIR__.'/_header.php';
?>
<div class="container" style="max-width:900px">
  <?php if ($msg=flash_get('ok')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg=flash_get('err')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>

  <?php if ($canEdit): ?>
  <div class="card mb-3">
    <div class="card-body">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <div class="col-md-6">
          <label class="form-label">Új kategória neve</label>
          <input class="form-control" name="name" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Szülő</label>
          <select class="form-select" name="parent_id">
            <option value="0">— nincs —</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= str_repeat('— ', (int)$c['_depth']) . e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <button class="btn btn-success w-100">Hozzáad</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table table-sm table-striped">
      <thead><tr><th>ID</th><th>Név</th><th>Szint</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cats as $c): ?>
        <tr>
          <td><?= (int)$c['id'] ?></td>
          <td><?= str_repeat('— ', (int)$c['_depth']) . e($c['name']) ?></td>
          <td><?= (int)$c['_depth'] ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?>
              <a class="btn btn-sm btn-outline-primary" href="category_edit.php?id=<?= (int)$c['id'] ?>">Szerkeszt</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$cats): ?><tr><td colspan="4" class="text-center text-secondary py-4">Nincs kategória.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__.'/_footer.php'; ?>

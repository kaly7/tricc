<?php
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/auth.php';
require_login();
require_role('admin');
$pdo=db();
$id=(int)($_GET['id'] ?? 0);
$st=$pdo->prepare("SELECT * FROM categories WHERE id=? AND is_deleted=0 LIMIT 1");
$st->execute([$id]);
$c=$st->fetch();
if(!$c){ http_response_code(404); echo "Nincs ilyen kategória."; exit; }

require_once __DIR__.'/../app/categories.php';
$cats=fetch_categories_tree($pdo);

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  if(isset($_POST['delete'])){
    $pdo->prepare("UPDATE categories SET is_deleted=1 WHERE id=?")->execute([$id]);
    flash_set('ok','Törölve.');
    header('Location: categories.php'); exit;
  }
  $name=trim((string)($_POST['name'] ?? ''));
  $parent=(int)($_POST['parent_id'] ?? 0);
  if($name===''){ flash_set('err','Név kötelező.'); header('Location: category_edit.php?id='.$id); exit; }
  $pdo->prepare("UPDATE categories SET name=?, parent_id=? WHERE id=?")->execute([$name, $parent>0?$parent:null, $id]);
  flash_set('ok','Mentve.');
  header('Location: categories.php'); exit;
}

$title='Kategória';
$page='Kategória';
require __DIR__.'/_header.php';
?>
<div class="container" style="max-width:800px">
  <?php if ($msg=flash_get('err')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <div class="card">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label">Név</label>
          <input class="form-control" name="name" value="<?= e($c['name']) ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Szülő</label>
          <select class="form-select" name="parent_id">
            <option value="0">— nincs —</option>
            <?php foreach ($cats as $x): ?>
              <?php if ((int)$x['id'] === (int)$id) continue; ?>
              <option value="<?= (int)$x['id'] ?>" <?= ((int)($c['parent_id']??0)===(int)$x['id'])?'selected':'' ?>>
                <?= str_repeat('— ', (int)$x['_depth']) . e($x['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <button class="btn btn-success">Mentés</button>
        <a class="btn btn-outline-secondary" href="categories.php">Vissza</a>
        <button class="btn btn-outline-danger ms-auto" name="delete" value="1" onclick="return confirm('Biztosan törlöd?');">Törlés</button>
      </div>
    </div>
  </form>
</div>
<?php require __DIR__.'/_footer.php'; ?>

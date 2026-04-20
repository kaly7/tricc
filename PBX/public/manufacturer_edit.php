<?php
require_once __DIR__.'/../app/auth.php';
require_role('admin');
$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare("SELECT * FROM manufacturers WHERE id=?");
$st->execute([$id]);
$m = $st->fetch();
if (!$m) { http_response_code(404); exit('Nincs ilyen gyártó'); }

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $name = trim((string)($_POST['name'] ?? ''));
  $arch = isset($_POST['is_archived']) ? 1 : 0;
  if ($name === '') $err = 'A név kötelező.';
  else {
    try {
      $up = $pdo->prepare("UPDATE manufacturers SET name=?, is_archived=? WHERE id=?");
      $up->execute([$name, $arch, $id]);
      flash_set('ok', 'Mentve.');
      redirect('manufacturers.php');
    } catch (Throwable $e) {
      $err = 'Hiba: '.$e->getMessage();
    }
  }
}

$title = 'Gyártó szerkesztése';
$page = 'Gyártók';
include __DIR__.'/_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1 class="h4 mb-0">Gyártó szerkesztése</h1>
  <a class="btn btn-outline-secondary" href="<?= e(base_url('manufacturers.php')) ?>">← Vissza</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <div class="col-12">
        <label class="form-label">Név</label>
        <input class="form-control" name="name" value="<?= e($m['name']) ?>" required>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_archived" value="1" id="arch" <?= (int)$m['is_archived']===1?'checked':'' ?>>
          <label class="form-check-label" for="arch">Archivált</label>
        </div>
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Mentés</button>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__.'/_footer.php'; ?>

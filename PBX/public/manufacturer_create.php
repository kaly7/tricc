<?php
require_once __DIR__.'/../app/auth.php';
require_role('admin');
$pdo = db();
$err = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $name = trim((string)($_POST['name'] ?? ''));
  if ($name === '') $err = 'A név kötelező.';
  else {
    try {
      $st = $pdo->prepare("INSERT INTO manufacturers(name,is_archived) VALUES(?,0)");
      $st->execute([$name]);
      flash_set('ok', 'Gyártó létrehozva.');
      redirect('manufacturers.php');
    } catch (Throwable $e) {
      $err = 'Hiba: '.$e->getMessage();
    }
  }
}

$title = 'Új gyártó';
$page = 'Gyártók';
include __DIR__.'/_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1 class="h4 mb-0">Új gyártó</h1>
  <a class="btn btn-outline-secondary" href="<?= e(base_url('manufacturers.php')) ?>">← Vissza</a>
</div>

<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <div class="col-12">
        <label class="form-label">Név</label>
        <input class="form-control" name="name" required>
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Mentés</button>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__.'/_footer.php'; ?>

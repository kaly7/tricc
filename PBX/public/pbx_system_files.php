<?php
require __DIR__.'/../app/auth.php';
require_login();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);

// Load PBX
$st = $pdo->prepare("SELECT id,name FROM pbx_systems WHERE id=?");
$st->execute([$id]);
$pbx = $st->fetch();
if(!$pbx){ http_response_code(404); exit('Nincs ilyen központ'); }

$u = current_user();
$uName  = (string)($u['name'] ?? $u['email'] ?? '');
$uEmail = (string)($u['email'] ?? '');

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['files'])) {
  // only editor/admin can upload
  require_role('editor');

  $dir = dirname(__DIR__).'/storage/pbx_files/'.$id;
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

  $files = $_FILES['files'];
  $count = is_array($files['name']) ? count($files['name']) : 0;

  for($i=0;$i<$count;$i++){
    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

    $orig = (string)$files['name'][$i];
    $tmp  = $files['tmp_name'][$i];
    $size = (int)$files['size'][$i];

    $safe = preg_replace('/[^A-Za-z0-9._-]+/','_', $orig);
    if ($safe==='') $safe = 'file';

    $stored = date('Ymd_His').'_'.bin2hex(random_bytes(4)).'_'.$safe;
    $path = $dir.'/'.$stored;

    if (!move_uploaded_file($tmp, $path)) continue;

    $ins = $pdo->prepare("INSERT INTO pbx_files
      (pbx_id, original_name, stored_name, mime, size, uploaded_by, uploaded_by_name, uploaded_by_email, created_at, is_deleted)
      VALUES
      (:pbx_id,:orig,:stored,:mime,:size,NULL,:uname,:uemail,NOW(),0)");
    $ins->execute([
      ':pbx_id'=>$id,
      ':orig'=>$orig,
      ':stored'=>$stored,
      ':mime'=>(string)($files['type'][$i] ?? ''),
      ':size'=>$size,
      ':uname'=>$uName,
      ':uemail'=>$uEmail,
    ]);
  }

  header('Location: '.base_url('pbx_system_files.php?id='.$id));
  exit;
}

// List files (soft-delete via is_deleted)
$list = $pdo->prepare("SELECT * FROM pbx_files WHERE pbx_id=? AND is_deleted=0 ORDER BY created_at DESC, id DESC");
$list->execute([$id]);
$rows = $list->fetchAll();

$title='Központ dokumentumok';
$page='Központok';
require __DIR__.'/_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h3 mb-1">Dokumentumok</h1>
    <div class="text-muted small"><?= e($pbx['name']) ?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-secondary" href="<?= e(base_url('pbx_system_edit.php?id='.$id)) ?>">Vissza</a>
  </div>
</div>

<?php if (current_user() && in_array((string)(current_user()['role'] ?? ''), ['admin','editor'], true)): ?>
<div class="card mb-3">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <div class="mb-2">
        <label class="form-label">Fájlok feltöltése</label>
        <input class="form-control" type="file" name="files[]" multiple>
        <div class="form-text">Több fájlt is kiválaszthatsz egyszerre.</div>
      </div>
      <button class="btn btn-primary" type="submit">Feltöltés</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <?php if (!$rows): ?>
      <div class="text-muted">Nincs feltöltött dokumentum.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Fájl</th>
              <th class="text-nowrap">Feltöltő</th>
              <th class="text-nowrap">Dátum</th>
              <th class="text-end">Művelet</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?= e($r['original_name']) ?></div>
                  <div class="text-muted small"><?= e($r['mime'] ?: '—') ?> · <?= (int)$r['size'] ?> B</div>
                </td>
                <td class="small">
                  <?= e(($r['uploaded_by_name'] ?: $r['uploaded_by_email']) ?: '—') ?>
                </td>
                <td class="small text-nowrap"><?= e($r['created_at']) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary" href="<?= e(base_url('pbx_file_download.php?id='.(int)$r['id'])) ?>">Letöltés</a>
                  <?php if (current_user() && in_array((string)(current_user()['role'] ?? ''), ['admin','editor'], true)): ?>
                    <a class="btn btn-sm btn-outline-danger"
                       href="<?= e(base_url('pbx_file_delete.php?id='.(int)$r['id'].'&pbx_id='.$id)) ?>"
                       onclick="return confirm('Biztosan törlöd ezt a fájlt?');">Törlés</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__.'/_footer.php'; ?>

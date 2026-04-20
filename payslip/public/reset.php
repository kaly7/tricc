<?php
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../worker/ResetTool.php';

Auth::requireLogin();
if (!Auth::isAdmin()) { http_response_code(403); echo "Forbidden"; exit; }

require __DIR__ . '/_layout.php';

$err = '';
$res = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['confirm'] ?? '');
    if ($token !== 'RESET') {
        $err = "A megerősítéshez írd be pontosan: RESET";
    } else {
        $pdo = Db::pdo();

        $db = ResetTool::resetDatabase($pdo);
        $dirs = [
            'uploads' => UPLOADS_DIR,
            'output'  => OUTPUT_DIR,
            'tmp'     => TMP_DIR,
        ];
        $fs = ResetTool::resetFiles($dirs);

        if (!$db['ok']) $err = "DB hiba: " . $db['error'];
        else $res = ['db'=>$db, 'fs'=>$fs];
    }
}

page_header('Rendszer reset');
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card p-4">
      <h1 class="h5 mb-3 text-danger">Rendszer reset</h1>

      <div class="alert alert-warning">
        <b>Figyelem!</b> Ez a művelet törli az eddigi feldolgozásokat:
        <ul class="mb-0">
          <li>DB: <code>page_jobs</code>, <code>uploads</code>, <code>audit_log</code> (a <code>divisions</code> megmarad)</li>
          <li>Fájlok: <code>storage/uploads</code>, <code>storage/output</code>, <code>storage/tmp</code> tartalma</li>
        </ul>
      </div>

      <?php if ($err): ?>
        <div class="alert alert-danger"><?= h($err) ?></div>
      <?php endif; ?>

      <?php if ($res): ?>
        <div class="alert alert-success">Reset kész.</div>
        <div class="small text-muted">
          <div>DB táblák ürítve: <?= h(implode(', ', $res['db']['tables'])) ?></div>
          <div class="mt-2">
            <?php foreach ($res['fs']['results'] as $k => $r): ?>
              <div><?= h($k) ?>: törölt fájlok: <?= (int)$r['deleted'] ?><?= isset($r['note']) ? ' ('.h($r['note']).')' : '' ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" class="mt-3">
        <div class="mb-3">
          <label class="form-label">Megerősítés</label>
          <input class="form-control" name="confirm" placeholder="Írd be: RESET" required>
          <div class="form-text">Szándékosan kell begépelni.</div>
        </div>
        <button class="btn btn-danger" type="submit">RESET végrehajtása</button>
        <a class="btn btn-outline-secondary" href="index.php">Mégse</a>
      </form>
    </div>
  </div>
</div>
<?php page_footer(); ?>

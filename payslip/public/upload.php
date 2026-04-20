<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

use Services\LoggerService;
use Services\PdfService;

$pdo = Db::pdo();

$divisions = $pdo->query("SELECT id,name,slug FROM divisions WHERE active=1 ORDER BY name ASC")->fetchAll();

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $month = $_POST['month'] ?? '';
    $divisionId = (int)($_POST['division_id'] ?? 0);

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $err = "Hibás hónap (YYYY-MM).";
    if (!$err && $divisionId <= 0) $err = "Divízió kiválasztása kötelező.";

    $div = null;
    if (!$err) {
        $st = $pdo->prepare("SELECT id,name,slug FROM divisions WHERE id=? AND active=1 LIMIT 1");
        $st->execute([$divisionId]);
        $div = $st->fetch();
        if (!$div) $err = "Érvénytelen divízió.";
    }

    if (!$err) {
        if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) $err = "Nincs PDF vagy hiba történt.";
    }

    if (!$err) {
        $tmp = $_FILES['pdf']['tmp_name'];
        $orig = basename($_FILES['pdf']['name']);

        // division-aware folder to avoid collisions
        $monthDivDir = UPLOADS_DIR . '/' . $month . '/' . $div['slug'];
        if (!is_dir($monthDivDir)) mkdir($monthDivDir, 0770, true);

        $stored = $monthDivDir . '/original.pdf';
        if (!move_uploaded_file($tmp, $stored)) $err = "Nem tudtam elmenteni a feltöltést.";
        else {
            $sha = hash_file('sha256', $stored);
            $pages = PdfService::getTotalPages($stored);

            $stmt = $pdo->prepare("INSERT INTO uploads(original_filename, month, division_id, stored_path, total_pages, file_sha256, uploaded_by)
                                   VALUES(?,?,?,?,?,?,?)");
            $stmt->execute([$orig, $month, (int)$div['id'], $stored, $pages, $sha, $_SESSION['user']['username'] ?? null]);
            $uploadId = (int)$pdo->lastInsertId();

            LoggerService::log('INFO', 'UPLOAD', "Upload rögzítve: $orig, oldalak: $pages", $uploadId, null, ['sha256'=>$sha,'division'=>$div['slug']]);
            header("Location: start.php?upload_id=" . $uploadId);
            exit;
        }
    }
}

page_header('PDF feltöltés');
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card p-4">
      <h1 class="h5 mb-3">PDF feltöltés</h1>
      <?php if ($err): ?>
        <div class="alert alert-danger"><?= h($err) ?></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <div class="mb-3">
          <label class="form-label">Hónap (YYYY-MM)</label>
          <input class="form-control" name="month" placeholder="2025-11" required>
        </div>

        <div class="mb-3">
          <label class="form-label">Divízió</label>
          <select class="form-select" name="division_id" required>
            <option value="">Válassz…</option>
            <?php foreach ($divisions as $d): ?>
              <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Ha új divízió kell: admin → “Divíziók”.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">PDF</label>
          <input class="form-control" type="file" name="pdf" accept="application/pdf" required>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-primary" type="submit">Feltöltés</button>
          <a class="btn btn-outline-secondary" href="index.php">Vissza</a>
        </div>
      </form>

      <div class="small text-muted mt-3">
        Feltöltés után automatikusan indul a feldolgozás.
      </div>
    </div>
  </div>
</div>
<?php page_footer(); ?>

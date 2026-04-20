<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

use Services\EmployeeService;
use Services\LoggerService;

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $err = "CSV feltöltés hiba.";
    } else {
        $content = file_get_contents($_FILES['csv']['tmp_name']);
        if ($content === false) $err = "Nem tudtam olvasni a CSV-t.";
        else {
            $lines = preg_split('/\R/u', $content);
            $pdo = Db::pdo();
            $ins = $pdo->prepare("INSERT INTO employees(name, name_norm, email, active)
                                  VALUES(?,?,?,1)
                                  ON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email), active=1");
            $count=0;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line==='' || str_starts_with($line, '#')) continue;
                $sep = (strpos($line, ';') !== false) ? ';' : ',';
                $parts = array_map('trim', explode($sep, $line));
                if (count($parts) < 2) continue;
                $name = $parts[0];
                $email = $parts[1];
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                $norm = EmployeeService::normalizeName($name);
                if ($norm==='') continue;
                $ins->execute([$name, $norm, $email]);
                $count++;
            }
            LoggerService::log('INFO', 'EMP_IMPORT', "Dolgozók import: $count sor", null, null, ['count'=>$count]);
            $msg = "Import kész: $count sor feldolgozva.";
        }
    }
}

page_header('Dolgozók import');
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-4">
      <h1 class="h5 mb-3">Dolgozók import (CSV)</h1>
      <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
      <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <div class="mb-3">
          <label class="form-label">CSV fájl</label>
          <input class="form-control" type="file" name="csv" accept=".csv,text/csv" required>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary" type="submit">Import</button>
          <a class="btn btn-outline-secondary" href="index.php">Vissza</a>
        </div>
      </form>

      <div class="small text-muted mt-3">
        Formátum: <code>Név;Email</code> vagy <code>Név,Email</code> soronként.
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card p-4">
      <h2 class="h6">Példa</h2>
      <pre class="mb-0">Beke Attila;beke.attila@ceg.local
Kiss János;kiss.janos@ceg.local</pre>
    </div>
  </div>
</div>
<?php page_footer(); ?>

<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

page_header('Payslip');
$pdo = Db::pdo();
$uploads = (int)($pdo->query("SELECT COUNT(*) c FROM uploads")->fetch()['c'] ?? 0);
$pageJobs = (int)($pdo->query("SELECT COUNT(*) c FROM page_jobs")->fetch()['c'] ?? 0);
$employees = (int)($pdo->query("SELECT COUNT(*) c FROM employees")->fetch()['c'] ?? 0);
$unmatched = (int)($pdo->query("SELECT COUNT(*) c FROM employees WHERE hr_id IS NULL")->fetch()['c'] ?? 0);
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card p-3">
      <h2 class="h5 mb-3">Menü</h2>
      <div class="d-grid gap-2">
        <a class="btn btn-primary" href="upload.php">PDF feltöltés</a>
        <a class="btn btn-outline-primary" href="log.php">Log / státusz</a>
        <?php if (Auth::isAdmin()): ?>
        <a class="btn btn-outline-warning" href="hr_audit.php">HR – Email audit</a>
        <a class="btn btn-outline-secondary" href="hr_tax_sync.php">HR – Adójel szinkron</a>
        <?php endif; ?>
      </div>
      <div class="text-muted small mt-3">
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card p-3">
      <h2 class="h5 mb-3">Áttekintés</h2>
      <table class="table table-sm mb-0">
        <tbody>
          <tr><th>Feltöltések</th><td><?= $uploads ?></td></tr>
          <tr><th>Oldal rekordok</th><td><?= $pageJobs ?></td></tr>
          <tr>
            <th>Dolgozók (cache)</th>
            <td>
              <?= $employees ?>
              <?php if ($unmatched > 0): ?>
                <a href="employees.php" class="badge bg-warning text-dark ms-1 text-decoration-none"><?= $unmatched ?> nem egyeztetett</a>
              <?php endif; ?>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php page_footer(); ?>

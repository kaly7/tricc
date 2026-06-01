<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

if (!Auth::isAdmin()) { http_response_code(403); echo "Forbidden"; exit; }

$pdo = Db::pdo();

// POST: hr_id szinkronizálás az adójel alapján megtalált rekordokra
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_hr_id') {
    $stmt = $pdo->prepare("
        UPDATE payslip.employees p
        JOIN hr.employees h
          ON h.tax_id COLLATE utf8mb4_unicode_ci = p.tax_id COLLATE utf8mb4_unicode_ci
          AND h.is_active = 1
        SET p.hr_id = h.id,
            p.email = COALESCE(
              CASE WHEN h.payslip_email_target = 'privat' THEN h.email_private ELSE h.email END,
              p.email
            )
        WHERE p.hr_id IS NULL
          AND p.tax_id IS NOT NULL AND p.tax_id != ''
    ");
    $stmt->execute();
    $synced = $stmt->rowCount();
    header('Location: hr_audit.php?synced=' . $synced);
    exit;
}

// 1) Payslip rekordok hr_id nélkül – LEFT JOIN HR-rel, hogy látsszon ki van meg ott és ki nincs
$unmatched = $pdo->query("
  SELECT
    p.id, p.name, p.email, p.tax_id,
    h.id AS h_id, h.full_name AS h_name
  FROM payslip.employees p
  LEFT JOIN hr.employees h
    ON h.tax_id COLLATE utf8mb4_unicode_ci = p.tax_id COLLATE utf8mb4_unicode_ci
    AND h.is_active = 1
  WHERE p.hr_id IS NULL
  ORDER BY p.name
")->fetchAll();

// 2) Aktív HR dolgozók effektív email nélkül (nem tudnánk nekik küldeni)
$noEmail = $pdo->query("
  SELECT id, full_name, tax_id, email, email_private, payslip_email_target,
    CASE WHEN payslip_email_target = 'privat' THEN email_private ELSE email END AS eff_email
  FROM hr.employees
  WHERE is_active = 1
    AND (
      (payslip_email_target = 'privat' AND (email_private IS NULL OR email_private = ''))
      OR
      (payslip_email_target != 'privat' AND (email IS NULL OR email = ''))
    )
  ORDER BY full_name
")->fetchAll();

// Kategorizálás PHP-ban
$unmatchedTrueCount = 0;
$unmatchedSyncCount = 0;
foreach ($unmatched as $r) {
    if ($r['h_id']) $unmatchedSyncCount++;
    else $unmatchedTrueCount++;
}

page_header('HR – Email audit');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">HR – Email audit</h1>
  <div class="d-flex gap-2">
    <?php if ($unmatchedSyncCount > 0): ?>
    <form method="post">
      <input type="hidden" name="action" value="sync_hr_id">
      <button class="btn btn-sm btn-info text-white" type="submit"
              onclick="return confirm('<?= $unmatchedSyncCount ?> rekord hr_id-ját és emailjét szinkronizálja HR-ből. Folytatod?');">
        HR szinkron (<?= $unmatchedSyncCount ?> rekord)
      </button>
    </form>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="index.php">Vissza</a>
  </div>
</div>

<?php if (isset($_GET['synced'])): ?>
  <div class="alert alert-success py-2"><?= (int)$_GET['synced'] ?> payslip rekord hr_id és email szinkronizálva HR-ből.</div>
<?php endif; ?>

<!-- Összesítő -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card text-center p-3 <?= $unmatchedTrueCount ? 'border-warning' : 'border-success' ?>">
      <div class="fs-3 fw-bold <?= $unmatchedTrueCount ? 'text-warning' : 'text-success' ?>"><?= $unmatchedTrueCount ?></div>
      <div class="small text-muted">Valóban nincs HR-ben</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center p-3 <?= $unmatchedSyncCount ? 'border-info' : 'border-success' ?>">
      <div class="fs-3 fw-bold <?= $unmatchedSyncCount ? 'text-info' : 'text-success' ?>"><?= $unmatchedSyncCount ?></div>
      <div class="small text-muted">HR-ben van, szinkron még nem futott</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center p-3 <?= count($noEmail) ? 'border-danger' : 'border-success' ?>">
      <div class="fs-3 fw-bold <?= count($noEmail) ? 'text-danger' : 'text-success' ?>"><?= count($noEmail) ?></div>
      <div class="small text-muted">HR dolgozó email nélkül</div>
    </div>
  </div>
</div>

<!-- 1) Payslip rekordok hr_id nélkül -->
<div class="card p-3 mb-4">
  <h2 class="h6 mb-1">Payslip rekordok hr_id nélkül</h2>
  <p class="text-muted small mb-3">
    Ezek a payslip cache rekordok még nincsenek HR-hez kötve. Oka soronként látható.
  </p>
  <?php if (!$unmatched): ?>
    <div class="text-success small">Nincs ilyen rekord.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr><th>Payslip név</th><th>Adójel</th><th>Email</th><th>Miért?</th><th>Javítás</th></tr>
        </thead>
        <tbody>
          <?php foreach ($unmatched as $r):
            $hasTaxId = !empty($r['tax_id']);
            $inHr     = !empty($r['h_id']);
            if ($inHr) {
                $reasonBadge = '<span class="badge bg-info text-dark">HR-ben van, szinkron még nem futott</span>';
                $reasonText  = 'Megvan HR-ben (' . h($r['h_name']) . '), de a payslip cache-ben még nincs hr_id. Futtass feldolgozást PDF-re, vagy az Adójel szinkron eszközt.';
            } elseif (!$hasTaxId) {
                $reasonBadge = '<span class="badge bg-secondary">Nincs adójel</span>';
                $reasonText  = 'Adójel nélkül nem egyeztethető HR-rel.';
            } else {
                $reasonBadge = '<span class="badge bg-warning text-dark">Nincs HR-ben</span>';
                $reasonText  = 'Ez az adójel (' . h($r['tax_id']) . ') nem szerepel aktív HR rekordban.';
            }
          ?>
            <tr>
              <td><?= h($r['name']) ?></td>
              <td class="text-muted small font-monospace"><?= h($r['tax_id'] ?? '—') ?></td>
              <td class="<?= empty($r['email']) ? 'text-danger' : '' ?>"><?= h($r['email'] ?? '—') ?></td>
              <td>
                <?= $reasonBadge ?>
                <div class="text-muted small mt-1"><?= $reasonText ?></div>
              </td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="employees.php?edit=<?= (int)$r['id'] ?>">Payslip</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- 2) HR dolgozók effektív email nélkül -->
<div class="card p-3 mb-4">
  <h2 class="h6 mb-1">HR dolgozók effektív email nélkül</h2>
  <p class="text-muted small mb-3">
    Ezeknek a dolgozóknak a bérjegyzéket nem lehet automatikusan elküldeni,
    mert a bérjegyzék-célpont beállításuk szerint nincs kitöltve az email cím.
  </p>
  <?php if (!$noEmail): ?>
    <div class="text-success small">Minden aktív HR dolgozónak van effektív email címe.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr><th>HR név</th><th>Adójel</th><th>Célpont</th><th>Céges email</th><th>Privát email</th><th>Miért?</th></tr>
        </thead>
        <tbody>
          <?php foreach ($noEmail as $r):
            $target = ($r['payslip_email_target'] ?? 'ceges');
            if ($target === 'privat') {
                $reason = 'Célpont: <strong>Privát</strong>, de a privát email mező üres. Töltsd ki a HR adatlapon, vagy állítsd át a célpontot Cégesre.';
            } else {
                $reason = 'Célpont: <strong>Céges</strong>, de a céges email mező üres. Töltsd ki a HR adatlapon.';
            }
          ?>
            <tr>
              <td><?= h($r['full_name']) ?></td>
              <td class="text-muted small font-monospace"><?= h($r['tax_id'] ?? '—') ?></td>
              <td>
                <?php if ($target === 'privat'): ?>
                  <span class="badge bg-info text-dark">Privát</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Céges</span>
                <?php endif; ?>
              </td>
              <td class="<?= empty($r['email']) ? 'text-danger fw-bold' : '' ?>"><?= h($r['email'] ?? '—') ?></td>
              <td class="<?= empty($r['email_private']) ? 'text-danger fw-bold' : '' ?>"><?= h($r['email_private'] ?? '—') ?></td>
              <td class="small text-muted"><?= $reason ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php page_footer(); ?>

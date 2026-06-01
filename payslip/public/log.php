<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

$uploadId = (int)($_GET['upload_id'] ?? 0);
$msg = (string)($_GET['msg'] ?? '');
$pdo = Db::pdo();

page_header('Log / státusz');

$uploads = $pdo->query("
  SELECT
    u.id, u.month, u.original_filename, u.total_pages, u.uploaded_at,
    d.name AS division_name,
    COALESCE(s.pending,0)       AS pending,
    COALESCE(s.saved,0)         AS saved,
    COALESCE(s.saved_no_email,0) AS saved_no_email,
    COALESCE(s.mailed,0)        AS mailed,
    COALESCE(s.no_match,0)      AS no_match,
    COALESCE(s.error,0)         AS error_cnt
  FROM uploads u
  LEFT JOIN divisions d ON d.id = u.division_id
  LEFT JOIN (
    SELECT
      upload_id,
      SUM(status='PENDING')  AS pending,
      SUM(status='SAVED')    AS saved,
      SUM(status='SAVED' AND (email_to IS NULL OR email_to='')) AS saved_no_email,
      SUM(status='MAILED')   AS mailed,
      SUM(status='NO_MATCH') AS no_match,
      SUM(status='ERROR')    AS error
    FROM page_jobs
    GROUP BY upload_id
  ) s ON s.upload_id = u.id
  ORDER BY u.id DESC
  LIMIT 50
")->fetchAll();

$pages = [];
$noEmailCount = 0;
if ($uploadId > 0) {
    $st = $pdo->prepare("SELECT * FROM page_jobs WHERE upload_id=? ORDER BY page_no ASC");
    $st->execute([$uploadId]);
    $pages = $st->fetchAll();
    $noEmailCount = count(array_filter($pages, fn($p) =>
        ($p['status'] ?? '') === 'SAVED' && empty($p['email_to'])
    ));
}

function badgeForStatus(string $status): array {
    switch ($status) {
        case 'SAVED':   return ['success', 'Mentve'];
        case 'MAILED':  return ['success', 'Küldve'];
        case 'NO_MATCH':return ['warning', 'Nincs egyezés'];
        case 'ERROR':   return ['danger', 'Hiba'];
        case 'PENDING': return ['secondary', 'Folyamatban'];
        default:        return ['secondary', $status ?: '—'];
    }
}

$isAdmin = Auth::isAdmin();
$overrideTo = defined('MAIL_OVERRIDE_TO') ? (string)MAIL_OVERRIDE_TO : '';
$dryRun = defined('MAIL_DRY_RUN') ? (bool)MAIL_DRY_RUN : false;
?>
<style>
.fn-scroll{ max-width: 220px; overflow-x: auto; white-space: nowrap; }
@media (min-width: 1400px){ .fn-scroll{ max-width: 320px; } }

tr.upload-odd  td { background-color:#ffffff !important; }
tr.upload-even td { background-color:#f8f9fa !important; }
tr.upload-odd td, tr.upload-even td{ border-top: 2px solid #dee2e6; }
thead th{ background-color:#f8f9fa; }
</style>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card p-3">
      <h2 class="h6 mb-3">Feltöltések</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr><th>ID</th><th>Dátum</th><th>Divízió</th><th>Fájl</th><th>Oldal</th><th></th></tr>
          </thead>
          <tbody>
            <?php $i=0; foreach ($uploads as $r): $i++; $cls = ($i % 2) ? 'upload-odd' : 'upload-even'; $id=(int)$r['id']; ?>
              <tr class="<?= $cls ?>">
                <td><?= $id ?></td>
                <td><?= h($r['month']) ?></td>
                <td class="text-truncate" style="max-width:160px"><?= h($r['division_name'] ?? '—') ?></td>
                <td><div class="fn-scroll" title="<?= h($r['original_filename']) ?>"><?= h($r['original_filename']) ?></div></td>
                <td><?= (int)$r['total_pages'] ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-outline-primary" href="log.php?upload_id=<?= $id ?>">Részletek</a>
                  <a class="btn btn-sm btn-primary" href="start.php?upload_id=<?= $id ?>">Indít</a>
                </td>
              </tr>
              <tr class="<?= $cls ?>">
                <td colspan="6">
                  <?php if ((int)$r['mailed'] > 0): ?><span class="badge bg-success me-1">Küldve: <?= (int)$r['mailed'] ?></span><?php endif; ?>
                  <?php if ((int)$r['saved'] > 0): ?><span class="badge bg-success bg-opacity-25 text-success border border-success me-1">Mentve: <?= (int)$r['saved'] ?></span><?php endif; ?>
                  <?php if ((int)$r['saved_no_email'] > 0): ?><span class="badge bg-warning text-dark me-1">Nincs email: <?= (int)$r['saved_no_email'] ?></span><?php endif; ?>
                  <?php if ((int)$r['no_match'] > 0): ?><span class="badge bg-warning text-dark me-1">Nincs egyezés: <?= (int)$r['no_match'] ?></span><?php endif; ?>
                  <?php if ((int)$r['error_cnt'] > 0): ?><span class="badge bg-danger me-1">Hiba: <?= (int)$r['error_cnt'] ?></span><?php endif; ?>
                  <?php if ((int)$r['pending'] > 0): ?><span class="badge bg-secondary me-1">Folyamatban: <?= (int)$r['pending'] ?></span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="small text-muted mt-2">
        <span class="badge bg-success bg-opacity-25 text-success border border-success">Mentve</span> ·
        <span class="badge bg-success">Küldve</span> ·
        <span class="badge bg-warning text-dark">Nincs egyezés</span> ·
        <span class="badge bg-danger">Hiba</span> ·
        <span class="badge bg-secondary">Folyamatban</span>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-3">
      <h2 class="h6 mb-3">Oldal részletek</h2>

      <?php if ($msg): ?>
        <?php if ($msg === 'sent'): ?>
          <div class="alert alert-success py-2">Küldés kész.</div>
        <?php elseif ($msg === 'dry_run'): ?>
          <div class="alert alert-info py-2">Dry-run: nem történt küldés (csak log).</div>
        <?php elseif ($msg === 'missing_file'): ?>
          <div class="alert alert-danger py-2">A PDF fájl nem található a küldéshez.</div>
        <?php elseif ($msg === 'bad_email'): ?>
          <div class="alert alert-danger py-2">Hibás email cím a küldéshez.</div>
        <?php else: ?>
          <div class="alert alert-danger py-2">Küldés hiba.</div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($uploadId <= 0): ?>
        <div class="text-muted">Válassz egy feltöltést.</div>
      <?php else: ?>
        <?php if ($dryRun): ?>
          <div class="alert alert-warning py-2">MAIL_DRY_RUN aktív: a rendszer nem küld levelet, csak logol.</div>
        <?php endif; ?>
        <?php if ($overrideTo): ?>
          <div class="alert alert-warning py-2">MAIL_OVERRIDE_TO aktív: minden levél ide megy: <b><?= h($overrideTo) ?></b></div>
        <?php endif; ?>
        <?php if ($noEmailCount > 0): ?>
          <div class="alert alert-warning py-2">
            <strong><?= $noEmailCount ?> oldal</strong> mentve, de hiányzik az email cím &mdash;
            <a href="employees.php" class="alert-link">Dolgozók szerkesztése &rarr;</a>
          </div>
        <?php endif; ?>

        <div class="d-flex gap-2 align-items-center mb-2">
          <span class="badge bg-secondary">upload_id: <?= (int)$uploadId ?></span>
          <a class="btn btn-sm btn-outline-secondary" href="start.php?upload_id=<?= (int)$uploadId ?>">Újraindít</a>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr><th>Oldal</th><th>Név</th><th>Státusz</th><th>Fájl</th><th>Hiba</th></tr>
            </thead>
            <tbody>
              <?php foreach ($pages as $p):
                [$badge, $label] = badgeForStatus((string)($p['status'] ?? ''));
                $jobId = (int)$p['id'];
                $hasFile = !empty($p['output_path']);
                $emailTo = (string)($p['email_to'] ?? '');
                $status = (string)($p['status'] ?? '');

                $validEmail = (bool)filter_var($emailTo, FILTER_VALIDATE_EMAIL);

                // Button rules:
                // - SAVED: send to stored email_to
                // - MAILED: resend to stored email_to
                // - NO_MATCH: allow manual email entry and send
                $canSendSaved = $isAdmin && $hasFile && $validEmail && ($status === 'SAVED');
                $canResend    = $isAdmin && $hasFile && $validEmail && ($status === 'MAILED');
                $canSendNoMatch = $isAdmin && $hasFile && ($status === 'NO_MATCH');
              ?>
                <tr>
                  <td><?= (int)$p['page_no'] ?></td>
                  <td><?= h($p['extracted_name'] ?? '') ?></td>
                  <td>
                    <span class="badge bg-<?= h($badge) ?> <?= ($status==='SAVED') ? 'bg-opacity-25 text-success border border-success' : '' ?>">
                      <?= h($label) ?>
                    </span>
                    <?php if ($status === 'SAVED' && $emailTo === ''): ?>
                      <span class="badge bg-warning text-dark ms-1">Nincs email</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($hasFile): ?>
                      <div class="d-flex flex-wrap gap-2 align-items-center">
                        <a class="btn btn-sm btn-outline-primary" href="download.php?job_id=<?= $jobId ?>">Letöltés</a>

                        <?php if ($canSendSaved): ?>
                          <form method="post" action="resend.php" style="display:inline">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <button class="btn btn-sm btn-outline-success" type="submit"
                              onclick="return confirm('Biztosan elküldöd erre a címre: <?= h($emailTo) ?> ?');">Küldés</button>
                          </form>
                        <?php endif; ?>

                        <?php if ($canResend): ?>
                          <form method="post" action="resend.php" style="display:inline">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <button class="btn btn-sm btn-outline-success" type="submit"
                              onclick="return confirm('Biztosan újra elküldöd erre a címre: <?= h($emailTo) ?> ?');">Újraküldés</button>
                          </form>
                        <?php endif; ?>

                        <?php if ($canSendNoMatch): ?>
                          <form method="post" action="resend.php" style="display:inline" onsubmit="return (function(f){
                            var em = f.to_email.value.trim();
                            if(!em){ alert('Adj meg egy email címet!'); return false; }
                            return confirm('Biztosan elküldöd erre a címre: ' + em + ' ?');
                          })(this);">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <input type="email" name="to_email" class="form-control form-control-sm" placeholder="címzett email" style="max-width:220px" value="<?= h($emailTo) ?>">
                            <button class="btn btn-sm btn-outline-success" type="submit">Küldés</button>
                          </form>
                        <?php endif; ?>
                      </div>

                      <div class="text-muted small text-truncate" style="max-width:240px"><?= h(basename($p['output_path'])) ?></div>

                      <?php if (!empty($p['sent_at'])): ?>
                        <div class="text-muted small">Küldve: <?= h($p['sent_at']) ?></div>
                      <?php endif; ?>

                      <?php if ($emailTo): ?>
                        <div class="text-muted small">Címzett: <?= h($emailTo) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted small text-truncate" style="max-width:240px"><?= h($p['error_message'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="small text-muted mt-2">
          <span class="badge bg-success bg-opacity-25 text-success border border-success">Mentve</span> = elmentve, de még nem biztos, hogy elküldve ·
          <span class="badge bg-success">Küldve</span> = email elküldve (sent_at) ·
          <span class="badge bg-warning text-dark">Nincs egyezés</span> = nincs automatikus dolgozó/email találat (kézzel megadható email) ·
          <span class="badge bg-danger">Hiba</span> = feldolgozási / email hiba ·
          <span class="badge bg-secondary">Folyamatban</span> = még fut
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php page_footer(); ?>

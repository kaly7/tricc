<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

$pdo = Db::pdo();
$isAdmin = Auth::isAdmin();

$q = trim((string)($_GET['q'] ?? ''));
$scope = (string)($_GET['scope'] ?? 'employees'); // employees | pdfs
$mode  = (string)($_GET['mode'] ?? 'exact');     // exact | partial
$msg   = (string)($_GET['msg'] ?? '');

$selectedEmployeeId = (int)($_GET['employee_id'] ?? 0);
$selectedPdfName = trim((string)($_GET['pdf_name'] ?? ''));

$employees = [];
$pdfNames = [];
$items = [];
$err = '';


function badgeForStatus(string $status): array {
    switch ($status) {
        case 'SAVED':   return ['success', 'Mentve', true];
        case 'MAILED':  return ['success', 'Küldve', false];
        case 'NO_MATCH':return ['warning', 'Nincs egyezés', false];
        case 'ERROR':   return ['danger', 'Hiba', false];
        case 'PENDING': return ['secondary', 'Folyamatban', false];
        default:        return ['secondary', $status ?: '—', false];
    }
}

function needMin3(string $q): bool {
    return mb_strlen($q, 'UTF-8') < 3;
}

function is_valid_email(?string $s): bool {
    if (!$s) return false;
    return (bool)filter_var($s, FILTER_VALIDATE_EMAIL);
}

if ($q !== '') {
    if ($mode === 'partial' && needMin3($q)) {
        $err = 'Töredék kereséshez legalább 3 karakter szükséges.';
    } else {
        if ($scope === 'pdfs') {
            // Search in ALL processed PDFs (page_jobs.extracted_name), not only employees table
            if ($mode === 'partial') {
                $like = '%' . $q . '%';
                $st = $pdo->prepare("
                    SELECT pj.extracted_name AS name, COUNT(*) AS cnt
                    FROM page_jobs pj
                    WHERE pj.extracted_name IS NOT NULL
                      AND pj.extracted_name <> ''
                      AND pj.extracted_name LIKE ?
                    GROUP BY pj.extracted_name
                    ORDER BY pj.extracted_name ASC
                    LIMIT 50
                ");
                $st->execute([$like]);
            } else {
                // exact: full name match
                $st = $pdo->prepare("
                    SELECT pj.extracted_name AS name, COUNT(*) AS cnt
                    FROM page_jobs pj
                    WHERE pj.extracted_name = ?
                    GROUP BY pj.extracted_name
                    ORDER BY pj.extracted_name ASC
                    LIMIT 50
                ");
                $st->execute([$q]);
            }
            $pdfNames = $st->fetchAll();

            if ($selectedPdfName === '' && count($pdfNames) === 1) {
                $selectedPdfName = (string)$pdfNames[0]['name'];
            }
        } else {
            // employees scope (existing behavior)
            if ($mode === 'partial') {
                $like = '%' . $q . '%';
                $st = $pdo->prepare("SELECT id,name,email FROM employees WHERE name LIKE ? OR email LIKE ? ORDER BY name ASC LIMIT 25");
                $st->execute([$like, $like]);
            } else {
                $st = $pdo->prepare("SELECT id,name,email FROM employees WHERE name = ? OR email = ? ORDER BY name ASC LIMIT 25");
                $st->execute([$q, $q]);
            }
            $employees = $st->fetchAll();

            if ($selectedEmployeeId <= 0 && count($employees) === 1) {
                $selectedEmployeeId = (int)$employees[0]['id'];
            }
        }
    }
}

/** Load selection details + list items */
$selectedEmp = null;
if ($scope === 'employees' && $selectedEmployeeId > 0) {
    $st = $pdo->prepare("SELECT id,name,email FROM employees WHERE id=? LIMIT 1");
    $st->execute([$selectedEmployeeId]);
    $selectedEmp = $st->fetch();

    if ($selectedEmp) {
        $st = $pdo->prepare("
          SELECT
            pj.*,
            u.month,
            d.name AS division_name
          FROM page_jobs pj
          JOIN uploads u ON u.id = pj.upload_id
          LEFT JOIN divisions d ON d.id = u.division_id
          WHERE (pj.employee_id = ?)
             OR (pj.email_to = ? AND pj.employee_id IS NULL)
          ORDER BY u.month DESC, d.name ASC, pj.page_no ASC
          LIMIT 2000
        ");
        $st->execute([(int)$selectedEmp['id'], (string)$selectedEmp['email']]);
        $items = $st->fetchAll();
    }
}

if ($scope === 'pdfs' && $selectedPdfName !== '') {
    $st = $pdo->prepare("
      SELECT
        pj.*,
        u.month,
        d.name AS division_name
      FROM page_jobs pj
      JOIN uploads u ON u.id = pj.upload_id
      LEFT JOIN divisions d ON d.id = u.division_id
      WHERE pj.extracted_name = ?
      ORDER BY u.month DESC, d.name ASC, pj.page_no ASC
      LIMIT 2000
    ");
    $st->execute([$selectedPdfName]);
    $items = $st->fetchAll();
}


$bulkEmailDefault = '';
if ($scope === 'employees' && $selectedEmp && !empty($selectedEmp['email'])) {
    $bulkEmailDefault = (string)$selectedEmp['email'];
} elseif ($scope === 'pdfs' && $items) {
    foreach ($items as $it) {
        $em = (string)($it['email_to'] ?? '');
        if ($em && filter_var($em, FILTER_VALIDATE_EMAIL)) { $bulkEmailDefault = $em; break; }
    }
}

page_header('Dolgozó PDF-ek');
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card p-3">
      <h1 class="h6 mb-3">Keresés</h1>

      <?php if ($msg === 'bulk_sent' || $msg === 'sent'): ?>
        <div class="alert alert-success py-2">Küldés kész.</div>
      <?php elseif ($msg === 'bulk_fail' || $msg === 'fail'): ?>
        <div class="alert alert-danger py-2">Küldés hiba.</div>
      <?php endif; ?>

      <?php if ($err): ?>
        <div class="alert alert-warning py-2"><?= h($err) ?></div>
      <?php endif; ?>

      <form method="get" class="mb-3">
        <div class="mb-2">
          <input class="form-control" name="q" placeholder="Teljes név vagy email" value="<?= h($q) ?>">
        </div>

        <div class="mb-2">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="scope" id="scopeEmployees" value="employees" <?= $scope==='employees'?'checked':'' ?>>
            <label class="form-check-label" for="scopeEmployees">Dolgozók (adatbázis)</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="scope" id="scopePdfs" value="pdfs" <?= $scope==='pdfs'?'checked':'' ?>>
            <label class="form-check-label" for="scopePdfs">PDF-ekben szereplő nevek (minden feldolgozott)</label>
          </div>
        </div>

        <div class="mb-2">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="mode" id="modeExact" value="exact" <?= $mode==='exact'?'checked':'' ?>>
            <label class="form-check-label" for="modeExact">Teljes név / pontos egyezés</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="mode" id="modePartial" value="partial" <?= $mode==='partial'?'checked':'' ?>>
            <label class="form-check-label" for="modePartial">Töredék (min. 3 karakter)</label>
          </div>
        </div>

        <button class="btn btn-primary" type="submit">Keres</button>
      </form>

      <?php if ($q !== '' && !$err): ?>
        <?php if ($scope === 'employees'): ?>
          <?php if ($employees): ?>
            <div class="small text-muted mb-1">Találatok (max 25):</div>
            <div class="list-group">
              <?php foreach ($employees as $e): $eid=(int)$e['id']; ?>
                <a class="list-group-item list-group-item-action <?= ($eid===$selectedEmployeeId)?'active':'' ?>"
                   href="employee_pdfs.php?q=<?= urlencode($q) ?>&scope=employees&mode=<?= urlencode($mode) ?>&employee_id=<?= $eid ?>">
                  <div class="fw-semibold"><?= h($e['name']) ?></div>
                  <div class="small <?= ($eid===$selectedEmployeeId)?'text-white-50':'text-muted' ?>"><?= h($e['email']) ?></div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted small">Nincs találat.</div>
          <?php endif; ?>
        <?php else: ?>
          <?php if ($pdfNames): ?>
            <div class="small text-muted mb-1">Találatok a PDF-ekből (max 50):</div>
            <div class="list-group">
              <?php foreach ($pdfNames as $n): $nm=(string)$n['name']; ?>
                <a class="list-group-item list-group-item-action <?= ($nm===$selectedPdfName)?'active':'' ?>"
                   href="employee_pdfs.php?q=<?= urlencode($q) ?>&scope=pdfs&mode=<?= urlencode($mode) ?>&pdf_name=<?= urlencode($nm) ?>">
                  <div class="fw-semibold"><?= h($nm) ?></div>
                  <div class="small <?= ($nm===$selectedPdfName)?'text-white-50':'text-muted' ?>">PDF-ek száma: <?= (int)$n['cnt'] ?></div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted small">Nincs találat.</div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>

      <!-- div class="small text-muted mt-3">
        <b>Megjegyzés:</b> A “PDF-ekben szereplő nevek” keresés az eddig feldolgozott <code>page_jobs.extracted_name</code> mező alapján működik,
        így akkor is megtalálod, ha a dolgozó nincs felvéve a dolgozó adatbázisba.
      </div -->
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card p-3">
      <h2 class="h6 mb-3">PDF-ek</h2>

      <?php if ($scope === 'employees' && !$selectedEmp): ?>
        <div class="text-muted">Keress bal oldalt egy dolgozóra.</div>
      <?php elseif ($scope === 'pdfs' && $selectedPdfName === ''): ?>
        <div class="text-muted">Keress bal oldalt egy névre a PDF-ekből.</div>
      <?php else: ?>
        <?php if ($scope === 'employees'): ?>
          <div class="d-flex gap-2 align-items-center mb-2">
            <span class="badge bg-secondary"><?= h($selectedEmp['name']) ?></span>
            <span class="badge bg-light text-dark border"><?= h($selectedEmp['email']) ?></span>
          </div>
        <?php else: ?>
          <div class="d-flex gap-2 align-items-center mb-2">
            <span class="badge bg-secondary"><?= h($selectedPdfName) ?></span>
            <span class="text-muted small">Forrás: PDF kivonatolt név</span>
          </div>
        <?php endif; ?>

        <?php if (!$items): ?>
          <div class="text-muted">Nincs találat a kiválasztott névre.</div>
        <?php else: ?>
          
          <?php if ($isAdmin && $items): ?>
            <form method="post" action="send_bulk.php" class="mb-2">
              <input type="hidden" name="scope" value="<?= h($scope) ?>">
              <input type="hidden" name="return_q" value="<?= h($q) ?>">
              <?php if ($scope === 'employees'): ?>
                <input type="hidden" name="employee_id" value="<?= (int)($selectedEmp['id'] ?? 0) ?>">
              <?php else: ?>
                <input type="hidden" name="employee_id" value="0">
                <input type="hidden" name="pdf_name" value="<?= h($selectedPdfName) ?>">
              <?php endif; ?>

              <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div class="d-flex gap-2 align-items-center">
                  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(true)">Mind kijelöl</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(false)">Kijelölés törlése</button>
                </div>

                <div class="d-flex flex-wrap gap-2 align-items-center">
                  <input id="bulk_to_email" type="email" name="to_email" class="form-control form-control-sm" style="width:260px"
                         placeholder="cél email" value="<?= h($bulkEmailDefault) ?>">
                  <button type="submit" class="btn btn-sm btn-success" onclick="return confirmBulk();">Kijelöltek küldése</button>
                </div>
              </div>
<div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <?php if ($isAdmin && $items): ?><th style="width:28px"></th><?php endif; ?>
                  <th>Hónap</th>
                  <th>Divízió</th>
                  <th>Oldal</th>
                  <th>Státusz</th>
                  <th>Fájl</th>
                  <th class="text-end">Művelet</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $p):
                  $jobId = (int)$p['id'];
                  $hasFile = !empty($p['output_path']) && is_file($p['output_path']);
                  $status = (string)($p['status'] ?? '');
                  $emailTo = (string)($p['email_to'] ?? '');
                  $validEmail = is_valid_email($emailTo);
                  $targetEmail = ($scope === 'employees') ? (string)$selectedEmp['email'] : $emailTo;
                ?>
                  <tr>
                    <?php if ($isAdmin && $items): ?>
                      <td><input class="form-check-input job-check" type="checkbox" name="job_ids[]" value="<?= (int)$p['id'] ?>"></td>
                    <?php endif; ?>
                    <td><?= h($p['month'] ?? '') ?></td>
                    <td><?= h($p['division_name'] ?? '—') ?></td>
                    <td><?= (int)$p['page_no'] ?></td>
                    <td>
                      <?php [$bg,$label,$isSaved]=badgeForStatus($status); ?>
                      <span class="badge bg-<?= h($bg) ?> <?= $isSaved?'bg-opacity-25 text-success border border-success':'' ?>"><?= h($label) ?></span>
                      <?php if (!empty($p['sent_at'])): ?>
                        <div class="text-muted small">Küldve: <?= h($p['sent_at']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-muted small text-truncate" style="max-width:220px">
                      <?= h(basename((string)($p['output_path'] ?? ''))) ?>
                    </td>
                    <td class="text-end text-nowrap">
                      <?php if ($hasFile): ?>
                        <a class="btn btn-sm btn-outline-primary" href="download.php?job_id=<?= $jobId ?>">Letöltés</a>

                        <?php if ($isAdmin): ?>
                          <?php if ($scope === 'employees'): ?>
                            <form method="post" action="resend.php" style="display:inline">
                              <input type="hidden" name="job_id" value="<?= $jobId ?>">
                              <button class="btn btn-sm btn-outline-success" type="submit"
                                onclick="return confirm('Biztosan elküldöd erre a címre: <?= h((string)$selectedEmp['email']) ?> ?');">
                                Küldés
                              </button>
                            </form>
                          <?php else: ?>
                            <?php if ($validEmail): ?>
                              <form method="post" action="resend.php" style="display:inline">
                                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                                <button class="btn btn-sm btn-outline-success" type="submit"
                                  onclick="return confirm('Biztosan elküldöd erre a címre: <?= h($emailTo) ?> ?');">
                                  Küldés
                                </button>
                              </form>
                            <?php else: ?>
                              <form method="post" action="resend.php" style="display:inline" onsubmit="return (function(f){
                                var em = f.to_email.value.trim();
                                if(!em){ alert('Adj meg egy email címet!'); return false; }
                                return confirm('Biztosan elküldöd erre a címre: ' + em + ' ?');
                              })(this);">
                                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                                <input type="email" name="to_email" class="form-control form-control-sm d-inline-block" placeholder="címzett email" style="width:210px" value="">
                                <button class="btn btn-sm btn-outline-success" type="submit">Küldés</button>
                              </form>
                            <?php endif; ?>
                          <?php endif; ?>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

            </form>
            <?php endif; ?>

          <?php if ($scope === 'pdfs'): ?>
            <div class="small text-muted mt-2">
              Tipp: ha több sorban nincs email, először a <b>Dolgozók</b> menüben vedd fel/javítsd a dolgozót, és a következő feldolgozás már automatikusan egyezni fog.
            </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function toggleAll(on){
  document.querySelectorAll('.job-check').forEach(cb => cb.checked = !!on);
}
function confirmBulk(){
  const cbs = Array.from(document.querySelectorAll('.job-check')).filter(cb => cb.checked);
  if (cbs.length === 0) { alert('Nincs kijelölt elem.'); return false; }
  const emEl = document.getElementById('bulk_to_email');
  const em = emEl ? emEl.value.trim() : '';
  if (!em) { alert('Adj meg cél email címet!'); return false; }
  return confirm('Biztosan elküldöd a kijelölt ' + cbs.length + ' db PDF-et erre: ' + em + ' ?');
}
</script>
<?php page_footer(); ?>

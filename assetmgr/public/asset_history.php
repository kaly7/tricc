<?php
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/auth.php';
require_login();

$u = current_user();
$isAdmin = (($u['role'] ?? '') === 'admin');
if (!$isAdmin) {
  http_response_code(403);
  $title='Nincs jogosultság'; $page=$title;
  require __DIR__.'/_header.php';
  echo "<div class='alert alert-danger'>Nincs jogosultság.</div>";
  require __DIR__.'/_footer.php';
  exit;
}

$pdo = db();

// asset_id param: elfogadjuk az asset_id-t és az id-t is
$assetId = (int)($_GET['asset_id'] ?? 0);
if ($assetId <= 0) $assetId = (int)($_GET['id'] ?? 0);

if ($assetId <= 0) {
  $title='Eszköz történet'; $page=$title;
  require __DIR__.'/_header.php';
  echo "<div class='alert alert-warning'>Hiányzó vagy hibás asset_id. Példa: <code>asset_history.php?asset_id=123</code></div>";
  require __DIR__.'/_footer.php';
  exit;
}

// Asset alapadat
$stA = $pdo->prepare("SELECT id, name, sku FROM assets WHERE id=? AND is_deleted=0 LIMIT 1");
$stA->execute([$assetId]);
$asset = $stA->fetch(PDO::FETCH_ASSOC);
if (!$asset) {
  $title='Eszköz történet'; $page=$title;
  require __DIR__.'/_header.php';
  echo "<div class='alert alert-warning'>Nem található eszköz ezzel az ID-val: ".(int)$assetId."</div>";
  require __DIR__.'/_footer.php';
  exit;
}

// Helperek
function holder_name(int $id, array $map): string {
  if ($id <= 0) return '';
  return $map[$id] ?? ('#'.$id);
}
function csv_cell($v): string { return (string)($v ?? ''); }
function hlink(?string $path, string $label): string {
  $p = trim((string)$path);
  if ($p === '') return '';
  return '<a href="'.e($p).'" target="_blank" rel="noopener">'.$label.'</a>';
}
function render_pagination(string $paramName, int $currentPage, int $totalPages, array $baseQuery): void {
  if ($totalPages <= 1) return;
  echo '<nav class="mt-3"><ul class="pagination pagination-sm mb-0">';
  for ($p = 1; $p <= $totalPages; $p++) {
    $query = $baseQuery;
    $query[$paramName] = $p;
    $active = ($p === $currentPage) ? ' active' : '';
    echo '<li class="page-item'.$active.'"><a class="page-link" href="?'.e(http_build_query($query)).'">'.$p.'</a></li>';
  }
  echo '</ul></nav>';
}

// Query params per szekció
$perPage = 50;
$internalPage = max(1, (int)($_GET['internal_page'] ?? 1));
$externalPage = max(1, (int)($_GET['external_page'] ?? 1));
$warehouseIssuePage = max(1, (int)($_GET['warehouse_issue_page'] ?? 1));
$warehouseIntakePage = max(1, (int)($_GET['warehouse_intake_page'] ?? 1));

// HR névtérkép
$empMap = [];
$warehouseMap = [];
try {
  foreach ($pdo->query("SELECT id, name FROM warehouses")->fetchAll(PDO::FETCH_ASSOC) as $w) { $warehouseMap[(int)$w['id']] = (string)$w['name']; }
} catch (Throwable $e) { $warehouseMap = []; }
try {
  $hr = db_hr();
  foreach ($hr->query("SELECT id, full_name FROM employees")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $empMap[(int)$e['id']] = (string)$e['full_name'];
  }
} catch (Throwable $e) {
  $empMap = [];
}

// Raktár névtérkép
$warehouseMap = [];
try {
  foreach ($pdo->query("SELECT id, name FROM warehouses")->fetchAll(PDO::FETCH_ASSOC) as $w) {
    $warehouseMap[(int)$w['id']] = (string)$w['name'];
  }
} catch (Throwable $e) {
  $warehouseMap = [];
}

// Auth user névtérkép
$authUserMap = [];
try {
  if (function_exists('authcfg') && function_exists('auth_pdo')) {
    $apdo = auth_pdo();
    foreach ($apdo->query("SELECT id, full_name, email, username FROM users")->fetchAll(PDO::FETCH_ASSOC) as $ur) {
      $nm = trim((string)($ur['full_name'] ?? ''));
      if ($nm === '') $nm = trim((string)($ur['username'] ?? ''));
      if ($nm === '') $nm = trim((string)($ur['email'] ?? ''));
      $authUserMap[(int)$ur['id']] = $nm !== '' ? $nm : ('#'.(int)$ur['id']);
    }
  }
} catch (Throwable $e) {
  $authUserMap = [];
}

// asset_assignments oszlopok
$aaCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
  $aaCols[(string)$c['Field']] = true;
}
$hasStatus    = isset($aaCols['status']);
$hasExpires   = isset($aaCols['expires_at']);
$hasResponded = isset($aaCols['responded_at']);
$hasRespNote  = isset($aaCols['response_note']);
$hasPdf       = isset($aaCols['pdf_path']);
$hasReturnPdf = isset($aaCols['return_pdf_path']);

$tsCol = null;
foreach (['created_at','assigned_at','created_on','assigned_on','created','ts','timestamp'] as $cand) {
  if (isset($aaCols[$cand])) { $tsCol = $cand; break; }
}

// ---------- BELSŐ ESEMÉNYEK ----------
$selectExtra = "";
if ($hasStatus)    $selectExtra .= ", aa.status";
if ($hasExpires)   $selectExtra .= ", aa.expires_at";
if ($hasResponded) $selectExtra .= ", aa.responded_at";
if ($hasRespNote)  $selectExtra .= ", aa.response_note";
if ($hasPdf)       $selectExtra .= ", aa.pdf_path";
if ($hasReturnPdf) $selectExtra .= ", aa.return_pdf_path";
$selectTs = $tsCol ? (", aa.`".$tsCol."` AS event_time") : ", NULL AS event_time";

$stCnt = $pdo->prepare("SELECT COUNT(*) FROM asset_assignments aa WHERE aa.asset_id=?");
$stCnt->execute([$assetId]);
$internalTotal = (int)($stCnt->fetchColumn() ?: 0);
$internalPages = max(1, (int)ceil($internalTotal / $perPage));
$internalOffset = ($internalPage - 1) * $perPage;

$sql = "
  SELECT aa.id, aa.asset_id, aa.from_employee_id, aa.to_employee_id, aa.note
  $selectTs
  $selectExtra
  FROM asset_assignments aa
  WHERE aa.asset_id=?
  ORDER BY aa.id DESC
  LIMIT ".(int)$perPage." OFFSET ".(int)$internalOffset;
$st = $pdo->prepare($sql);
$st->execute([$assetId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ---------- KÜLSŐS ESEMÉNYEK ----------
$extRows = [];
$hasExternalEvents = false;
$externalTotal = 0;
$externalPages = 1;
try {
  $pdo->query("SELECT 1 FROM asset_external_assignments LIMIT 1");
  $hasExternalEvents = true;

  $stExtCnt = $pdo->prepare("SELECT COUNT(*) FROM asset_external_assignments WHERE asset_id=?");
  $stExtCnt->execute([$assetId]);
  $externalTotal = (int)($stExtCnt->fetchColumn() ?: 0);
  $externalPages = max(1, (int)ceil($externalTotal / $perPage));
  $externalOffset = ($externalPage - 1) * $perPage;

  $stExt = $pdo->prepare("SELECT aea.*,
                                 COALESCE(aea.ext_company, eh.company_name) AS company_name,
                                 COALESCE(aea.ext_contact, eh.contact_name) AS contact_name,
                                 COALESCE(aea.ext_phone, eh.phone) AS phone
                          FROM asset_external_assignments aea
                          JOIN external_holders eh ON eh.id=aea.external_holder_id
                          WHERE aea.asset_id=?
                          ORDER BY aea.id DESC
                          LIMIT ".(int)$perPage." OFFSET ".(int)$externalOffset);
  $stExt->execute([$assetId]);
  $extRows = $stExt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $hasExternalEvents = false;
  $extRows = [];
}

// ---------- RAKTÁRI KIADÁSOK ----------
$warehouseIssueRows = [];
$hasWarehouseIssue = false;
$warehouseIssueTotal = 0;
$warehouseIssuePages = 1;
try {
  $pdo->query("SELECT 1 FROM warehouse_issue_documents LIMIT 1");
  $hasWarehouseIssue = true;

  $stWCnt = $pdo->prepare("SELECT COUNT(*) FROM warehouse_issue_documents WHERE asset_id=?");
  $stWCnt->execute([$assetId]);
  $warehouseIssueTotal = (int)($stWCnt->fetchColumn() ?: 0);
  $warehouseIssuePages = max(1, (int)ceil($warehouseIssueTotal / $perPage));
  $warehouseIssueOffset = ($warehouseIssuePage - 1) * $perPage;

  $stW = $pdo->prepare("SELECT wid.*
                        FROM warehouse_issue_documents wid
                        WHERE wid.asset_id=?
                        ORDER BY wid.created_at DESC, wid.id DESC
                        LIMIT ".(int)$perPage." OFFSET ".(int)$warehouseIssueOffset);
  $stW->execute([$assetId]);
  $warehouseIssueRows = $stW->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $hasWarehouseIssue = false;
  $warehouseIssueRows = [];
}

// ---------- RAKTÁRI BEVÉTELEK ----------
$warehouseIntakeRows = [];
$hasWarehouseIntake = false;
$warehouseIntakeTotal = 0;
$warehouseIntakePages = 1;
try {
  $pdo->query("SELECT 1 FROM warehouse_intake_documents LIMIT 1");
  $hasWarehouseIntake = true;

  $stWICnt = $pdo->prepare("SELECT COUNT(*) FROM warehouse_intake_documents WHERE asset_id=?");
  $stWICnt->execute([$assetId]);
  $warehouseIntakeTotal = (int)($stWICnt->fetchColumn() ?: 0);
  $warehouseIntakePages = max(1, (int)ceil($warehouseIntakeTotal / $perPage));
  $warehouseIntakeOffset = ($warehouseIntakePage - 1) * $perPage;

  $stWI = $pdo->prepare("SELECT wid.*
                         FROM warehouse_intake_documents wid
                         WHERE wid.asset_id=?
                         ORDER BY wid.created_at DESC, wid.id DESC
                         LIMIT ".(int)$perPage." OFFSET ".(int)$warehouseIntakeOffset);
  $stWI->execute([$assetId]);
  $warehouseIntakeRows = $stWI->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $hasWarehouseIntake = false;
  $warehouseIntakeRows = [];
}

// CSV export
if ((string)($_GET['export'] ?? '') === 'csv') {
  $fn = 'asset_history_'.$assetId.'_'.date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$fn.'"');
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');
  fputcsv($out, ['Szekció','Esemény ID','Eszköz ID','Átadó/Forrás','Átvevő/Cél','Megjegyzés','Idő','Státusz','PDF'], ';');

  foreach ($rows as $r) {
    fputcsv($out, [
      'belső',
      (int)$r['id'],
      (int)$r['asset_id'],
      holder_name((int)($r['from_employee_id'] ?? 0), $empMap),
      holder_name((int)($r['to_employee_id'] ?? 0), $empMap),
      csv_cell($r['note'] ?? ''),
      csv_cell($r['event_time'] ?? ''),
      csv_cell($r['status'] ?? ''),
      csv_cell($r['pdf_path'] ?? ''),
    ], ';');
  }
  foreach ($extRows as $r) {
    $partner = trim(((string)($r['company_name'] ?? '')).' / '.((string)($r['contact_name'] ?? '')), ' /');
    fputcsv($out, [
      'külsős',
      (int)$r['id'],
      (int)$r['asset_id'],
      $authUserMap[(int)($r['assigned_by_user_id'] ?? 0)] ?? '',
      $partner,
      csv_cell($r['note'] ?? ''),
      csv_cell($r['assigned_at'] ?? ''),
      csv_cell($r['status'] ?? ''),
      csv_cell($r['pdf_path'] ?? ''),
    ], ';');
  }
  foreach ($warehouseIssueRows as $r) {
    fputcsv($out, [
      'raktári kiadás',
      (int)$r['id'],
      (int)$r['asset_id'],
      $warehouseMap[(int)($r['warehouse_id'] ?? 0)] ?? ('#'.(int)($r['warehouse_id'] ?? 0)),
      holder_name((int)($r['to_employee_id'] ?? 0), $empMap),
      csv_cell($r['note'] ?? ''),
      csv_cell($r['created_at'] ?? $r['doc_date'] ?? ''),
      '',
      csv_cell($r['pdf_path'] ?? ''),
    ], ';');
  }
  foreach ($warehouseIntakeRows as $r) {
    fputcsv($out, [
      'raktári bevétel',
      (int)$r['id'],
      (int)$r['asset_id'],
      csv_cell($r['source_label'] ?? ''),
      $warehouseMap[(int)($r['warehouse_id'] ?? 0)] ?? ('#'.(int)($r['warehouse_id'] ?? 0)),
      csv_cell($r['note'] ?? ''),
      csv_cell($r['created_at'] ?? $r['doc_date'] ?? ''),
      '',
      csv_cell($r['pdf_path'] ?? ''),
    ], ';');
  }
  fclose($out);
  exit;
}

$title = 'Eszköz történet';
$page  = 'Eszköz történet';
require __DIR__.'/_header.php';

$baseQuery = ['asset_id' => $assetId];
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4 class="mb-0">Eszköz történet</h4>
    <div class="text-secondary small">
      #<?= (int)$asset['id'] ?> • <?= e((string)$asset['name']) ?><?= !empty($asset['sku']) ? ' • '.e((string)$asset['sku']) : '' ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="asset_edit.php?id=<?= (int)$assetId ?>">Vissza az eszközhöz</a>
    <a class="btn btn-outline-primary btn-sm" href="asset_history.php?asset_id=<?= (int)$assetId ?>&export=csv">CSV export</a>
  </div>
</div>

<div class="accordion" id="historyAccordion">

  <div class="accordion-item">
    <h2 class="accordion-header" id="headingInternal">
      <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseInternal" aria-expanded="true" aria-controls="collapseInternal">
        Egymás közti átadások <span class="badge bg-secondary ms-2"><?= (int)$internalTotal ?></span>
      </button>
    </h2>
    <div id="collapseInternal" class="accordion-collapse collapse show" aria-labelledby="headingInternal" data-bs-parent="#historyAccordion">
      <div class="accordion-body">
        <?php if (!$rows): ?>
          <div class="alert alert-light border mb-0">Nincs belső esemény ehhez az eszközhöz.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
              <thead>
                <tr>
                  <th style="width:80px">ID</th>
                  <th>Átadó</th>
                  <th>Átvevő</th>
                  <?php if ($hasStatus): ?><th style="width:120px">Státusz</th><?php endif; ?>
                  <th style="width:180px">Idő</th>
                  <th style="width:140px"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $rid = (int)$r['id'];
                    $pdfUrl = (string)($r['pdf_path'] ?? '');
                    $rpdfUrl = (string)($r['return_pdf_path'] ?? '');
                    $hasAnyDetail = (!empty($r['note']) || ($hasRespNote && !empty($r['response_note'])) || $pdfUrl !== '' || $rpdfUrl !== '' || ($hasExpires && !empty($r['expires_at'])) || ($hasResponded && !empty($r['responded_at'])));
                  ?>
                  <tr>
                    <td><?= $rid ?></td>
                    <td><?= e(holder_name((int)($r['from_employee_id'] ?? 0), $empMap)) ?></td>
                    <td><?= e(holder_name((int)($r['to_employee_id'] ?? 0), $empMap)) ?></td>
                    <?php if ($hasStatus): ?><td><?= e((string)($r['status'] ?? '')) ?></td><?php endif; ?>
                    <td><?= e((string)($r['event_time'] ?? '')) ?></td>
                    <td class="text-end">
                      <?php if ($hasAnyDetail): ?>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#detailInternal<?= $rid ?>" aria-expanded="false" aria-controls="detailInternal<?= $rid ?>">Részletek</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php if ($hasAnyDetail): ?>
                    <tr class="collapse" id="detailInternal<?= $rid ?>">
                      <td colspan="<?= $hasStatus ? 6 : 5 ?>">
                        <div class="p-2 small">
                          <?php if (!empty($r['note'])): ?><div><strong>Átadás megjegyzés:</strong> <?= e((string)$r['note']) ?></div><?php endif; ?>
                          <?php if ($hasRespNote && !empty($r['response_note'])): ?><div><strong>Válasz megjegyzés:</strong> <?= e((string)$r['response_note']) ?></div><?php endif; ?>
                          <?php if ($hasExpires && !empty($r['expires_at'])): ?><div><strong>Határidő:</strong> <?= e((string)$r['expires_at']) ?></div><?php endif; ?>
                          <?php if ($hasResponded && !empty($r['responded_at'])): ?><div><strong>Válasz ideje:</strong> <?= e((string)$r['responded_at']) ?></div><?php endif; ?>
                          <?php if ($pdfUrl !== ''): ?><div><strong>PDF:</strong> <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener">átadás-átvételi jegyzőkönyv</a></div><?php endif; ?>
                          <?php if ($rpdfUrl !== ''): ?><div><strong>Visszavétel PDF:</strong> <a href="<?= e($rpdfUrl) ?>" target="_blank" rel="noopener">visszavételi jegyzőkönyv</a></div><?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php render_pagination('internal_page', $internalPage, $internalPages, $baseQuery); ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="accordion-item mt-3">
    <h2 class="accordion-header" id="headingExternal">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseExternal" aria-expanded="false" aria-controls="collapseExternal">
        Külsős átadások <span class="badge bg-secondary ms-2"><?= (int)$externalTotal ?></span>
      </button>
    </h2>
    <div id="collapseExternal" class="accordion-collapse collapse" aria-labelledby="headingExternal" data-bs-parent="#historyAccordion">
      <div class="accordion-body">
        <?php if (!$hasExternalEvents || !$extRows): ?>
          <div class="alert alert-light border mb-0">Nincs külsős esemény ehhez az eszközhöz.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
              <thead>
                <tr>
                  <th style="width:80px">ID</th>
                  <th>Külsős partner</th>
                  <th style="width:140px">Státusz</th>
                  <th style="width:180px">Kiadva</th>
                  <th style="width:180px">Visszavéve</th>
                  <th style="width:140px"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($extRows as $x): ?>
                  <?php
                    $id = (int)$x['id'];
                    $partner = trim(((string)($x['company_name'] ?? '')).' / '.((string)($x['contact_name'] ?? '')), ' /');
                    if (!empty($x['phone'])) $partner .= ' ('.(string)$x['phone'].')';
                    $sigUrl = (string)($x['signature_path'] ?? '');
                    $pdfUrl = (string)($x['pdf_path'] ?? '');
                    $rpdfUrl = (string)($x['return_pdf_path'] ?? '');
                    $assignedBy = '';
                    $auid = (int)($x['assigned_by_user_id'] ?? 0);
                    if ($auid > 0) $assignedBy = $authUserMap[$auid] ?? ('#'.$auid);
                    $hasDetail = ($assignedBy !== '' || $sigUrl !== '' || $pdfUrl !== '' || $rpdfUrl !== '' || !empty($x['courier_ref']) || !empty($x['note']) || !empty($x['returned_to_employee_id']) || !empty($x['return_note']) || !empty($x['ext_email']));
                  ?>
                  <tr>
                    <td><?= $id ?></td>
                    <td><?= e($partner) ?></td>
                    <td><?= e((string)($x['status'] ?? '')) ?></td>
                    <td><?= e((string)($x['assigned_at'] ?? '')) ?></td>
                    <td><?= e((string)($x['returned_at'] ?? '')) ?></td>
                    <td class="text-end">
                      <?php if ($hasDetail): ?>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#detailExternal<?= $id ?>" aria-expanded="false" aria-controls="detailExternal<?= $id ?>">Részletek</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php if ($hasDetail): ?>
                    <tr class="collapse" id="detailExternal<?= $id ?>">
                      <td colspan="6">
                        <div class="p-2 small">
                          <?php if ($assignedBy !== ''): ?><div><strong>Átadta (külsősnek):</strong> <?= e($assignedBy) ?></div><?php endif; ?>
                          <?php if (!empty($x['ext_email'])): ?><div><strong>Email:</strong> <?= e((string)$x['ext_email']) ?></div><?php endif; ?>
                          <?php if ($sigUrl !== ''): ?>
                            <div class="mb-2">
                              <strong>Aláírás:</strong><br>
                              <a href="<?= e($sigUrl) ?>" target="_blank" rel="noopener">
                                <img src="<?= e($sigUrl) ?>" alt="aláírás" style="max-width:260px;max-height:120px;border:1px solid #ddd;border-radius:8px">
                              </a>
                            </div>
                          <?php endif; ?>
                          <?php if ($pdfUrl !== ''): ?><div><strong>PDF:</strong> <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener">átadás-átvételi jegyzőkönyv</a></div><?php endif; ?>
                          <?php if ($rpdfUrl !== ''): ?><div><strong>Visszavétel PDF:</strong> <a href="<?= e($rpdfUrl) ?>" target="_blank" rel="noopener">visszavételi jegyzőkönyv</a></div><?php endif; ?>
                          <?php if (!empty($x['courier_ref'])): ?><div><strong>Szállító:</strong> <?= e((string)$x['courier_ref']) ?></div><?php endif; ?>
                          <?php if (!empty($x['source_warehouse_id'])): ?><div><strong>Forrás raktár:</strong> <?= e($warehouseMap[(int)$x['source_warehouse_id']] ?? ('#'.(int)$x['source_warehouse_id'])) ?></div><?php endif; ?>
                  <?php if (!empty($x['note'])): ?><div><strong>Megjegyzés:</strong> <?= e((string)$x['note']) ?></div><?php endif; ?>
                          <?php if (!empty($x['returned_to_employee_id'])): ?><div><strong>Kihez került:</strong> <?= e(holder_name((int)$x['returned_to_employee_id'], $empMap)) ?></div><?php endif; ?>
                  <?php if (!empty($x['returned_to_warehouse_id'])): ?><div><strong>Melyik raktárba került:</strong> <?= e($warehouseMap[(int)$x['returned_to_warehouse_id']] ?? ('#'.(int)$x['returned_to_warehouse_id'])) ?></div><?php endif; ?>
                          <?php if (!empty($x['return_note'])): ?><div><strong>Visszavétel megjegyzés:</strong> <?= e((string)$x['return_note']) ?></div><?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php render_pagination('external_page', $externalPage, $externalPages, $baseQuery); ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="accordion-item mt-3">
    <h2 class="accordion-header" id="headingWhIssue">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseWhIssue" aria-expanded="false" aria-controls="collapseWhIssue">
        Raktári kiadások <span class="badge bg-secondary ms-2"><?= (int)$warehouseIssueTotal ?></span>
      </button>
    </h2>
    <div id="collapseWhIssue" class="accordion-collapse collapse" aria-labelledby="headingWhIssue" data-bs-parent="#historyAccordion">
      <div class="accordion-body">
        <?php if (!$hasWarehouseIssue || !$warehouseIssueRows): ?>
          <div class="alert alert-light border mb-0">Nincs raktári kiadási esemény ehhez az eszközhöz.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
              <thead>
                <tr>
                  <th style="width:80px">ID</th>
                  <th style="width:180px">Időpont</th>
                  <th>Raktár</th>
                  <th>Dolgozó</th>
                  <th style="width:140px"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($warehouseIssueRows as $r): ?>
                  <?php $id=(int)$r['id']; $hasDetail = !empty($r['pdf_path']) || !empty($r['recipient_email']) || !empty($r['note']); ?>
                  <tr>
                    <td><?= $id ?></td>
                    <td><?= e((string)($r['created_at'] ?? $r['doc_date'] ?? '')) ?></td>
                    <td><?= e($warehouseMap[(int)($r['warehouse_id'] ?? 0)] ?? ('#'.(int)($r['warehouse_id'] ?? 0))) ?></td>
                    <td><?= e(holder_name((int)($r['to_employee_id'] ?? 0), $empMap)) ?></td>
                    <td class="text-end">
                      <?php if ($hasDetail): ?>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#detailWhIssue<?= $id ?>" aria-expanded="false" aria-controls="detailWhIssue<?= $id ?>">Részletek</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php if ($hasDetail): ?>
                    <tr class="collapse" id="detailWhIssue<?= $id ?>">
                      <td colspan="5">
                        <div class="p-2 small">
                          <?php if (!empty($r['pdf_path'])): ?><div><strong>PDF:</strong> <a href="<?= e((string)$r['pdf_path']) ?>" target="_blank" rel="noopener">raktári kiadási jegyzőkönyv</a></div><?php endif; ?>
                          <?php if (!empty($r['recipient_email'])): ?><div><strong>Email:</strong> <?= e((string)$r['recipient_email']) ?></div><?php endif; ?>
                          <?php if (!empty($r['note'])): ?><div><strong>Megjegyzés:</strong> <?= e((string)$r['note']) ?></div><?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php render_pagination('warehouse_issue_page', $warehouseIssuePage, $warehouseIssuePages, $baseQuery); ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="accordion-item mt-3">
    <h2 class="accordion-header" id="headingWhIntake">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseWhIntake" aria-expanded="false" aria-controls="collapseWhIntake">
        Raktári bevételek <span class="badge bg-secondary ms-2"><?= (int)$warehouseIntakeTotal ?></span>
      </button>
    </h2>
    <div id="collapseWhIntake" class="accordion-collapse collapse" aria-labelledby="headingWhIntake" data-bs-parent="#historyAccordion">
      <div class="accordion-body">
        <?php if (!$hasWarehouseIntake || !$warehouseIntakeRows): ?>
          <div class="alert alert-light border mb-0">Nincs raktári bevételi esemény ehhez az eszközhöz.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
              <thead>
                <tr>
                  <th style="width:80px">ID</th>
                  <th style="width:180px">Időpont</th>
                  <th>Forrás</th>
                  <th>Raktár</th>
                  <th style="width:140px"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($warehouseIntakeRows as $r): ?>
                  <?php $id=(int)$r['id']; $hasDetail = !empty($r['pdf_path']) || !empty($r['recipient_email']) || !empty($r['note']); ?>
                  <tr>
                    <td><?= $id ?></td>
                    <td><?= e((string)($r['created_at'] ?? $r['doc_date'] ?? '')) ?></td>
                    <td><?= e((string)($r['source_label'] ?? '')) ?></td>
                    <td><?= e($warehouseMap[(int)($r['warehouse_id'] ?? 0)] ?? ('#'.(int)($r['warehouse_id'] ?? 0))) ?></td>
                    <td class="text-end">
                      <?php if ($hasDetail): ?>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#detailWhIntake<?= $id ?>" aria-expanded="false" aria-controls="detailWhIntake<?= $id ?>">Részletek</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php if ($hasDetail): ?>
                    <tr class="collapse" id="detailWhIntake<?= $id ?>">
                      <td colspan="5">
                        <div class="p-2 small">
                          <?php if (!empty($r['pdf_path'])): ?><div><strong>PDF:</strong> <a href="<?= e((string)$r['pdf_path']) ?>" target="_blank" rel="noopener">raktárba vételi jegyzőkönyv</a></div><?php endif; ?>
                          <?php if (!empty($r['recipient_email'])): ?><div><strong>Email:</strong> <?= e((string)$r['recipient_email']) ?></div><?php endif; ?>
                          <?php if (!empty($r['note'])): ?><div><strong>Megjegyzés:</strong> <?= e((string)$r['note']) ?></div><?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php render_pagination('warehouse_intake_page', $warehouseIntakePage, $warehouseIntakePages, $baseQuery); ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require __DIR__.'/_footer.php'; ?>

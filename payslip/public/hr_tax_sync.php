<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

if (!Auth::isAdmin()) { http_response_code(403); echo "Forbidden"; exit; }

use Services\EmployeeService;

$pdo = Db::pdo();
$msg = '';
$applied = 0;

// POST: kiválasztott egyezések alkalmazása
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pairs = $_POST['pair'] ?? []; // ['payslip_id' => 'hr_id', ...]
    if (is_array($pairs)) {
        foreach ($pairs as $payslipId => $hrId) {
            $payslipId = (int)$payslipId;
            $hrId = (int)$hrId;
            if ($payslipId <= 0 || $hrId <= 0) continue;

            // HR rekord lekérése
            $st = $pdo->prepare("SELECT id, full_name, tax_id FROM hr.employees WHERE id = ? AND is_active = 1 LIMIT 1");
            $st->execute([$hrId]);
            $hrEmp = $st->fetch();
            if (!$hrEmp || empty($hrEmp['tax_id'])) continue;

            $taxId = preg_replace('/\D+/', '', (string)$hrEmp['tax_id']);
            if (!preg_match('/^\d{10}$/', $taxId)) continue;

            $pdo->prepare("UPDATE employees SET tax_id = ?, hr_id = ? WHERE id = ? AND (tax_id IS NULL OR tax_id = '')")
                ->execute([$taxId, $hrId, $payslipId]);
            $applied++;
        }
    }
    $msg = $applied > 0 ? "success:$applied" : 'none';
    header("Location: hr_tax_sync.php?msg=" . urlencode($msg));
    exit;
}

// GET: névegyeztetés PHP-ban
// 1) Payslip rekordok adójel nélkül
$payslipEmps = $pdo->query("
    SELECT id, name, name_norm, email
    FROM employees
    WHERE tax_id IS NULL OR tax_id = ''
    ORDER BY name
")->fetchAll();

// 2) Aktív HR dolgozók adójellel
$hrEmps = $pdo->query("
    SELECT id, full_name, tax_id, email
    FROM hr.employees
    WHERE is_active = 1 AND tax_id IS NOT NULL AND tax_id != ''
")->fetchAll();

// HR lookup: normalizált név → rekord
$hrByNorm = [];
foreach ($hrEmps as $h) {
    $norm = EmployeeService::normalizeName((string)$h['full_name']);
    if ($norm !== '') $hrByNorm[$norm] = $h;
}

// Egyezések keresése
$matches   = []; // biztosan egyező párok
$noMatch   = []; // nem találtunk egyezést

foreach ($payslipEmps as $p) {
    $norm = (string)($p['name_norm'] ?? '');
    if ($norm !== '' && isset($hrByNorm[$norm])) {
        $matches[] = ['p' => $p, 'h' => $hrByNorm[$norm]];
    } else {
        $noMatch[] = $p;
    }
}

$msgParam = (string)($_GET['msg'] ?? '');

page_header('HR – Adójel szinkron');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">HR – Adójel szinkronizáció (név alapján)</h1>
  <a class="btn btn-sm btn-outline-secondary" href="index.php">Vissza</a>
</div>

<?php if ($msgParam !== ''): ?>
  <?php if (str_starts_with($msgParam, 'success:')): ?>
    <div class="alert alert-success"><?= (int)substr($msgParam, 8) ?> rekord frissítve.</div>
  <?php else: ?>
    <div class="alert alert-warning">Nem volt kijelölt egyezés.</div>
  <?php endif; ?>
<?php endif; ?>

<p class="text-muted small mb-4">
  Az alábbi payslip dolgozóknak <strong>nincs adójele</strong>. A rendszer a normalizált névegyezés alapján
  javasol HR-párokat. Ellenőrizd az egyezéseket, jelöld be a helyeseket, majd mentsd.
  <br>Csak a bejelölt párokhoz kerül be az adójel és a HR-kapcsolat.
</p>

<?php if (!$payslipEmps): ?>
  <div class="alert alert-success">Minden payslip dolgozónak van adójele – nincs teendő.</div>
<?php else: ?>

<?php if ($matches): ?>
<form method="post">
  <div class="card p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h6 mb-0">Névegyezések <span class="badge bg-primary"><?= count($matches) ?></span></h2>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(true)">Mind bejelöl</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(false)">Mind töröl</button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-3">
        <thead>
          <tr>
            <th style="width:40px"><input type="checkbox" id="chk-all" onchange="toggleAll(this.checked)"></th>
            <th>Payslip név</th>
            <th>Payslip email</th>
            <th></th>
            <th>HR név</th>
            <th>HR adójel</th>
            <th>HR email</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($matches as $m): ?>
            <tr>
              <td>
                <input class="match-chk" type="checkbox" name="pair[<?= (int)$m['p']['id'] ?>]"
                       value="<?= (int)$m['h']['id'] ?>" checked>
              </td>
              <td><?= h($m['p']['name']) ?></td>
              <td class="text-muted small"><?= h($m['p']['email'] ?? '—') ?></td>
              <td class="text-muted">→</td>
              <td><?= h($m['h']['full_name']) ?></td>
              <td class="font-monospace small"><?= h($m['h']['tax_id']) ?></td>
              <td class="text-muted small"><?= h($m['h']['email'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div>
      <button class="btn btn-primary" type="submit">Kijelöltek mentése</button>
    </div>
  </div>
</form>
<?php else: ?>
  <div class="alert alert-info">Nincs automatikus névegyezés a payslip és HR rekordok között.</div>
<?php endif; ?>

<?php if ($noMatch): ?>
<div class="card p-3 mb-4">
  <h2 class="h6 mb-1">Nem egyező payslip rekordok <span class="badge bg-secondary"><?= count($noMatch) ?></span></h2>
  <p class="text-muted small mb-3">Ezeket a neveket nem sikerült HR rekordhoz illeszteni. Kézzel add meg az adójelet a payslip szerkesztőben, vagy vedd fel őket a HR modulban.</p>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr><th>Payslip név</th><th>Email</th><th>Javítás</th></tr>
      </thead>
      <tbody>
        <?php foreach ($noMatch as $r): ?>
          <tr>
            <td><?= h($r['name']) ?></td>
            <td class="text-muted small"><?= h($r['email'] ?? '—') ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="employees.php?edit=<?= (int)$r['id'] ?>">Szerkeszt</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
function toggleAll(state) {
  document.querySelectorAll('.match-chk').forEach(c => c.checked = state);
  const all = document.getElementById('chk-all');
  if (all) all.checked = state;
}
</script>

<?php page_footer(); ?>

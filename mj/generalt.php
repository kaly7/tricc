<?php
require_once __DIR__.'/bootstrap.php';
$db = db();

$projekt_id = intval($_GET['projekt_id'] ?? 0);
if (!$projekt_id) { header('Location: index.php'); exit; }

$projekt = $db->prepare('SELECT * FROM projektek WHERE id=?');
$projekt->execute([$projekt_id]);
$projekt = $projekt->fetch();
if (!$projekt) { header('Location: index.php'); exit; }

// Tételek betöltése, csoportosítva
$stmt = $db->prepare('
  SELECT t.*, e.megnevezes AS egysegar_nev, e.egyseg_dij, e.egyseg AS egysegar_egyseg
  FROM tetelek t
  LEFT JOIN egysegarak e ON e.id = t.egysegar_id
  WHERE t.projekt_id = ?
  ORDER BY t.sorrend, t.id
');
$stmt->execute([$projekt_id]);
$tetelek = $stmt->fetchAll();

// Csoportosítás: csoport_id + egysegar_id együtt határozza meg a napidíj sort
// Azaz: egy csoporton belül lehetnek különböző egységáras tételek, de mindegyik külön napidíj sort kap
// Csoporton belül az azonos egysegar_id-jú tételek összegzett munkadíja → egy napidíj sor

// Generált sorok felépítése
// Típusok: 'anyag' = anyagtétel, 'napidij' = tört napidíj sor

$sorok = [];
$csoportok = [];
foreach ($tetelek as $t) {
  $key = $t['csoport_id'].'_'.($t['egysegar_id'] ?? 'null');
  if (!isset($csoportok[$key])) {
    $csoportok[$key] = [
      'csoport_id'    => $t['csoport_id'],
      'egysegar_id'   => $t['egysegar_id'],
      'egysegar_nev'  => $t['egysegar_nev'],
      'egyseg_dij'    => $t['egyseg_dij'],
      'egysegar_egyseg' => $t['egysegar_egyseg'],
      'munka_osszeg'  => 0,
      'tetelek'       => [],
    ];
  }
  $csoportok[$key]['munka_osszeg'] += $t['mennyiseg'] * $t['munkadij_egyseg'];
  $csoportok[$key]['tetelek'][] = $t;
}

// Sorrendbe rakjuk a csoportokat az első tételük sorrendje alapján
uasort($csoportok, function($a, $b) {
  $sa = $a['tetelek'][0]['sorrend'] ?? 0;
  $sb = $b['tetelek'][0]['sorrend'] ?? 0;
  return $sa <=> $sb;
});

// Generált sor lista összeállítása
$gen_sorok = [];
$prev_csoport = null;
foreach ($csoportok as $key => $csoport) {
  $hatarjelzo = ($prev_csoport !== null && $csoport['csoport_id'] !== $prev_csoport);
  foreach ($csoport['tetelek'] as $t) {
    $gen_sorok[] = [
      'tipus'      => 'anyag',
      'csoport_id' => $csoport['csoport_id'],
      'hatar'      => $hatarjelzo,
      'tetel'      => $t,
    ];
    $hatarjelzo = false; // csak az első tételnél jelezzük a határt
  }
  // Napidíj sor, ha van egységár hozzárendelve ÉS van munkadíj
  if ($csoport['egysear_id'] !== null || $csoport['egysegar_id'] !== null) {
    if ($csoport['munka_osszeg'] > 0 && $csoport['egyseg_dij'] > 0) {
      $tort = $csoport['munka_osszeg'] / $csoport['egyseg_dij'];
      $gen_sorok[] = [
        'tipus'        => 'napidij',
        'csoport_id'   => $csoport['csoport_id'],
        'hatar'        => false,
        'egysegar_nev' => $csoport['egysegar_nev'],
        'egyseg_dij'   => $csoport['egyseg_dij'],
        'egysegar_egyseg' => $csoport['egysegar_egyseg'],
        'tort'         => $tort,
        'dij_osszesen' => $csoport['munka_osszeg'],
      ];
    }
  }
  $prev_csoport = $csoport['csoport_id'];
}

// Összesítők
$anyag_total  = 0;
$munka_total  = 0;
$napidij_total = 0;
foreach ($gen_sorok as $sor) {
  if ($sor['tipus'] === 'anyag') {
    $anyag_total  += $sor['tetel']['mennyiseg'] * $sor['tetel']['anyagar_egyseg'];
  }
  if ($sor['tipus'] === 'napidij') {
    $napidij_total += $sor['dij_osszesen'];
  }
}
$vegosszeg = $anyag_total + $napidij_total;
$afa       = $vegosszeg * 0.27;
$brutto    = $vegosszeg + $afa;

$ref = $projekt['munka1_osszeg'];
$elteresszazalek = ($ref > 0) ? (($vegosszeg - $ref) / $ref * 100) : null;
?>
<?php
$title = 'MJ – Munka3 – '.htmlspecialchars($projekt['nev']);
$head_extra = '<style>
@media print {
  nav.navbar, .no-print { display:none !important; }
  body { background:white !important; }
  .container-fluid { padding:0 !important; }
}
tr.napidij-sor td { background:#e8f4ff !important; font-style:italic; }
tr.csoport-hatar td { border-top:2px solid #0d6efd !important; }
.tort-szam { font-weight:bold; color:#0d6efd; }
</style>';
require __DIR__.'/_header.php'; ?>

  <div class="d-flex justify-content-between align-items-center mb-3 no-print flex-wrap gap-2">
    <div>
      <a href="projekt.php?id=<?= $projekt_id ?>" class="text-decoration-none text-muted small">← Vissza a tételekhez</a>
      <h5 class="mt-1">Generált Munka3 – <?= htmlspecialchars($projekt['nev']) ?></h5>
    </div>
    <div class="d-flex gap-2">
      <div class="d-flex gap-2 flex-wrap">
        <a href="export.php?projekt_id=<?= $projekt_id ?>&format=csv"  class="btn btn-outline-success btn-sm">⬇ CSV</a>
        <a href="export.php?projekt_id=<?= $projekt_id ?>&format=xlsx" class="btn btn-outline-success btn-sm">⬇ XLSX</a>
        <a href="export.php?projekt_id=<?= $projekt_id ?>&format=pdf"  class="btn btn-outline-danger btn-sm">⬇ PDF</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">🖨 Nyomtatás</button>
      </div>
    </div>
  </div>

  <!-- Összesítő kártyák -->
  <div class="row g-3 mb-4 no-print">
    <div class="col-auto">
      <div class="card text-center px-3 py-2">
        <div class="small text-muted">Anyag összesen</div>
        <div class="fw-bold"><?= number_format($anyag_total, 0, ',', ' ') ?> Ft</div>
      </div>
    </div>
    <div class="col-auto">
      <div class="card text-center px-3 py-2">
        <div class="small text-muted">Munkadíj (napidíj) összesen</div>
        <div class="fw-bold"><?= number_format($napidij_total, 0, ',', ' ') ?> Ft</div>
      </div>
    </div>
    <div class="col-auto">
      <div class="card text-center px-3 py-2 border-success">
        <div class="small text-muted">Nettó végösszeg</div>
        <div class="fw-bold text-success"><?= number_format($vegosszeg, 0, ',', ' ') ?> Ft</div>
      </div>
    </div>
    <div class="col-auto">
      <div class="card text-center px-3 py-2">
        <div class="small text-muted">27% ÁFA</div>
        <div class="fw-bold"><?= number_format($afa, 0, ',', ' ') ?> Ft</div>
      </div>
    </div>
    <div class="col-auto">
      <div class="card text-center px-3 py-2 border-primary">
        <div class="small text-muted">Bruttó</div>
        <div class="fw-bold text-primary"><?= number_format($brutto, 0, ',', ' ') ?> Ft</div>
      </div>
    </div>
    <?php if ($ref > 0): ?>
    <div class="col-auto">
      <div class="card text-center px-3 py-2 <?= $vegosszeg >= $ref ? 'border-success' : 'border-danger' ?>">
        <div class="small text-muted">vs. Munka1 ref. (<?= number_format($ref, 0, ',', ' ') ?> Ft)</div>
        <div class="fw-bold <?= $vegosszeg >= $ref ? 'text-success' : 'text-danger' ?>">
          <?= ($vegosszeg >= $ref ? '+' : '') ?><?= number_format($vegosszeg - $ref, 0, ',', ' ') ?> Ft
          (<?= number_format($elteresszazalek, 1) ?>%)
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$gen_sorok): ?>
    <div class="alert alert-warning">Nincsenek tételek. <a href="projekt.php?id=<?= $projekt_id ?>">Vissza a projekthez</a></div>
  <?php else: ?>

  <!-- Fő táblázat -->
  <div class="card">
    <div class="card-header">
      <?= htmlspecialchars($projekt['nev']) ?> – Gyengeáramú munkák árajánlata
      <?php if ($projekt['leiras']): ?> — <?= htmlspecialchars($projekt['leiras']) ?><?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.88em">
        <thead class="table-dark">
          <tr>
            <th style="width:40px">#</th>
            <th>Megnevezés</th>
            <th>Gyártó</th>
            <th>Típus</th>
            <th class="text-end" style="width:70px">Menny.</th>
            <th style="width:50px">Egys.</th>
            <th class="text-end" style="width:90px">Anyagár</th>
            <th class="text-end" style="width:90px">Díj/egys.</th>
            <th class="text-end" style="width:100px">Anyag Σ</th>
            <th class="text-end" style="width:100px">Díj Σ</th>
          </tr>
        </thead>
        <tbody>
          <?php $sorsz = 0; ?>
          <?php foreach ($gen_sorok as $sor): ?>
            <?php if ($sor['tipus'] === 'anyag'):
              $t = $sor['tetel'];
              $anyag_o = $t['mennyiseg'] * $t['anyagar_egyseg'];
              $sorsz++;
            ?>
            <tr class="<?= $sor['hatar'] ? 'csoport-hatar' : '' ?>">
              <td class="text-muted"><?= $sorsz ?>.</td>
              <td><?= htmlspecialchars($t['megnevezes']) ?></td>
              <td class="text-muted"><?= htmlspecialchars($t['gyarto']) ?></td>
              <td class="text-muted"><?= htmlspecialchars($t['tipus']) ?></td>
              <td class="text-end"><?= rtrim(rtrim(number_format($t['mennyiseg'], 4, ',', ' '), '0'), ',') ?></td>
              <td><?= htmlspecialchars($t['egyseg']) ?></td>
              <td class="text-end"><?= number_format($t['anyagar_egyseg'], 0, ',', ' ') ?></td>
              <td class="text-end text-muted">0</td>
              <td class="text-end"><?= number_format($anyag_o, 0, ',', ' ') ?></td>
              <td class="text-end text-muted">—</td>
            </tr>

            <?php elseif ($sor['tipus'] === 'napidij'): $sorsz++; ?>
            <tr class="napidij-sor">
              <td class="text-muted"><?= $sorsz ?>.</td>
              <td colspan="3" class="text-primary fw-semibold"><?= htmlspecialchars($sor['egysegar_nev']) ?></td>
              <td class="text-end tort-szam"><?= number_format($sor['tort'], 4, ',', ' ') ?></td>
              <td><?= htmlspecialchars($sor['egysegar_egyseg'] ?? 'klt') ?></td>
              <td class="text-end text-muted">—</td>
              <td class="text-end"><?= number_format($sor['egyseg_dij'], 0, ',', ' ') ?></td>
              <td class="text-end text-muted">—</td>
              <td class="text-end fw-bold"><?= number_format($sor['dij_osszesen'], 0, ',', ' ') ?></td>
            </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="table-secondary fw-bold">
            <td colspan="8" class="text-end">Összesen nettó:</td>
            <td class="text-end"><?= number_format($anyag_total, 0, ',', ' ') ?></td>
            <td class="text-end"><?= number_format($napidij_total, 0, ',', ' ') ?></td>
          </tr>
          <tr class="table-light">
            <td colspan="8" class="text-end">Nettó végösszeg:</td>
            <td colspan="2" class="text-end fw-bold"><?= number_format($vegosszeg, 0, ',', ' ') ?> Ft</td>
          </tr>
          <tr class="table-light">
            <td colspan="8" class="text-end">27% ÁFA:</td>
            <td colspan="2" class="text-end"><?= number_format($afa, 0, ',', ' ') ?> Ft</td>
          </tr>
          <tr class="table-primary fw-bold">
            <td colspan="8" class="text-end">Bruttó végösszeg:</td>
            <td colspan="2" class="text-end"><?= number_format($brutto, 0, ',', ' ') ?> Ft</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <?php endif; ?>

</div>
<?php require __DIR__.'/_footer.php'; ?>

<?php
require_once __DIR__.'/db.php';
$db = db();

$projekt_id = intval($_GET['projekt_id'] ?? $_POST['projekt_id'] ?? 0);
if (!$projekt_id) { header('Location: index.php'); exit; }

$projekt = $db->prepare('SELECT * FROM projektek WHERE id=?');
$projekt->execute([$projekt_id]);
$projekt = $projekt->fetch();
if (!$projekt) { header('Location: index.php'); exit; }

$egysegarak = $db->query('SELECT id, sorsz, megnevezes, egyseg, egyseg_dij FROM egysegarak ORDER BY sorsz')->fetchAll();

$msg   = '';
$step  = 'upload';   // upload | preview | done
$sorok = [];         // beolvasott sorok a preview-hoz
$fejlec = [];

// ──────────────────────────────────────────
// XLSX beolvasás (ZipArchive + SimpleXML)
// ──────────────────────────────────────────
function parse_xlsx(string $path, int $sheet_index = 0): array {
    $rows = [];
    $zip  = new ZipArchive();
    if ($zip->open($path) !== true) return $rows;

    // shared strings
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        $xml = simplexml_load_string($ss);
        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($xml->xpath('//x:si') as $si) {
            $t = $si->xpath('.//x:t');
            $shared[] = $t ? implode('', array_map(fn($n) => (string)$n, $t)) : '';
        }
    }

    // sheet path
    $wb = $zip->getFromName('xl/workbook.xml');
    $wb_xml = simplexml_load_string($wb);
    $wb_xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rels_xml = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $rels_xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $sheets = $wb_xml->xpath('//x:sheet');
    if (!isset($sheets[$sheet_index])) { $zip->close(); return $rows; }
    $rid = (string)$sheets[$sheet_index]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
    $rels = $rels_xml->xpath("//r:Relationship[@Id='$rid']");
    $sheet_path = 'xl/' . (string)$rels[0]['Target'];

    $sh = $zip->getFromName($sheet_path);
    if (!$sh) { $zip->close(); return $rows; }
    $sh_xml = simplexml_load_string($sh);
    $sh_xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    foreach ($sh_xml->xpath('//x:row') as $row) {
        $cells = [];
        $max_col = 0;
        foreach ($row->xpath('x:c') as $c) {
            // oszlop index kinyerése a cella referenciából (pl. "C5" → 2)
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $col = 0;
            foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
            $col--; // 0-based
            $max_col = max($max_col, $col);
            $t = (string)$c['t'];
            $v_node = $c->xpath('x:v');
            $val = '';
            if ($v_node) {
                $v = (string)$v_node[0];
                $val = ($t === 's') ? ($shared[(int)$v] ?? '') : $v;
            }
            $cells[$col] = trim(str_replace(["\r", "\n"], ' ', $val));
        }
        // kitöltés üres stringgel a hiányzó oszlopokhoz
        $filled = [];
        for ($i = 0; $i <= $max_col; $i++) $filled[] = $cells[$i] ?? '';
        if (array_filter($filled, fn($x) => $x !== '') !== []) {
            $rows[] = $filled;
        }
    }
    $zip->close();
    return $rows;
}

// ──────────────────────────────────────────
// CSV beolvasás
// ──────────────────────────────────────────
function parse_csv(string $path): array {
    $rows = [];
    if (($fh = fopen($path, 'r')) === false) return $rows;
    // BOM eltávolítás
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    // elválasztó detektálás
    $first = fgets($fh);
    rewind($fh);
    if ($bom !== "\xEF\xBB\xBF") fread($fh, 3); // skip BOM újra
    else rewind($fh);
    $sep = (substr_count($first, ';') >= substr_count($first, ',')) ? ';' : ',';
    while (($row = fgetcsv($fh, 0, $sep)) !== false) {
        $row = array_map(fn($v) => trim($v), $row);
        if (array_filter($row) !== []) $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

// ──────────────────────────────────────────
// Ár tisztítás: "61 500" / "61500" / "61 500,00 Ft" → float
// ──────────────────────────────────────────
function tisztit_ar(string $s): float {
    $s = preg_replace('/[^\d,.]/', '', $s);
    $s = str_replace(',', '.', $s);
    // ha több pont van (ezres elválasztó), csak az utolsó a tizedes
    if (substr_count($s, '.') > 1) {
        $parts = explode('.', $s);
        $dec = array_pop($parts);
        $s = implode('', $parts) . '.' . $dec;
    }
    return (float)$s;
}

// ──────────────────────────────────────────
// STEP 1: FELTÖLTÉS
// ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'upload') {
    $file = $_FILES['importfile'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $msg = '<div class="alert alert-danger">Feltöltési hiba.</div>';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $tmp = $file['tmp_name'];

        if ($ext === 'xlsx') {
            $sorok = parse_xlsx($tmp);
        } elseif (in_array($ext, ['csv', 'txt'])) {
            $sorok = parse_csv($tmp);
        } else {
            $msg = '<div class="alert alert-danger">Csak XLSX vagy CSV fájl fogadható el.</div>';
        }

        if ($sorok) {
            // Session-be mentjük a sorokat
            session_start();
            $_SESSION['mj_import_sorok']      = $sorok;
            $_SESSION['mj_import_projekt_id'] = $projekt_id;
            $step   = 'preview';
            $fejlec = $sorok[0];
        }
    }
}

// ──────────────────────────────────────────
// STEP 2: IMPORTÁLÁS
// ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'import') {
    session_start();
    $sorok = $_SESSION['mj_import_sorok'] ?? [];
    $pid   = intval($_SESSION['mj_import_projekt_id'] ?? 0);

    if (!$sorok || $pid !== $projekt_id) {
        $msg = '<div class="alert alert-danger">Lejárt munkamenet, tölts fel újra.</div>';
    } else {
        $col_megnevezes  = intval($_POST['col_megnevezes']);
        $col_gyarto      = intval($_POST['col_gyarto'] ?? -1);
        $col_tipus       = intval($_POST['col_tipus'] ?? -1);
        $col_rendszam    = intval($_POST['col_rendszam'] ?? -1);
        $col_mennyiseg   = intval($_POST['col_mennyiseg'] ?? -1);
        $col_egyseg      = intval($_POST['col_egyseg'] ?? -1);
        $col_anyagar     = intval($_POST['col_anyagar'] ?? -1);
        $col_munkadij    = intval($_POST['col_munkadij'] ?? -1);
        $skip_fejlec     = intval($_POST['skip_fejlec'] ?? 1);
        $def_egysegar    = intval($_POST['def_egysegar'] ?? 0) ?: null;
        $def_csoport     = intval($_POST['def_csoport'] ?? 1);
        $csoport_egyenkent = intval($_POST['csoport_egyenkent'] ?? 0);

        // Max sorrend
        $maxs = $db->prepare('SELECT COALESCE(MAX(sorrend),0) FROM tetelek WHERE projekt_id=?');
        $maxs->execute([$projekt_id]);
        $sorrend = $maxs->fetchColumn() + 10;

        $stmt = $db->prepare('INSERT INTO tetelek
            (projekt_id,sorrend,csoport_id,megnevezes,gyarto,tipus,rendeles_szam,mennyiseg,egyseg,anyagar_egyseg,munkadij_egyseg,egysegar_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');

        $db->beginTransaction();
        $cnt = 0;
        $csoport = $def_csoport;
        foreach ($sorok as $i => $sor) {
            if ($i < $skip_fejlec) continue;
            $megnevezes = trim($sor[$col_megnevezes] ?? '');
            if ($megnevezes === '') continue;

            $gyarto    = $col_gyarto  >= 0 ? trim($sor[$col_gyarto]  ?? '') : '';
            $tipus     = $col_tipus   >= 0 ? trim($sor[$col_tipus]   ?? '') : '';
            $rendszam  = $col_rendszam >= 0 ? trim($sor[$col_rendszam] ?? '') : '';
            $menny     = $col_mennyiseg >= 0 ? tisztit_ar($sor[$col_mennyiseg] ?? '1') : 1;
            $egyseg    = $col_egyseg  >= 0 ? trim($sor[$col_egyseg]  ?? 'db') : 'db';
            $anyagar   = $col_anyagar >= 0 ? tisztit_ar($sor[$col_anyagar]  ?? '0') : 0;
            $munkadij  = $col_munkadij >= 0 ? tisztit_ar($sor[$col_munkadij] ?? '0') : 0;

            if ($menny <= 0) $menny = 1;
            if ($egyseg === '') $egyseg = 'db';

            if ($csoport_egyenkent) $csoport = $def_csoport + $cnt;

            $stmt->execute([$projekt_id, $sorrend, $csoport, $megnevezes, $gyarto, $tipus, $rendszam, $menny, $egyseg, $anyagar, $munkadij, $def_egysegar]);
            $sorrend += 10;
            $cnt++;
        }
        $db->commit();
        unset($_SESSION['mj_import_sorok']);

        header('Location: projekt.php?id='.$projekt_id.'&imported='.$cnt);
        exit;
    }
}

// Preview session visszatöltés (ha újra megjelenik a preview oldal)
if ($step === 'upload' && isset($_GET['preview'])) {
    session_start();
    if (!empty($_SESSION['mj_import_sorok']) && $_SESSION['mj_import_projekt_id'] == $projekt_id) {
        $sorok  = $_SESSION['mj_import_sorok'];
        $fejlec = $sorok[0] ?? [];
        $step   = 'preview';
    }
}

// Oszlopok száma a preview-hoz
$oszlop_db = 0;
foreach ($sorok as $sor) $oszlop_db = max($oszlop_db, count($sor));
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MJ – Tételek importálása</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
.col-select { width:100%; font-size:.78em; }
thead.sticky-top th { position:sticky; top:0; z-index:2; }
.preview-table td, .preview-table th { white-space:nowrap; font-size:.82em; padding:3px 6px; }
</style>
</head>
<body class="bg-light">
<div class="container-fluid py-3 px-4">

  <div class="mb-3">
    <a href="projekt.php?id=<?= $projekt_id ?>" class="text-decoration-none text-muted small">← <?= htmlspecialchars($projekt['nev']) ?></a>
    <h5 class="mt-1">Tételek importálása</h5>
  </div>

  <?= $msg ?>

  <?php if ($step === 'upload'): ?>
  <!-- ══════════════════════════════════════════════ -->
  <!-- STEP 1: Feltöltés                              -->
  <!-- ══════════════════════════════════════════════ -->
  <div class="card" style="max-width:540px">
    <div class="card-header">Fájl feltöltése</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="step" value="upload">
        <input type="hidden" name="projekt_id" value="<?= $projekt_id ?>">
        <div class="mb-3">
          <label class="form-label">XLSX vagy CSV fájl</label>
          <input type="file" name="importfile" class="form-control" accept=".xlsx,.csv,.txt" required>
          <div class="form-text">Az első sort fejlécként kezeli a rendszer (kihagyható).</div>
        </div>
        <button type="submit" class="btn btn-primary">Feltöltés és előnézet →</button>
        <a href="projekt.php?id=<?= $projekt_id ?>" class="btn btn-outline-secondary ms-2">Mégsem</a>
      </form>
    </div>
  </div>

  <?php elseif ($step === 'preview'): ?>
  <!-- ══════════════════════════════════════════════ -->
  <!-- STEP 2: Oszlop-mapping + preview               -->
  <!-- ══════════════════════════════════════════════ -->
  <form method="post">
    <input type="hidden" name="step" value="import">
    <input type="hidden" name="projekt_id" value="<?= $projekt_id ?>">

    <div class="row g-3 mb-3">
      <!-- Oszlop hozzárendelések -->
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header">Oszlopok hozzárendelése</div>
          <div class="card-body">
            <?php
            $oszlop_opcio = '<option value="-1">— kihagyás —</option>';
            for ($i = 0; $i < $oszlop_db; $i++) {
                $label = isset($fejlec[$i]) && $fejlec[$i] !== '' ? htmlspecialchars($fejlec[$i]) : "#{$i}";
                $oszlop_opcio .= "<option value=\"{$i}\">{$i}: {$label}</option>";
            }
            // Munka1 oszlop-struktúra alapján javaslatok:
            // 0:Sorsz, 1:Beépítési hely, 2:Tervjel, 3:Megnevezés, 4:Gyártó, 5:Típus, 6:Rendelési szám, 7:Menny, 8:Egys, 9:Anyagár, 10:Díj egység
            $col_javasok = [
                'col_megnevezes' => ['label'=>'Megnevezés <span class="text-danger">*</span>',  'def'=>3, 'required'=>true],
                'col_gyarto'     => ['label'=>'Gyártó',      'def'=>4],
                'col_tipus'      => ['label'=>'Típus',       'def'=>5],
                'col_rendszam'   => ['label'=>'Rendelési szám', 'def'=>6],
                'col_mennyiseg'  => ['label'=>'Mennyiség',   'def'=>7],
                'col_egyseg'     => ['label'=>'Egység',      'def'=>8],
                'col_anyagar'    => ['label'=>'Anyagár / egység', 'def'=>9],
                'col_munkadij'   => ['label'=>'Munkadíj / egység', 'def'=>10],
            ];
            ?>
            <div class="row g-2">
              <?php foreach ($col_javasok as $name => $info): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small mb-1"><?= $info['label'] ?></label>
                <select name="<?= $name ?>" class="form-select form-select-sm col-select">
                  <?php if (empty($info['required'])): ?>
                    <option value="-1">— kihagyás —</option>
                  <?php endif; ?>
                  <?php for ($i = 0; $i < $oszlop_db; $i++):
                    $label = isset($fejlec[$i]) && $fejlec[$i] !== '' ? htmlspecialchars($fejlec[$i]) : "#{$i}";
                  ?>
                    <option value="<?= $i ?>" <?= ($i === $info['def']) ? 'selected' : '' ?>><?= $i ?>: <?= $label ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <?php endforeach; ?>
            </div>

            <hr class="my-3">
            <div class="row g-2">
              <div class="col-md-3 col-6">
                <label class="form-label small mb-1">Fejléc sorok kihagyása</label>
                <input type="number" name="skip_fejlec" value="1" min="0" max="5" class="form-control form-control-sm">
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Alapértelmezett szerz. tétel</label>
                <select name="def_egysegar" class="form-select form-select-sm">
                  <option value="">— nincs —</option>
                  <?php foreach ($egysegarak as $e): ?>
                    <option value="<?= $e['id'] ?>">[<?= $e['sorsz'] ?>] <?= htmlspecialchars(mb_strimwidth($e['megnevezes'], 0, 50, '…')) ?> (<?= number_format($e['egyseg_dij'],0,',', ' ') ?> Ft)</option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Minden importált tételhez ez lesz hozzárendelve (utólag módosítható).</div>
              </div>
              <div class="col-md-2 col-6">
                <label class="form-label small mb-1">Kezdő csoport #</label>
                <input type="number" name="def_csoport" value="1" min="1" class="form-control form-control-sm">
              </div>
              <div class="col-md-3 col-6 d-flex align-items-end">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="csoport_egyenkent" value="1" id="csoportCheck">
                  <label class="form-check-label small" for="csoportCheck">Minden sor külön csoport</label>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Összesítő -->
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header">Összesítő</div>
          <div class="card-body">
            <p class="mb-1">Beolvasott sorok: <strong><?= count($sorok) ?></strong></p>
            <p class="mb-1">Oszlopok száma: <strong><?= $oszlop_db ?></strong></p>
            <p class="mb-3 text-muted small">Az 1. fejlécsor kihagyásával <strong><?= max(0, count($sorok)-1) ?></strong> tétel kerül importálásra.</p>
            <button type="submit" class="btn btn-success w-100">✓ Importálás</button>
            <a href="tetel_import.php?projekt_id=<?= $projekt_id ?>" class="btn btn-outline-secondary w-100 mt-2">← Új fájl</a>
            <a href="projekt.php?id=<?= $projekt_id ?>" class="btn btn-outline-secondary w-100 mt-2">Mégsem</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Preview táblázat -->
    <div class="card">
      <div class="card-header">Előnézet (első <?= min(20, count($sorok)) ?> sor)</div>
      <div class="table-responsive" style="max-height:400px;overflow-y:auto">
        <table class="table table-bordered table-sm preview-table mb-0">
          <thead class="table-dark sticky-top">
            <tr>
              <th>#</th>
              <?php for ($i = 0; $i < $oszlop_db; $i++): ?>
                <th><?= $i ?>: <?= htmlspecialchars($fejlec[$i] ?? '') ?></th>
              <?php endfor; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($sorok, 0, 20) as $ri => $sor): ?>
            <tr class="<?= $ri === 0 ? 'table-warning' : '' ?>">
              <td class="text-muted"><?= $ri ?></td>
              <?php for ($i = 0; $i < $oszlop_db; $i++): ?>
                <td><?= htmlspecialchars($sor[$i] ?? '') ?></td>
              <?php endfor; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-muted small">A sárga sor (0.) az alapértelmezetten kihagyott fejlécsor.</div>
    </div>

  </form>
  <?php endif; ?>

</div>
</body>
</html>

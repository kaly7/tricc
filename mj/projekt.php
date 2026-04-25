<?php
require_once __DIR__.'/bootstrap.php';
$db = db();

$projekt_id = intval($_GET['id'] ?? 0);
if (!$projekt_id) { header('Location: index.php'); exit; }

$projekt = $db->prepare('SELECT * FROM projektek WHERE id=?');
$projekt->execute([$projekt_id]);
$projekt = $projekt->fetch();
if (!$projekt) { header('Location: index.php'); exit; }

$msg = '';

if (isset($_GET['imported'])) {
  $msg = '<div class="alert alert-success">'.intval($_GET['imported']).' tétel sikeresen importálva.</div>';
}

// ─────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────
function ar(string $s): float {
  return (float)str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $s);
}

function load_tetelek(PDO $db, int $pid): array {
  $s = $db->prepare('SELECT t.*, e.megnevezes AS egysegar_nev, e.egyseg_dij
    FROM tetelek t LEFT JOIN egysegarak e ON e.id=t.egysegar_id
    WHERE t.projekt_id=? ORDER BY t.sorrend, t.id');
  $s->execute([$pid]);
  return $s->fetchAll();
}

function next_verzio(PDO $db, int $pid): int {
  $r = $db->prepare('SELECT COALESCE(MAX(verzio_szam),0) FROM projekt_verziok WHERE projekt_id=?');
  $r->execute([$pid]);
  return intval($r->fetchColumn()) + 1;
}

function save_snapshot(PDO $db, int $pid, array $tetelek, string $megjegyzes = ''): int {
  $vzs = next_verzio($db, $pid);
  $snap = json_encode(array_map(fn($t) => [
    'id'             => $t['id'],
    'sorrend'        => $t['sorrend'],
    'csoport_id'     => $t['csoport_id'],
    'megnevezes'     => $t['megnevezes'],
    'gyarto'         => $t['gyarto'],
    'tipus'          => $t['tipus'],
    'rendeles_szam'  => $t['rendeles_szam'],
    'mennyiseg'      => $t['mennyiseg'],
    'egyseg'         => $t['egyseg'],
    'anyagar_egyseg' => $t['anyagar_egyseg'],
    'munkadij_egyseg'=> $t['munkadij_egyseg'],
    'egysegar_id'    => $t['egysegar_id'],
  ], $tetelek), JSON_UNESCAPED_UNICODE);
  $db->prepare('INSERT INTO projekt_verziok (projekt_id,verzio_szam,megjegyzes,snapshot) VALUES (?,?,?,?)')
     ->execute([$pid, $vzs, $megjegyzes, $snap]);
  return $vzs;
}

// ─────────────────────────────────────────────────────
// POST akciók
// ─────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

// --- Batch mentés (számok + sorrend + csoport) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'batch_save') {
  $rows = $_POST['t'] ?? [];
  $upd  = $db->prepare('UPDATE tetelek SET sorrend=?,csoport_id=?,mennyiseg=?,egyseg=?,anyagar_egyseg=?,munkadij_egyseg=? WHERE id=? AND projekt_id=?');
  $db->beginTransaction();
  foreach ($rows as $tid => $v) {
    $tid = intval($tid);
    if (!$tid) continue;
    $upd->execute([
      intval($v['sorrend']   ?? 0),
      intval($v['csoport_id']?? 1),
      (float)str_replace(',','.',$v['mennyiseg'] ?? '1'),
      trim($v['egyseg'] ?? 'db'),
      ar($v['anyagar']  ?? '0'),
      ar($v['munkadij'] ?? '0'),
      $tid, $projekt_id,
    ]);
  }
  $db->commit();
  // snapshot mentés
  $tetelek_snap = load_tetelek($db, $projekt_id);
  $vzs = save_snapshot($db, $projekt_id, $tetelek_snap, trim($_POST['verzio_megjegyzes'] ?? ''));
  header('Location: projekt.php?id='.$projekt_id.'&v='.$vzs.'&saved=1');
  exit;
}

// --- Verzió visszatöltés ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'load_verzio') {
  $vzid        = intval($_POST['verzio_id'] ?? 0);
  $save_before = intval($_POST['save_before_load'] ?? 0);
  $row  = $db->prepare('SELECT * FROM projekt_verziok WHERE id=? AND projekt_id=?');
  $row->execute([$vzid, $projekt_id]);
  $verzio = $row->fetch();
  if ($verzio) {
    $snap = json_decode($verzio['snapshot'], true);
    // Csak ha a felhasználó kérte a mentést
    if ($save_before) {
      $tetelek_snap = load_tetelek($db, $projekt_id);
      save_snapshot($db, $projekt_id, $tetelek_snap, 'Mentés betöltés előtt');
    }
    // Tételek törlése és újra-beillesztése
    $db->prepare('DELETE FROM tetelek WHERE projekt_id=?')->execute([$projekt_id]);
    $ins = $db->prepare('INSERT INTO tetelek (projekt_id,sorrend,csoport_id,megnevezes,gyarto,tipus,rendeles_szam,mennyiseg,egyseg,anyagar_egyseg,munkadij_egyseg,egysegar_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($snap as $t) {
      $ins->execute([$projekt_id,$t['sorrend'],$t['csoport_id'],$t['megnevezes'],$t['gyarto'],$t['tipus'],$t['rendeles_szam'],$t['mennyiseg'],$t['egyseg'],$t['anyagar_egyseg'],$t['munkadij_egyseg'],$t['egysegar_id']]);
    }
    $vzs = $verzio['verzio_szam'];
    header('Location: projekt.php?id='.$projekt_id.'&loaded='.$vzs.'&v='.$vzs);
    exit;
  }
}

// --- Verzió törlés ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_verzio') {
  $vzid = intval($_POST['verzio_id'] ?? 0);
  if ($vzid > 0) {
    $db->prepare('DELETE FROM projekt_verziok WHERE id=? AND projekt_id=?')->execute([$vzid, $projekt_id]);
  }
  header('Location: projekt.php?id='.$projekt_id);
  exit;
}

// --- Tétel törlés ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
  $tid = intval($_POST['tetel_id'] ?? 0);
  if ($tid > 0) {
    $db->prepare('DELETE FROM tetelek WHERE id=? AND projekt_id=?')->execute([$tid, $projekt_id]);
    $msg = '<div class="alert alert-warning">Tétel törölve.</div>';
  }
}

// --- Egységár inline ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_egysegar') {
  $tid      = intval($_POST['tetel_id'] ?? 0);
  $egysegar = intval($_POST['egysegar_id'] ?? 0) ?: null;
  if ($tid > 0) {
    $db->prepare('UPDATE tetelek SET egysegar_id=? WHERE id=? AND projekt_id=?')->execute([$egysegar,$tid,$projekt_id]);
  }
  header('Location: projekt.php?id='.$projekt_id);
  exit;
}

// --- Összevonás ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'merge') {
  $ids = array_filter(array_map('intval', (array)($_POST['sel'] ?? [])));
  $cel = intval($_POST['cel_csoport'] ?? 0);
  if (!$cel && $ids) {
    $in  = implode(',', $ids);
    $cel = intval($db->query("SELECT MIN(csoport_id) FROM tetelek WHERE projekt_id=$projekt_id AND id IN ($in)")->fetchColumn()) ?: 1;
  }
  if ($ids && $cel) {
    $in = implode(',', $ids);
    $db->exec("UPDATE tetelek SET csoport_id=$cel WHERE projekt_id=$projekt_id AND id IN ($in)");
    $msg = '<div class="alert alert-success">'.count($ids).' sor összevonva a '.$cel.'. csoportba.</div>';
  }
}

// --- Szétválasztás ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'split') {
  $ids = array_filter(array_map('intval', (array)($_POST['sel'] ?? [])));
  if ($ids) {
    $max = $db->prepare('SELECT COALESCE(MAX(csoport_id),0) FROM tetelek WHERE projekt_id=?');
    $max->execute([$projekt_id]);
    $next = intval($max->fetchColumn()) + 1;
    $upd  = $db->prepare('UPDATE tetelek SET csoport_id=? WHERE id=? AND projekt_id=?');
    foreach ($ids as $tid) $upd->execute([$next++, $tid, $projekt_id]);
    $msg = '<div class="alert alert-success">'.count($ids).' sor szétválasztva.</div>';
  }
}

// --- Katalógusba mentés ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'katalogusba') {
  $tetelek_now = load_tetelek($db, $projekt_id);
  $ins = $db->prepare('
    INSERT INTO anyagar_katalogus (megnevezes,gyarto,tipus,rendeles_szam,egyseg,anyagar_egyseg,munkadij_egyseg)
    VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      gyarto=VALUES(gyarto), tipus=VALUES(tipus), rendeles_szam=VALUES(rendeles_szam),
      egyseg=VALUES(egyseg), anyagar_egyseg=VALUES(anyagar_egyseg),
      munkadij_egyseg=VALUES(munkadij_egyseg), frissitve=NOW()
  ');
  $cnt = 0;
  foreach ($tetelek_now as $t) {
    if (trim($t['megnevezes']) === '') continue;
    $ins->execute([
      trim($t['megnevezes']), trim($t['gyarto']), trim($t['tipus']),
      trim($t['rendeles_szam']), trim($t['egyseg']),
      $t['anyagar_egyseg'], $t['munkadij_egyseg'],
    ]);
    $cnt++;
  }
  $msg = '<div class="alert alert-success">'.$cnt.' tétel mentve az anyagárak katalógusába.</div>';
}

// --- Tömeges egységár ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_egysegar') {
  $ids      = array_filter(array_map('intval', (array)($_POST['sel'] ?? [])));
  $egysegar = intval($_POST['bulk_egysegar_id'] ?? 0) ?: null;
  if ($ids) {
    $in = implode(',', $ids);
    $db->exec("UPDATE tetelek SET egysegar_id=".($egysegar ?? 'NULL')." WHERE projekt_id=$projekt_id AND id IN ($in)");
    $msg = '<div class="alert alert-success">'.count($ids).' sorhoz egységár hozzárendelve.</div>';
  }
}

// ─────────────────────────────────────────────────────
// Adatok betöltése
// ─────────────────────────────────────────────────────
$egysegarak = $db->query('SELECT id,sorsz,megnevezes,egyseg,egyseg_dij FROM egysegarak ORDER BY sorsz')->fetchAll();
$tetelek    = load_tetelek($db, $projekt_id);

// Verziók
$verziok = $db->prepare('SELECT id,verzio_szam,megjegyzes,letrehozva FROM projekt_verziok WHERE projekt_id=? ORDER BY verzio_szam DESC');
$verziok->execute([$projekt_id]);
$verziok = $verziok->fetchAll();
$max_verzio   = $verziok ? $verziok[0]['verzio_szam'] : 0;
$aktiv_verzio = isset($_GET['v']) ? intval($_GET['v']) : ($max_verzio ?: 0);

if (isset($_GET['saved']))  $msg = '<div class="alert alert-success">Mentve — '.$aktiv_verzio.'. verzió létrehozva.</div>';
if (isset($_GET['loaded'])) $msg = '<div class="alert alert-info">'.intval($_GET['loaded']).'. verzió betöltve.</div>';

// Csoportok + színek (36 pastel szín)
$csoportok = [];
foreach ($tetelek as $t) $csoportok[$t['csoport_id']] = true;
$palette = [
  '#FFB3B3','#FFCDB3','#FFE5B3','#FFFAB3','#E5FFB3','#CBFFB3',
  '#B3FFB3','#B3FFCB','#B3FFE5','#B3FFFA','#B3EEFF','#B3D4FF',
  '#B3BEFF','#C8B3FF','#DEB3FF','#F3B3FF','#FFB3F3','#FFB3DE',
  '#FFB3C8','#FFD0A0','#FFEBA0','#F0FFA0','#D0FFA0','#A0FFD0',
  '#A0EBFF','#A0D0FF','#B8A0FF','#EBA0FF','#FFA0EB','#FFA0B8',
  '#FFD9D9','#FFE8CC','#FFFACC','#E8FFCC','#CCE8FF','#E8CCFF',
];
$csoport_szin = [];
$ci = 0;
foreach (array_keys($csoportok) as $cid) { $csoport_szin[$cid] = $palette[$ci++ % count($palette)]; }
?>
<?php
$title = 'MJ – '.htmlspecialchars($projekt['nev']);
$head_extra = '<style>
tr.csoport-hatar td { border-top: 2px solid #0d6efd !important; }
tr.kivalasztott td  { outline: 2px solid #0d6efd; }
.inline-num, .inline-txt {
  border: 1px solid transparent; background: transparent;
  padding: 1px 3px; border-radius: 3px;
  transition: border-color .15s, background .15s; text-align: right;
}
.inline-txt { text-align: left; }
.inline-num:hover, .inline-txt:hover { background: rgba(0,0,0,.05); }
.inline-num:focus, .inline-txt:focus { border-color: #0d6efd; background: #fff; outline: none; }
.inline-num.dirty, .inline-txt.dirty { background: #fffbe6; border-color: #ffc107; }
#save-bar {
  position: sticky; top: 56px; z-index: 100;
  background: #212529; color:#fff; padding: 6px 16px;
  display: none; align-items: center; gap: 10px; flex-wrap: wrap;
}
#save-bar.lathato { display: flex; }
#bulk-toolbar {
  position: fixed; bottom: 0; left: 0; right: 0;
  background: #343a40; color:#fff; padding: 8px 16px;
  display: none; z-index: 1000; gap: 8px; align-items: center; flex-wrap: wrap;
}
#bulk-toolbar.lathato { display: flex; }
</style>';
require __DIR__.'/_header.php'; ?>

<!-- ══ Mentés sáv (megjelenik ha van módosítás) ══ -->
<div id="save-bar">
  <span id="dirty-count" class="me-2"></span>
  <input type="text" id="verzio-megjegyzes" class="form-control form-control-sm" style="max-width:220px" placeholder="Megjegyzés (opcionális)">
  <button type="button" class="btn btn-warning btn-sm fw-bold" onclick="batchSave()">💾 Mentés + új verzió</button>
  <button type="button" class="btn btn-outline-light btn-sm" onclick="resetDirty()">✕ Elvet</button>
</div>

<div class="container-fluid py-3" style="padding-bottom:70px">

  <!-- Fejléc -->
  <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
      <a href="index.php" class="text-decoration-none text-muted small">← Projektek</a>
      <h5 class="mb-0 mt-1"><?= htmlspecialchars($projekt['nev']) ?>
        <?php if ($projekt['munka1_osszeg']): ?>
          <span class="badge bg-secondary ms-2">Ref: <?= number_format($projekt['munka1_osszeg'], 0, ',', ' ') ?> Ft</span>
        <?php endif; ?>
      </h5>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <!-- Verzióváltó -->
      <?php if ($verziok): ?>
      <div class="d-flex align-items-center gap-1">
        <?php if ($aktiv_verzio == $max_verzio): ?>
          <span class="badge bg-success" title="Ez a legfrissebb verzió">v<?= $aktiv_verzio ?> ✓</span>
        <?php else: ?>
          <span class="badge bg-warning text-dark fw-bold" title="Nem a legfrissebb verzió van betöltve!">v<?= $aktiv_verzio ?> &lt; v<?= $max_verzio ?></span>
        <?php endif; ?>
        <form method="post" class="d-inline" id="load-verzio-form">
          <input type="hidden" name="action" value="load_verzio">
          <input type="hidden" name="verzio_id" id="load-verzio-id" value="">
          <input type="hidden" name="save_before_load" id="save-before-load" value="0">
          <select class="form-select form-select-sm" style="max-width:200px" id="verzio-select" onchange="loadVerzio(this)">
            <option value="">Verzió betöltése…</option>
            <?php foreach ($verziok as $v): ?>
              <option value="<?= $v['id'] ?>" <?= ($v['verzio_szam'] == $aktiv_verzio) ? 'selected' : '' ?>>
                #<?= $v['verzio_szam'] ?> – <?= substr($v['letrehozva'],0,16) ?><?= $v['megjegyzes'] ? ' – '.$v['megjegyzes'] : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
        <form method="post" class="d-inline" id="del-verzio-form" onsubmit="return delVerzioConfirm()">
          <input type="hidden" name="action" value="delete_verzio">
          <input type="hidden" name="verzio_id" id="del-verzio-id" value="">
          <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Kiválasztott verzió törlése">🗑</button>
        </form>
      </div>
      <?php else: ?>
        <span class="badge bg-secondary">Nincs mentett verzió</span>
      <?php endif; ?>
      <button type="button" class="btn btn-warning btn-sm" id="always-save-btn" onclick="alwaysSave()" title="Aktuális állapot mentése új verzióba">
        💾 Mentés <span class="badge bg-dark ms-1">v<?= $max_verzio + 1 ?></span>
      </button>
      <a href="tetel_new.php?projekt_id=<?= $projekt_id ?>" class="btn btn-primary btn-sm">+ Új tétel</a>
      <a href="tetel_import.php?projekt_id=<?= $projekt_id ?>" class="btn btn-outline-primary btn-sm">⬆ Import</a>
      <form method="post" class="d-inline" onsubmit="return confirm('A projekt összes tételének anyagárát elmenti a katalógusba (meglévő tételeket frissíti). Folytatod?')">
        <input type="hidden" name="action" value="katalogusba">
        <button class="btn btn-outline-warning btn-sm">📦 Árak → Katalógus</button>
      </form>
      <a href="katalogus.php" class="btn btn-outline-secondary btn-sm">📋 Katalógus</a>
      <a href="generalt.php?projekt_id=<?= $projekt_id ?>" class="btn btn-success btn-sm">▶ Munka3</a>
    </div>
  </div>

  <?= $msg ?>

  <!-- Bulk form (rejtett, JS tölti) -->
  <form method="post" id="bulk-form" style="display:none">
    <input type="hidden" name="action"           id="bulk-action"      value="">
    <input type="hidden" name="cel_csoport"      id="bulk-cel-csoport" value="">
    <input type="hidden" name="bulk_egysegar_id" id="bulk-egysegar-id" value="">
    <div id="bulk-ids-container"></div>
  </form>

  <!-- Batch mentés form (rejtett) -->
  <form method="post" id="batch-form" style="display:none">
    <input type="hidden" name="action" value="batch_save">
    <input type="hidden" name="verzio_megjegyzes" id="batch-megjegyzes" value="">
    <div id="batch-fields"></div>
  </form>

  <?php if (!$egysegarak): ?>
    <div class="alert alert-warning">Nincsenek szerződött egységárak. <a href="egysegarak.php">Hozzáadás</a></div>
  <?php endif; ?>

  <!-- Tételek táblázat -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Anyagtételek (<?= count($tetelek) ?> db &nbsp;|&nbsp; <?= count($csoportok) ?> csoport)</span>
      <label class="mb-0 small" style="cursor:pointer">
        <input type="checkbox" id="check-all" class="form-check-input me-1">Összes kijelölése
      </label>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle" id="tetel-tabla">
        <thead class="table-dark">
          <tr>
            <th style="width:28px"></th>
            <th style="width:56px">Sorr.</th>
            <th style="width:46px">Csop.</th>
            <th>Megnevezés</th>
            <th>Gyártó / Típus</th>
            <th class="text-end" style="width:72px">Menny.</th>
            <th style="width:44px">Egys.</th>
            <th class="text-end" style="width:96px">Anyagár/e</th>
            <th class="text-end" style="width:96px">Munkadíj/e</th>
            <th class="text-end" style="width:96px">Anyag Σ</th>
            <th class="text-end" style="width:96px">Munkadíj Σ</th>
            <th style="min-width:180px">Szerz. tétel</th>
            <th style="width:80px"></th>
          </tr>
        </thead>
        <tbody>
          <?php
          $prev_csoport = null;
          $anyag_total = 0; $munka_total = 0;
          foreach ($tetelek as $t):
            $anyag_o = $t['mennyiseg'] * $t['anyagar_egyseg'];
            $munka_o = $t['mennyiseg'] * $t['munkadij_egyseg'];
            $anyag_total += $anyag_o;
            $munka_total += $munka_o;
            $hatar = ($prev_csoport !== null && $t['csoport_id'] !== $prev_csoport);
            $prev_csoport = $t['csoport_id'];
            $bg = $csoport_szin[$t['csoport_id']] ?? '#fff';
          ?>
          <tr class="<?= $hatar ? 'csoport-hatar' : '' ?>"
              data-id="<?= $t['id'] ?>"
              data-anyag="<?= $anyag_o ?>"
              data-munka="<?= $munka_o ?>">
            <td class="text-center">
              <input type="checkbox" class="form-check-input row-check" value="<?= $t['id'] ?>">
            </td>
            <td>
              <input type="number" class="inline-num" style="width:48px"
                data-field="sorrend" data-id="<?= $t['id'] ?>" value="<?= $t['sorrend'] ?>">
            </td>
            <td class="text-center" style="background:<?= $bg ?>;padding:2px 4px">
              <input type="number" class="inline-num" style="width:40px;text-align:center;background:transparent"
                data-field="csoport_id" data-id="<?= $t['id'] ?>" value="<?= $t['csoport_id'] ?>" min="1">
            </td>
            <td>
              <div><?= htmlspecialchars($t['megnevezes']) ?></div>
              <?php if ($t['rendeles_szam']): ?>
                <div class="text-muted small"><?= htmlspecialchars($t['rendeles_szam']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= htmlspecialchars(trim($t['gyarto'].' '.$t['tipus'])) ?></td>
            <td class="text-end">
              <input type="text" class="inline-num" style="width:60px"
                data-field="mennyiseg" data-id="<?= $t['id'] ?>"
                value="<?= rtrim(rtrim(number_format($t['mennyiseg'],4,',',''), '0'), ',') ?>">
            </td>
            <td>
              <input type="text" class="inline-txt" style="width:38px"
                data-field="egyseg" data-id="<?= $t['id'] ?>" value="<?= htmlspecialchars($t['egyseg']) ?>">
            </td>
            <td class="text-end">
              <input type="text" class="inline-num" style="width:80px"
                data-field="anyagar" data-id="<?= $t['id'] ?>"
                value="<?= number_format($t['anyagar_egyseg'],0,',','') ?>">
            </td>
            <td class="text-end">
              <input type="text" class="inline-num" style="width:80px"
                data-field="munkadij" data-id="<?= $t['id'] ?>"
                value="<?= number_format($t['munkadij_egyseg'],0,',','') ?>">
            </td>
            <td class="text-end anyag-o"><?= number_format($anyag_o, 0, ',', ' ') ?> Ft</td>
            <td class="text-end munka-o"><?= number_format($munka_o, 0, ',', ' ') ?> Ft</td>
            <td>
              <form method="post">
                <input type="hidden" name="action"   value="update_egysegar">
                <input type="hidden" name="tetel_id" value="<?= $t['id'] ?>">
                <select name="egysegar_id" class="form-select form-select-sm" onchange="this.form.submit()" style="font-size:.78em">
                  <option value="">— nincs —</option>
                  <?php foreach ($egysegarak as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= ($t['egysegar_id'] == $e['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars(mb_strimwidth($e['megnevezes'], 0, 44, '…')) ?> (<?= number_format($e['egyseg_dij'],0,',', ' ') ?> Ft)
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="text-end" style="white-space:nowrap">
              <a href="tetel_new.php?projekt_id=<?= $projekt_id ?>&edit=<?= $t['id'] ?>" class="btn btn-outline-primary btn-sm py-0">✎</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törlöd?')">
                <input type="hidden" name="action"   value="delete">
                <input type="hidden" name="tetel_id" value="<?= $t['id'] ?>">
                <button class="btn btn-outline-danger btn-sm py-0">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$tetelek): ?>
          <tr><td colspan="13" class="text-center text-muted py-4">Még nincs tétel. <a href="tetel_new.php?projekt_id=<?= $projekt_id ?>">Hozzáadás</a></td></tr>
          <?php endif; ?>
        </tbody>
        <?php if ($tetelek): ?>
        <tfoot class="table-secondary fw-bold">
          <tr>
            <td colspan="9" class="text-end">Összesen:</td>
            <td class="text-end" id="total-anyag"><?= number_format($anyag_total, 0, ',', ' ') ?> Ft</td>
            <td class="text-end" id="total-munka"><?= number_format($munka_total, 0, ',', ' ') ?> Ft</td>
            <td colspan="2" class="text-end">Nettó: <span id="total-netto"><?= number_format($anyag_total+$munka_total, 0, ',', ' ') ?></span> Ft</td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div class="mt-3 d-flex gap-2 flex-wrap">
    <a href="tetel_new.php?projekt_id=<?= $projekt_id ?>" class="btn btn-primary btn-sm">+ Új tétel</a>
    <a href="tetel_import.php?projekt_id=<?= $projekt_id ?>" class="btn btn-outline-primary btn-sm">⬆ Import XLS/CSV</a>
    <a href="generalt.php?projekt_id=<?= $projekt_id ?>" class="btn btn-success btn-sm">▶ Munka3 generálás</a>
  </div>

</div><!-- /container -->

<!-- Bulk toolbar -->
<div id="bulk-toolbar">
  <span id="bulk-count" class="fw-bold me-2"></span>
  <button type="button" class="btn btn-warning btn-sm" onclick="bulkMerge()">⊞ Összevon</button>
  <div class="d-flex align-items-center gap-1">
    <input type="number" id="merge-target" class="form-control form-control-sm" style="width:62px" min="1" placeholder="Cs.#">
    <button type="button" class="btn btn-outline-warning btn-sm" onclick="bulkMergeTarget()">→ ebbe</button>
  </div>
  <button type="button" class="btn btn-outline-light btn-sm" onclick="bulkSplit()">⊟ Szétválaszt</button>
  <div class="d-flex align-items-center gap-1">
    <select id="bulk-egysegar-sel" class="form-select form-select-sm" style="max-width:240px;font-size:.8em">
      <option value="">— egységár hozzárendelése —</option>
      <?php foreach ($egysegarak as $e): ?>
        <option value="<?= $e['id'] ?>">[<?= $e['sorsz'] ?>] <?= htmlspecialchars(mb_strimwidth($e['megnevezes'],0,38,'…')) ?> (<?= number_format($e['egyseg_dij'],0,',', ' ') ?> Ft)</option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-outline-info btn-sm" onclick="bulkEgysegar()">Hozzárendel</button>
  </div>
  <button type="button" class="btn btn-outline-light btn-sm ms-auto" onclick="clearSelection()">✕</button>
</div>

<script>
// ── Inline szerkesztés ──────────────────────────────────
const dirtyRows   = {};  // {id: {field: value, ...}}
const saveBar     = document.getElementById('save-bar');
const dirtyCount  = document.getElementById('dirty-count');

function parseSzam(s) {
  return parseFloat(String(s).replace(/\s/g,'').replace(',','.')) || 0;
}
function fmt(n) {
  return new Intl.NumberFormat('hu-HU',{maximumFractionDigits:0}).format(n);
}

document.querySelectorAll('.inline-num, .inline-txt').forEach(inp => {
  const orig = inp.value;
  inp.dataset.orig = orig;

  inp.addEventListener('input', function() {
    const id    = this.dataset.id;
    const field = this.dataset.field;
    this.classList.toggle('dirty', this.value !== this.dataset.orig);
    if (!dirtyRows[id]) dirtyRows[id] = {};
    dirtyRows[id][field] = this.value;
    updateSaveBar();
    // Σ frissítés az anyag/munka oszlopokhoz
    if (['anyagar','munkadij','mennyiseg'].includes(field)) recalcRow(id);
  });
});

function recalcRow(id) {
  const tr = document.querySelector(`tr[data-id="${id}"]`);
  if (!tr) return;
  const getVal = f => {
    const el = tr.querySelector(`[data-field="${f}"]`);
    return el ? parseSzam(el.value) : 0;
  };
  const anyag = getVal('anyagar');
  const munka = getVal('munkadij');
  const menny = getVal('mennyiseg');
  tr.querySelector('.anyag-o').textContent = fmt(anyag * menny) + ' Ft';
  tr.querySelector('.munka-o').textContent = fmt(munka * menny) + ' Ft';
  recalcTotals();
}

function recalcTotals() {
  let ta = 0, tm = 0;
  document.querySelectorAll('#tetel-tabla tbody tr[data-id]').forEach(tr => {
    const id = tr.dataset.id;
    const getVal = f => {
      const el = tr.querySelector(`[data-field="${f}"]`);
      return el ? parseSzam(el.value) : 0;
    };
    ta += getVal('anyagar') * getVal('mennyiseg');
    tm += getVal('munkadij') * getVal('mennyiseg');
  });
  const ta_el = document.getElementById('total-anyag');
  const tm_el = document.getElementById('total-munka');
  const tn_el = document.getElementById('total-netto');
  if (ta_el) ta_el.textContent = fmt(ta) + ' Ft';
  if (tm_el) tm_el.textContent = fmt(tm) + ' Ft';
  if (tn_el) tn_el.textContent = fmt(ta + tm);
}

function updateSaveBar() {
  const cnt = Object.keys(dirtyRows).filter(id => Object.keys(dirtyRows[id]).length).length;
  if (cnt > 0) {
    saveBar.classList.add('lathato');
    dirtyCount.textContent = cnt + ' sor módosítva';
  } else {
    saveBar.classList.remove('lathato');
  }
}

function batchSave() {
  const megjegyzes = document.getElementById('verzio-megjegyzes').value;
  document.getElementById('batch-megjegyzes').value = megjegyzes;
  const container = document.getElementById('batch-fields');
  container.innerHTML = '';
  // Minden sor ÖSSZES mezőjét beküldjük (ne csak a dirty-t)
  document.querySelectorAll('#tetel-tabla tbody tr[data-id]').forEach(tr => {
    const id = tr.dataset.id;
    const fields = ['sorrend','csoport_id','mennyiseg','egyseg','anyagar','munkadij'];
    fields.forEach(f => {
      const el = tr.querySelector(`[data-field="${f}"]`);
      if (!el) return;
      const inp = document.createElement('input');
      inp.type  = 'hidden';
      inp.name  = `t[${id}][${f}]`;
      inp.value = el.value;
      container.appendChild(inp);
    });
  });
  document.getElementById('batch-form').submit();
}

function resetDirty() {
  document.querySelectorAll('.inline-num.dirty, .inline-txt.dirty').forEach(inp => {
    inp.value = inp.dataset.orig;
    inp.classList.remove('dirty');
  });
  for (const k in dirtyRows) delete dirtyRows[k];
  updateSaveBar();
  recalcTotals();
}

// ── Verzióváltó ─────────────────────────────────────────
function loadVerzio(sel) {
  const vid = sel.value;
  if (!vid) return;
  if (!confirm('Biztosan betöltöd a következő verziót?\n\n' + sel.options[sel.selectedIndex].text)) {
    sel.value = '';
    return;
  }
  // Egyedi modal a Igen/Nem kérdéshez
  document.getElementById('load-verzio-modal-text').textContent =
    sel.options[sel.selectedIndex].text + ' betöltése előtt elmented az aktuális állapotot?';
  document.getElementById('load-verzio-id').value = vid;
  const modal = new bootstrap.Modal(document.getElementById('loadVerzioModal'));
  modal.show();
}

function alwaysSave() {
  const megjegyzes = prompt('Megjegyzés a verzióhoz (opcionális):', '') ;
  if (megjegyzes === null) return; // Mégse
  document.getElementById('batch-megjegyzes').value = megjegyzes;
  const container = document.getElementById('batch-fields');
  container.innerHTML = '';
  document.querySelectorAll('#tetel-tabla tbody tr[data-id]').forEach(tr => {
    const id = tr.dataset.id;
    const fields = ['sorrend','csoport_id','mennyiseg','egyseg','anyagar','munkadij'];
    fields.forEach(f => {
      const el = tr.querySelector(`[data-field="${f}"]`);
      if (!el) return;
      const inp = document.createElement('input');
      inp.type  = 'hidden';
      inp.name  = `t[${id}][${f}]`;
      inp.value = el.value;
      container.appendChild(inp);
    });
  });
  document.getElementById('batch-form').submit();
}

function delVerzioConfirm() {
  const sel = document.getElementById('verzio-select');
  const vid = sel.value;
  if (!vid) { alert('Előbb válassz ki egy verziót a legördülőből.'); return false; }
  const label = sel.options[sel.selectedIndex].text;
  if (!confirm('Törlöd a következő verziót?\n\n' + label + '\n\nEz a művelet nem vonható vissza.')) return false;
  document.getElementById('del-verzio-id').value = vid;
  return true;
}

// ── Bulk toolbar ─────────────────────────────────────────
const toolbar      = document.getElementById('bulk-toolbar');
const bulkCountEl  = document.getElementById('bulk-count');
const checkAll     = document.getElementById('check-all');
const bulkForm     = document.getElementById('bulk-form');
const idsContainer = document.getElementById('bulk-ids-container');

function getChecked() {
  return [...document.querySelectorAll('.row-check:checked')].map(c => c.value);
}

function updateToolbar() {
  const checked = getChecked();
  toolbar.classList.toggle('lathato', checked.length > 0);
  bulkCountEl.textContent = checked.length + ' sor kijelölve';
  document.querySelectorAll('#tetel-tabla tbody tr').forEach(tr => {
    const cb = tr.querySelector('.row-check');
    if (cb) tr.classList.toggle('kivalasztott', cb.checked);
  });
}

document.querySelectorAll('.row-check').forEach(cb => cb.addEventListener('change', updateToolbar));

checkAll.addEventListener('change', function() {
  document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
  updateToolbar();
});

document.querySelectorAll('#tetel-tabla tbody tr').forEach(tr => {
  tr.addEventListener('click', function(e) {
    if (e.target.closest('input,select,button,a,form')) return;
    const cb = tr.querySelector('.row-check');
    if (cb) { cb.checked = !cb.checked; updateToolbar(); }
  });
});

function submitBulk(action) {
  const ids = getChecked();
  if (!ids.length) { alert('Nincs kijelölve sor!'); return; }
  document.getElementById('bulk-action').value = action;
  idsContainer.innerHTML = '';
  ids.forEach(id => {
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'sel[]'; inp.value = id;
    idsContainer.appendChild(inp);
  });
  bulkForm.submit();
}

function bulkMerge() {
  document.getElementById('bulk-cel-csoport').value = '';
  submitBulk('merge');
}
function bulkMergeTarget() {
  const t = document.getElementById('merge-target').value;
  if (!t) { alert('Add meg a cél csoportszámot!'); return; }
  document.getElementById('bulk-cel-csoport').value = t;
  submitBulk('merge');
}
function bulkSplit()    { submitBulk('split'); }
function bulkEgysegar() {
  const val = document.getElementById('bulk-egysegar-sel').value;
  if (!val) { alert('Válassz egységárat!'); return; }
  document.getElementById('bulk-egysegar-id').value = val;
  submitBulk('set_egysegar');
}
function clearSelection() {
  document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
  checkAll.checked = false;
  updateToolbar();
}
</script>

<!-- Modal: mentés betöltés előtt -->
<div class="modal fade" id="loadVerzioModal" tabindex="-1" aria-labelledby="loadVerzioModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title" id="loadVerzioModalLabel">Verzió betöltése</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p id="load-verzio-modal-text"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="doLoadVerzio(0)">Nem</button>
        <button type="button" class="btn btn-primary"   onclick="doLoadVerzio(1)">Igen</button>
      </div>
    </div>
  </div>
</div>
<script>
function doLoadVerzio(save) {
  bootstrap.Modal.getInstance(document.getElementById('loadVerzioModal')).hide();
  document.getElementById('save-before-load').value = save;
  document.getElementById('load-verzio-form').submit();
}
</script>
<?php require __DIR__.'/_footer.php'; ?>

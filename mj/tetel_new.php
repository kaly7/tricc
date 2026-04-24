<?php
require_once __DIR__.'/db.php';
$db = db();

$projekt_id = intval($_GET['projekt_id'] ?? $_POST['projekt_id'] ?? 0);
if (!$projekt_id) { header('Location: index.php'); exit; }

$projekt = $db->prepare('SELECT * FROM projektek WHERE id=?');
$projekt->execute([$projekt_id]);
$projekt = $projekt->fetch();
if (!$projekt) { header('Location: index.php'); exit; }

$edit_id = intval($_GET['edit'] ?? 0);
$edit = null;
if ($edit_id > 0) {
  $s = $db->prepare('SELECT * FROM tetelek WHERE id=? AND projekt_id=?');
  $s->execute([$edit_id, $projekt_id]);
  $edit = $s->fetch();
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $megnevezes    = trim($_POST['megnevezes'] ?? '');
  $gyarto        = trim($_POST['gyarto'] ?? '');
  $tipus         = trim($_POST['tipus'] ?? '');
  $rendeles_szam = trim($_POST['rendeles_szam'] ?? '');
  $mennyiseg     = floatval(str_replace(',', '.', $_POST['mennyiseg'] ?? '1'));
  $egyseg        = trim($_POST['egyseg'] ?? 'db');
  $anyagar       = floatval(str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $_POST['anyagar_egyseg'] ?? '0'));
  $munkadij      = floatval(str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $_POST['munkadij_egyseg'] ?? '0'));
  $egysegar_id   = intval($_POST['egysegar_id'] ?? 0) ?: null;
  $csoport_id    = intval($_POST['csoport_id'] ?? 1);
  $sorrend       = intval($_POST['sorrend'] ?? 0);

  if ($megnevezes === '') {
    $msg = '<div class="alert alert-danger">A megnevezés kötelező.</div>';
  } else {
    if ($edit_id > 0) {
      $db->prepare('UPDATE tetelek SET megnevezes=?,gyarto=?,tipus=?,rendeles_szam=?,mennyiseg=?,egyseg=?,anyagar_egyseg=?,munkadij_egyseg=?,egysegar_id=?,csoport_id=?,sorrend=? WHERE id=? AND projekt_id=?')
         ->execute([$megnevezes,$gyarto,$tipus,$rendeles_szam,$mennyiseg,$egyseg,$anyagar,$munkadij,$egysegar_id,$csoport_id,$sorrend,$edit_id,$projekt_id]);
    } else {
      $max = $db->prepare('SELECT COALESCE(MAX(sorrend),0) FROM tetelek WHERE projekt_id=?');
      $max->execute([$projekt_id]);
      $sorrend = $sorrend ?: ($max->fetchColumn() + 10);
      $db->prepare('INSERT INTO tetelek (projekt_id,sorrend,csoport_id,megnevezes,gyarto,tipus,rendeles_szam,mennyiseg,egyseg,anyagar_egyseg,munkadij_egyseg,egysegar_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
         ->execute([$projekt_id,$sorrend,$csoport_id,$megnevezes,$gyarto,$tipus,$rendeles_szam,$mennyiseg,$egyseg,$anyagar,$munkadij,$egysegar_id]);
    }
    header('Location: projekt.php?id='.$projekt_id);
    exit;
  }
}

$egysegarak = $db->query('SELECT id,sorsz,megnevezes,egyseg,egyseg_dij FROM egysegarak ORDER BY sorsz')->fetchAll();

$max_cs = $db->prepare('SELECT COALESCE(MAX(csoport_id),0) FROM tetelek WHERE projekt_id=?');
$max_cs->execute([$projekt_id]);
$javasolt_csoport = $edit ? $edit['csoport_id'] : ($max_cs->fetchColumn() + 1);

// Katalógus adatok JSON-ként (oldal betöltéskor)
$kat_all = $db->query('SELECT id,megnevezes,gyarto,tipus,rendeles_szam,egyseg,anyagar_egyseg,munkadij_egyseg FROM anyagar_katalogus ORDER BY megnevezes')->fetchAll();
$kat_json = json_encode($kat_all, JSON_UNESCAPED_UNICODE);
$kat_db   = count($kat_all);
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MJ – <?= $edit ? 'Tétel szerkesztése' : 'Új tétel' ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
/* Legördülő autocomplete */
#ac-lista {
  position: absolute; z-index: 1055;
  background: #fff; border: 1px solid #ced4da; border-radius: 0 0 6px 6px;
  max-height: 240px; overflow-y: auto;
  width: 100%; box-shadow: 0 6px 16px rgba(0,0,0,.15);
  display: none;
}
.ac-item { padding: 7px 12px; cursor: pointer; border-bottom: 1px solid #f2f2f2; }
.ac-item:hover, .ac-item.active { background: #e8f4ff; }
.ac-item .ac-nev { font-weight: 600; font-size: .92em; }
.ac-item .ac-meta { font-size: .80em; color: #888; margin-top:1px; }
.ac-item .ac-ar  { font-size: .82em; color: #0d6efd; }

/* Katalógus modal táblázat */
#kat-modal-tabla tbody tr { cursor: pointer; }
#kat-modal-tabla tbody tr:hover td { background: #e8f4ff; }
#kat-modal-tabla tbody tr.kat-kiv td { background: #d0eaff; font-weight:600; }
</style>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:900px">

  <div class="mb-3">
    <a href="projekt.php?id=<?= $projekt_id ?>" class="text-decoration-none text-muted small">← <?= htmlspecialchars($projekt['nev']) ?></a>
    <h5 class="mt-1"><?= $edit ? 'Tétel szerkesztése' : 'Új anyagtétel' ?></h5>
  </div>

  <?= $msg ?>

  <div class="card">
    <div class="card-body">
      <form method="post" id="tetel-form">
        <input type="hidden" name="projekt_id" value="<?= $projekt_id ?>">

        <div class="row g-3">

          <!-- Megnevezés + katalógus gomb -->
          <div class="col-12">
            <label class="form-label fw-semibold">Megnevezés <span class="text-danger">*</span></label>
            <div class="input-group position-relative">
              <div class="position-relative flex-grow-1">
                <input type="text" name="megnevezes" id="megnevezes-input" class="form-control"
                  value="<?= htmlspecialchars($edit['megnevezes'] ?? '') ?>"
                  required autofocus autocomplete="off"
                  placeholder="Írj be legalább 2 karaktert, vagy böngéssz a katalógusból…">
                <div id="ac-lista"></div>
              </div>
              <button type="button" class="btn btn-outline-primary" onclick="katModalMegnyit()"
                title="Böngészés a katalógusban">
                📋 Katalógusból
                <?php if ($kat_db > 0): ?>
                  <span class="badge bg-secondary ms-1"><?= $kat_db ?></span>
                <?php endif; ?>
              </button>
            </div>
          </div>

          <div class="col-md-4">
            <label class="form-label">Gyártó</label>
            <input type="text" name="gyarto" id="f-gyarto" class="form-control" value="<?= htmlspecialchars($edit['gyarto'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Típus</label>
            <input type="text" name="tipus" id="f-tipus" class="form-control" value="<?= htmlspecialchars($edit['tipus'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Rendelési szám</label>
            <input type="text" name="rendeles_szam" id="f-rendszam" class="form-control" value="<?= htmlspecialchars($edit['rendeles_szam'] ?? '') ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">Mennyiség</label>
            <input type="text" name="mennyiseg" class="form-control" value="<?= htmlspecialchars($edit['mennyiseg'] ?? '1') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Egység</label>
            <input type="text" name="egyseg" id="f-egyseg" class="form-control" value="<?= htmlspecialchars($edit['egyseg'] ?? 'db') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Anyagár / egység (Ft)</label>
            <input type="text" name="anyagar_egyseg" id="f-anyagar" class="form-control" value="<?= htmlspecialchars($edit['anyagar_egyseg'] ?? '0') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Munkadíj / egység (Ft)</label>
            <input type="text" name="munkadij_egyseg" id="f-munkadij" class="form-control" value="<?= htmlspecialchars($edit['munkadij_egyseg'] ?? '0') ?>">
            <div class="form-text">Valódi belső munkadíj (Munka2-stílusú).</div>
          </div>

          <div class="col-md-8">
            <label class="form-label">Szerződött tétel (munkadíj kompenzálásához)</label>
            <select name="egysegar_id" class="form-select">
              <option value="">— nincs —</option>
              <?php foreach ($egysegarak as $e): ?>
                <option value="<?= $e['id'] ?>" <?= (($edit['egysegar_id'] ?? null) == $e['id']) ? 'selected' : '' ?>>
                  [<?= $e['sorsz'] ?>] <?= htmlspecialchars($e['megnevezes']) ?> — <?= number_format($e['egyseg_dij'], 0, ',', ' ') ?> Ft
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Csoport #</label>
            <input type="number" name="csoport_id" class="form-control" value="<?= htmlspecialchars($edit['csoport_id'] ?? $javasolt_csoport) ?>" min="1">
            <div class="form-text">Azonos csoport = egy napidíj sor.</div>
          </div>
          <div class="col-md-2">
            <label class="form-label">Sorrend</label>
            <input type="number" name="sorrend" class="form-control" value="<?= htmlspecialchars($edit['sorrend'] ?? '') ?>" placeholder="auto">
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button type="submit" class="btn btn-primary"><?= $edit ? 'Mentés' : 'Hozzáadás' ?></button>
          <a href="projekt.php?id=<?= $projekt_id ?>" class="btn btn-outline-secondary">Mégsem</a>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════ -->
<!-- Katalógus modal                                -->
<!-- ══════════════════════════════════════════════ -->
<div class="modal fade" id="kat-modal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">📋 Katalógus böngésző</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-2">
        <input type="text" id="kat-kereses" class="form-control form-control-sm mb-2"
          placeholder="🔍 Szűrés megnevezés, gyártó vagy típus alapján…" autofocus>
        <div id="kat-db-info" class="text-muted small mb-2"></div>
        <div class="table-responsive" style="max-height:460px; overflow-y:auto">
          <table class="table table-sm table-hover mb-0" id="kat-modal-tabla">
            <thead class="table-dark sticky-top">
              <tr>
                <th>Megnevezés</th>
                <th>Gyártó</th>
                <th>Típus</th>
                <th>Rend.szám</th>
                <th>Egys.</th>
                <th class="text-end">Anyagár/e</th>
                <th class="text-end">Munkadíj/e</th>
              </tr>
            </thead>
            <tbody id="kat-modal-tbody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <span class="text-muted small" id="kat-kivalasztott-info">Kattints egy sorra a kiválasztáshoz.</span>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-primary" id="kat-betolt-btn" onclick="katBetolt()" disabled>✓ Betöltés a formba</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Mégsem</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Katalógus adatok ────────────────────────────────────
const KAT_DATA = <?= $kat_json ?>;
let katKivalasztott = null;
let acIndex = -1;
let acEredmenyek = [];

// ── Autocomplete (gépelés közben) ───────────────────────
const nevInput = document.getElementById('megnevezes-input');
const acLista  = document.getElementById('ac-lista');
let acTimer    = null;

nevInput.addEventListener('input', function() {
  clearTimeout(acTimer);
  const q = this.value.trim().toLowerCase();
  if (q.length < 2) { acZar(); return; }
  acTimer = setTimeout(() => acMutat(q), 180);
});

nevInput.addEventListener('keydown', function(e) {
  const items = acLista.querySelectorAll('.ac-item');
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    acIndex = Math.min(acIndex + 1, items.length - 1);
    acHighlight(items);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    acIndex = Math.max(acIndex - 1, -1);
    acHighlight(items);
  } else if (e.key === 'Enter' && acIndex >= 0) {
    e.preventDefault();
    if (acEredmenyek[acIndex]) formBetolt(acEredmenyek[acIndex]);
    acZar();
  } else if (e.key === 'Escape') {
    acZar();
  }
});

nevInput.addEventListener('blur', () => setTimeout(acZar, 200));

function acMutat(q) {
  acEredmenyek = KAT_DATA.filter(t =>
    t.megnevezes.toLowerCase().includes(q) ||
    (t.gyarto||'').toLowerCase().includes(q) ||
    (t.tipus||'').toLowerCase().includes(q)
  ).slice(0, 12);

  if (!acEredmenyek.length) { acZar(); return; }
  acIndex = -1;
  acLista.innerHTML = acEredmenyek.map((t, i) => `
    <div class="ac-item" data-i="${i}" onmousedown="formBetolt(KAT_DATA.find(x=>x.id==${t.id}));acZar()">
      <div class="ac-nev">${esc(t.megnevezes)}</div>
      <div class="ac-meta d-flex gap-3">
        ${t.gyarto ? `<span>${esc(t.gyarto)}${t.tipus ? ' / '+esc(t.tipus) : ''}</span>` : ''}
        <span class="ac-ar">Anyag: <b>${fmt(t.anyagar_egyseg)} Ft</b></span>
        <span class="ac-ar">Munkadíj: <b>${fmt(t.munkadij_egyseg)} Ft</b></span>
        <span class="text-muted">${esc(t.egyseg)}</span>
      </div>
    </div>`).join('');
  acLista.style.display = 'block';
}

function acHighlight(items) {
  items.forEach((el, i) => el.classList.toggle('active', i === acIndex));
  if (acIndex >= 0) items[acIndex]?.scrollIntoView({block:'nearest'});
}

function acZar() { acLista.style.display = 'none'; acIndex = -1; }

// ── Katalógus modal ─────────────────────────────────────
const katModal  = new bootstrap.Modal(document.getElementById('kat-modal'));
const katKerTxt = document.getElementById('kat-kereses');
const katTbody  = document.getElementById('kat-modal-tbody');
const katDbInfo = document.getElementById('kat-db-info');
const katKivInfo= document.getElementById('kat-kivalasztott-info');
const katBetBtn = document.getElementById('kat-betolt-btn');

function katModalMegnyit() {
  katKivalasztott = null;
  katBetBtn.disabled = true;
  katKivInfo.textContent = 'Kattints egy sorra a kiválasztáshoz.';
  katKerTxt.value = '';
  katSzur('');
  katModal.show();
  setTimeout(() => katKerTxt.focus(), 300);
}

katKerTxt.addEventListener('input', function() {
  katSzur(this.value.trim().toLowerCase());
});

function katSzur(q) {
  const eredmeny = q.length === 0
    ? KAT_DATA
    : KAT_DATA.filter(t =>
        t.megnevezes.toLowerCase().includes(q) ||
        (t.gyarto||'').toLowerCase().includes(q) ||
        (t.tipus||'').toLowerCase().includes(q) ||
        (t.rendeles_szam||'').toLowerCase().includes(q)
      );

  katDbInfo.textContent = `${eredmeny.length} / ${KAT_DATA.length} tétel`;

  if (!eredmeny.length) {
    katTbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Nincs találat.</td></tr>';
    return;
  }

  katTbody.innerHTML = eredmeny.map(t => `
    <tr data-id="${t.id}" onclick="katSorKivalaszt(${t.id})">
      <td>${esc(t.megnevezes)}</td>
      <td class="text-muted">${esc(t.gyarto||'')}</td>
      <td class="text-muted">${esc(t.tipus||'')}</td>
      <td class="text-muted small">${esc(t.rendeles_szam||'')}</td>
      <td>${esc(t.egyseg)}</td>
      <td class="text-end">${fmt(t.anyagar_egyseg)} Ft</td>
      <td class="text-end">${fmt(t.munkadij_egyseg)} Ft</td>
    </tr>`).join('');
}

function katSorKivalaszt(id) {
  katKivalasztott = KAT_DATA.find(t => t.id === id);
  if (!katKivalasztott) return;
  katTbody.querySelectorAll('tr').forEach(tr => tr.classList.toggle('kat-kiv', parseInt(tr.dataset.id) === id));
  katKivInfo.innerHTML = `<b>Kiválasztva:</b> ${esc(katKivalasztott.megnevezes)} — Anyag: <b>${fmt(katKivalasztott.anyagar_egyseg)} Ft</b>, Munkadíj: <b>${fmt(katKivalasztott.munkadij_egyseg)} Ft</b>`;
  katBetBtn.disabled = false;
}

function katBetolt() {
  if (!katKivalasztott) return;
  formBetolt(katKivalasztott);
  katModal.hide();
}

// ── Form kitöltés katalógus-tételből ────────────────────
function formBetolt(t) {
  document.getElementById('megnevezes-input').value = t.megnevezes || '';
  document.getElementById('f-gyarto').value         = t.gyarto     || '';
  document.getElementById('f-tipus').value          = t.tipus      || '';
  document.getElementById('f-rendszam').value       = t.rendeles_szam || '';
  document.getElementById('f-egyseg').value         = t.egyseg     || 'db';
  document.getElementById('f-anyagar').value        = t.anyagar_egyseg  || '0';
  document.getElementById('f-munkadij').value       = t.munkadij_egyseg || '0';
  // Vizuális visszajelzés
  ['f-anyagar','f-munkadij','f-egyseg'].forEach(id => {
    const el = document.getElementById(id);
    el.classList.add('border-success');
    setTimeout(() => el.classList.remove('border-success'), 1500);
  });
}

// ── Segédek ─────────────────────────────────────────────
function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmt(n) {
  return new Intl.NumberFormat('hu-HU',{maximumFractionDigits:0}).format(n||0);
}
</script>
</body>
</html>

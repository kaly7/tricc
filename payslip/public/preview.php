<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

$uploadId = (int)($_GET['upload_id'] ?? 0);
if ($uploadId <= 0) { header('Location: log.php'); exit; }

$pdo = Db::pdo();
$upload = $pdo->prepare("
    SELECT u.*, d.name AS division_name
    FROM uploads u LEFT JOIN divisions d ON d.id = u.division_id
    WHERE u.id = ?
");
$upload->execute([$uploadId]);
$upload = $upload->fetch();
if (!$upload) { header('Location: log.php'); exit; }

page_header('Teszt előnézet');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h5 mb-0">Teszt előnézet</h1>
    <div class="text-muted small"><?= h($upload['month']) ?> · <?= h($upload['division_name'] ?? '—') ?> · <?= h($upload['original_filename']) ?></div>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="log.php">Vissza</a>
</div>

<!-- Összesítő (JS tölti ki) -->
<div id="summary" class="row g-2 mb-3">
  <div class="col-12 text-muted small">Feldolgozás folyamatban…</div>
</div>

<!-- Eredmény tábla -->
<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h6 mb-0">Oldalak</h2>
    <div id="actions" class="d-flex gap-2"></div>
  </div>
  <div id="progress-bar" class="progress mb-3" style="height:6px">
    <div id="bar" class="progress-bar" style="width:0%"></div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Név (PDF-ből)</th>
          <th>Adójel</th>
          <th>HR</th>
          <th>Email</th>
          <th>Eredmény</th>
        </tr>
      </thead>
      <tbody id="rows"></tbody>
    </table>
  </div>
</div>

<script>
const uploadId = <?= (int)$uploadId ?>;

const STATUS = {
  ok:         { badge: 'success',   label: 'Küldhető' },
  no_email:   { badge: 'danger',    label: 'Nincs email' },
  no_hr:      { badge: 'warning',   label: 'Nincs HR rekord' },
  cache_only: { badge: 'info',      label: 'Csak cache' },
  no_name:    { badge: 'danger',    label: 'Nincs név' },
  no_tax_id:  { badge: 'secondary', label: 'Nincs adójel' },
};

function escHtml(s) {
  if (s == null) return '—';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function renderSummary(pages) {
  const counts = {};
  for (const p of pages) counts[p.status] = (counts[p.status] || 0) + 1;
  const total = pages.length;
  const ok = counts['ok'] || 0;
  const bad = total - ok;
  const allOk = bad === 0;

  let html = `
    <div class="col-auto"><div class="card text-center px-3 py-2 border-${allOk ? 'success' : 'primary'}">
      <div class="fs-4 fw-bold">${total}</div><div class="small text-muted">Összes oldal</div>
    </div></div>
    <div class="col-auto"><div class="card text-center px-3 py-2 border-success">
      <div class="fs-4 fw-bold text-success">${ok}</div><div class="small text-muted">Küldhető</div>
    </div></div>`;
  if (bad > 0) {
    html += `<div class="col-auto"><div class="card text-center px-3 py-2 border-danger">
      <div class="fs-4 fw-bold text-danger">${bad}</div><div class="small text-muted">Nem küldhető</div>
    </div></div>`;
  }
  for (const [st, cnt] of Object.entries(counts)) {
    if (st === 'ok') continue;
    const info = STATUS[st] || { badge: 'secondary', label: st };
    html += `<div class="col-auto"><div class="card text-center px-3 py-2">
      <div class="fs-5 fw-bold">${cnt}</div>
      <div class="small"><span class="badge bg-${info.badge}">${info.label}</span></div>
    </div></div>`;
  }
  document.getElementById('summary').innerHTML = html;
}

function renderRows(pages) {
  const tbody = document.getElementById('rows');
  let html = '';
  for (const p of pages) {
    const info = STATUS[p.status] || { badge: 'secondary', label: p.status };
    const hrCell = p.hr_found === true
      ? `<span class="badge bg-success">HR</span> <span class="small">${escHtml(p.hr_name)}</span>`
      : (p.hr_found === false ? '<span class="badge bg-warning text-dark">Nincs</span>' : '—');
    const noteTitle = p.note ? ` title="${escHtml(p.note)}"` : '';
    html += `<tr>
      <td>${p.page_no}</td>
      <td>${escHtml(p.name)}</td>
      <td class="font-monospace small">${escHtml(p.tax_id)}</td>
      <td>${hrCell}</td>
      <td class="small ${p.email ? '' : 'text-danger'}">${escHtml(p.email)}</td>
      <td${noteTitle}>
        <span class="badge bg-${info.badge}">${info.label}</span>
        ${p.note ? `<div class="text-muted small mt-1">${escHtml(p.note)}</div>` : ''}
      </td>
    </tr>`;
  }
  tbody.innerHTML = html;
}

function renderActions(done) {
  const div = document.getElementById('actions');
  div.innerHTML = done
    ? `<a class="btn btn-success" href="start.php?upload_id=${uploadId}">&#9654; Éles futtatás</a>
       <a class="btn btn-outline-secondary" href="log.php">Vissza</a>`
    : `<span class="text-muted small">Feldolgozás folyamatban…</span>`;
}

async function poll() {
  try {
    const r = await fetch(`test_progress.php?upload_id=${uploadId}&_=${Date.now()}`);
    const j = await r.json();

    if (j.error && typeof j.error === 'string' && !j.pages) {
      document.getElementById('summary').innerHTML =
        `<div class="col-12"><div class="alert alert-danger">${escHtml(j.error)}</div></div>`;
      return;
    }

    const pages = Array.isArray(j.pages) ? j.pages : [];
    const total = j.total || 0;
    const done  = j.done  || 0;

    // Progress bar
    const pct = total > 0 ? Math.round(done / total * 100) : 0;
    document.getElementById('bar').style.width = pct + '%';

    renderRows(pages);

    if (!j.running) {
      document.getElementById('progress-bar').style.display = 'none';
      renderSummary(pages);
      renderActions(true);
    } else {
      renderSummary(pages);
      renderActions(false);
      setTimeout(poll, 1200);
    }
  } catch(e) {
    setTimeout(poll, 2000);
  }
}

poll();
</script>

<?php page_footer(); ?>

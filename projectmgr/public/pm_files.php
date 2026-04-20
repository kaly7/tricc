<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/app/Activity.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers; use App\Activity;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$project_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$project_id) { http_response_code(400); exit('Hibás projekt ID'); }

// Projekt betöltése
$st = $pdo->prepare('SELECT * FROM projects WHERE id=?');
$st->execute([$project_id]);
$proj = $st->fetch(PDO::FETCH_ASSOC);
if (!$proj) { http_response_code(404); exit('Projekt nem található'); }

$cfg = require dirname(__DIR__).'/config/config.php';
$uploadRoot = rtrim($cfg['upload_root'],'/');
$clientMaxMb = isset($cfg['client_max_upload_mb']) ? (int)$cfg['client_max_upload_mb'] : 512;
$rootRel = $proj['root_dir'];
$absRoot = $uploadRoot.'/'.$rootRel;

// Utolsó könyvtár megjegyzése projektenként
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION['pm_last_dir'])) $_SESSION['pm_last_dir'] = [];
$dirParamPresent = array_key_exists('dir', $_GET);
if ($dirParamPresent) {
  $rel = (string)($_GET['dir'] ?? '');
  $_SESSION['pm_last_dir'][$project_id] = $rel;
} else {
  $rel = $_SESSION['pm_last_dir'][$project_id] ?? '';
}

// Jelenlegi relatív könyvtár biztonságos normalizálása
$rel = trim((string)$rel, '/');
$relSafe = str_replace('\\', '/', $rel);
$relSafe = preg_replace('#/+#', '/', $relSafe);
$parts = array_filter(explode('/', $relSafe), function($p){ return $p !== '' && $p !== '.' && $p !== '..'; });
$relSafe = implode('/', $parts);
$absDir = rtrim($absRoot . ($relSafe ? '/'.$relSafe : ''), '/');

// Útvonal védelme
$base = realpath($absRoot) ?: $absRoot;
$target = realpath($absDir) ?: $absDir;
if (strpos($target, $base) !== 0) {
  $relSafe = '';
  $absDir = $absRoot;
}

// Gyorsugró opciók
$dirs = [];
$g = $pdo->query('SELECT path FROM dir_templates ORDER BY sort, path')->fetchAll(PDO::FETCH_COLUMN);
$p = $pdo->prepare('SELECT path FROM project_dir_templates WHERE project_id=? ORDER BY sort, path');
$p->execute([$project_id]);
$pp = $p->fetchAll(PDO::FETCH_COLUMN);
foreach (array_merge([''], $g ?: [], $pp ?: []) as $d) { $dirs[$d]=true; }
if ($relSafe !== '' && !isset($dirs[$relSafe])) { $dirs[$relSafe] = true; }
$dirOptions = array_keys($dirs);
sort($dirOptions, SORT_NATURAL);

// Aktuális mappa beolvasása
$folders = [];
$files = [];
if (is_dir($absDir)) {
  $dh = opendir($absDir);
  if ($dh) {
    while (($entry = readdir($dh)) !== false) {
      if ($entry==='.' || $entry==='..') continue;
      $fp = $absDir.'/'.$entry;
      if (is_dir($fp)) {
        $folders[] = ['name'=>$entry, 'mtime'=> filemtime($fp)];
      } elseif (is_file($fp)) {
        $files[] = ['name'=>$entry, 'size'=>filesize($fp), 'mtime'=> filemtime($fp)];
      }
    }
    closedir($dh);
  }
}
usort($folders, function($a,$b){ return strcasecmp($a['name'],$b['name']); });
usort($files, function($a,$b){ return strcasecmp($a['name'],$b['name']); });

// DB meta a jelenlegi könyvtárhoz
$meta = $pdo->prepare('SELECT * FROM project_files WHERE project_id=? AND rel_dir=? ORDER BY filename');
$meta->execute([$project_id, $relSafe]);
$metaRows = [];
foreach ($meta->fetchAll(PDO::FETCH_ASSOC) as $m) { $metaRows[$m['filename']] = $m; }

$maxUpload = ini_get('upload_max_filesize');
$maxPost = ini_get('post_max_size');

// BreadCrumbs
$crumbs = [['label'=>'/ (gyökér)','dir'=>'']];
if ($relSafe!=='') {
  $parts = explode('/', $relSafe);
  $accum = '';
  foreach ($parts as $part) {
    $accum = trim($accum.'/'.$part,'/');
    $crumbs[] = ['label'=>$part, 'dir'=>$accum];
  }
}
?>
<style>
#dropZone {
  border: 2px dashed #adb5bd;
  border-radius: .5rem;
  padding: 1rem;
  text-align: center;
  transition: .15s ease-in-out;
}
#dropZone.dragover { background: #f1f3f5; border-color: #0d6efd; }
#uploadList .progress { height: 14px; }
.folder-row { background: #f8f9fa; }
</style>

<!-- Összegzés modal -->
<div class="modal fade" id="uploadSummaryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Feltöltés összegzés</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bezárás"></button>
      </div>
      <div class="modal-body">
        <div id="summaryOk" class="text-success mb-2"></div>
        <div id="summaryErr" class="text-danger"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal" id="uploadSummaryOkBtn">OK</button>
      </div>
    </div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Fájlok – <?= htmlspecialchars($proj['number'].' — '.$proj['name']) ?></h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/pm_project_edit.php?id=<?= (int)$project_id ?>">Vissza a projekthez</a>
    <a class="btn btn-outline-primary" href="/pm_project_log.php?id=<?= (int)$project_id ?>">Projekt napló</a>
  </div>
</div>

<!-- Breadcrumb + mappa műveletek -->
<div class="card p-3 mb-3">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div>
      <?php foreach($crumbs as $i=>$c): ?>
        <?php if ($i>0): ?> / <?php endif; ?>
        <a href="/pm_files.php?id=<?= (int)$project_id ?>&dir=<?= urlencode($c['dir']) ?>" class="text-decoration-none"><?= htmlspecialchars($c['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="d-flex gap-2">
      <form method="post" action="/pm_dir_action.php" class="d-inline-flex gap-2">
        <?= \App\Csrf::field() ?>
        <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
        <input type="hidden" name="cur" value="<?= htmlspecialchars($relSafe) ?>">
        <input type="hidden" name="op" value="mkdir">
        <input type="text" name="name" class="form-control form-control-sm" placeholder="Új mappa neve" required>
        <button class="btn btn-sm btn-outline-primary">Új mappa</button>
      </form>
      <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#renameModal" <?= $relSafe===''?'disabled':'' ?>>Átnevezés</button>
      <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal" <?= $relSafe===''?'disabled':'' ?>>Törlés</button>
    </div>
  </div>
</div>

<!-- Rename modal -->
<div class="modal fade" id="renameModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="/pm_dir_action.php" class="modal-content">
      <?= \App\Csrf::field() ?>
      <input type="hidden" name="op" value="rename">
      <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
      <input type="hidden" name="cur" value="<?= htmlspecialchars($relSafe) ?>">
      <div class="modal-header">
        <h5 class="modal-title">Könyvtár átnevezése</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bezárás"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Új név</label>
        <input type="text" name="newname" class="form-control" required>
        <div class="form-text">Engedélyezett: betűk, számok, szóköz, ., -, _ (max. 128 karakter)</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
        <button class="btn btn-primary">Mentés</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" action="/pm_dir_action.php" class="modal-content">
      <?= \App\Csrf::field() ?>
      <input type="hidden" name="op" value="rmdir">
      <input type="hidden" name="project_id" value="<?= (int)$project_id ?>">
      <input type="hidden" name="cur" value="<?= htmlspecialchars($relSafe) ?>">
      <div class="modal-header">
        <h5 class="modal-title">Könyvtár törlése</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bezárás"></button>
      </div>
      <div class="modal-body">Csak <strong>üres</strong> mappa törölhető.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
        <button class="btn btn-danger">Törlés</button>
      </div>
    </form>
  </div>
</div>

<!-- Többfájlos feltöltés -->
<div class="card p-3 mb-3">
  <h2 class="h6">Feltöltés ebbe a könyvtárba: <?= $relSafe===''?'/':htmlspecialchars('/'.$relSafe) ?></h2>
  <p class="text-muted small m-0">Szerver korlátok: egy fájl max: <strong><?= htmlspecialchars($maxUpload) ?></strong>, összesen: <strong><?= htmlspecialchars($maxPost) ?></strong>.</p>
  <p class="text-muted small">Kliens figyelmeztetési küszöb: <strong><?= (int)$clientMaxMb ?> MB</strong> (állítható a <code>config/config.php</code>-ban <code>client_max_upload_mb</code>).</p>

  <div id="dropZone" class="mb-2">
    <div class="mb-2">Húzd ide a fájlokat, vagy válaszd ki:</div>
    <input type="file" id="fileInput" multiple class="form-control mb-2">
    <input type="text" id="descInput" class="form-control" placeholder="Közös leírás minden fájlhoz (opcionális)">
    <div class="d-grid mt-2">
      <button id="startUpload" class="btn btn-primary">Feltöltés indítása</button>
    </div>
  </div>

  <div id="uploadList" class="list-group"></div>
</div>

<!-- Lista -->
<div class="card p-0">
  <table class="table table-striped m-0 align-middle">
    <thead><tr>
      <th>Név</th><th class="text-center" style="width:120px">Típus</th><th style="width:160px">Méret</th><th style="width:180px">Módosítva</th><th>Leírás</th><th style="width:220px"></th>
    </tr></thead>
    <tbody>
      <?php foreach($folders as $d): ?>
        <tr class="folder-row">
          <td>
            <a href="/pm_files.php?id=<?= (int)$project_id ?>&dir=<?= urlencode(trim($relSafe.'/'.$d['name'],'/')) ?>" class="text-decoration-none">📁 <?= htmlspecialchars($d['name']) ?></a>
          </td>
          <td class="text-center">Mappa</td>
          <td class="text-muted">—</td>
          <td><?= date('Y-m-d H:i', $d['mtime']) ?></td>
          <td class="text-muted">—</td>
          <td class="text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="/pm_files.php?id=<?= (int)$project_id ?>&dir=<?= urlencode(trim($relSafe.'/'.$d['name'],'/')) ?>">Megnyit</a>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php foreach($files as $f):
        $m = $metaRows[$f['name']] ?? null; ?>
        <tr>
          <td><?= htmlspecialchars($f['name']) ?></td>
          <td class="text-center">Fájl</td>
          <td><span class="fsize" data-bytes="<?= (int)$f['size'] ?>" title="<?= (int)$f['size'] ?> bytes"><?= number_format($f['size'], 0, '.', ' ') ?> B</span></td>
          <td><?= date('Y-m-d H:i', $f['mtime']) ?></td>
          <td><?= htmlspecialchars($m['description'] ?? '') ?></td>
          <td class="text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="/pm_file_download.php?id=<?= (int)$project_id ?>&dir=<?= urlencode($relSafe) ?>&name=<?= urlencode($f['name']) ?>">Letöltés</a>
            <form method="post" action="/pm_file_delete.php" class="d-inline" onsubmit="return confirm('Törlöd a fájlt?');">
              <?= \App\Csrf::field() ?>
              <input type="hidden" name="id" value="<?= (int)$project_id ?>">
              <input type="hidden" name="dir" value="<?= htmlspecialchars($relSafe) ?>">
              <input type="hidden" name="name" value="<?= htmlspecialchars($f['name']) ?>">
              <button class="btn btn-sm btn-outline-danger">Törlés</button>
            </form>
          </td>
        </tr>
      <?php endforeach; if (!$folders && !$files): ?>
        <tr><td colspan="6" class="text-muted">Ez a mappa üres.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
(function(){
  // Méret formázás + tooltip
  function fmtSize(bytes){
    bytes = Number(bytes || 0);
    if (bytes < 1024) return bytes + ' B';
    const units = ['KB','MB','GB','TB','PB'];
    let i = -1;
    do { bytes = bytes/1024; i++; } while (bytes >= 1024 && i < units.length-1);
    return bytes.toFixed(bytes < 10 ? 1 : 0) + ' ' + units[i];
  }
  document.querySelectorAll('.fsize').forEach(function(el){
    const b = el.getAttribute('data-bytes');
    if (!b) return;
    el.textContent = fmtSize(b);
    el.setAttribute('title', b + ' bytes');
    el.setAttribute('data-bs-toggle','tooltip');
  });
  if (window.bootstrap && bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
      new bootstrap.Tooltip(el);
    });
  }

  const clientMax = <?= (int)$clientMaxMb ?> * 1024 * 1024;
  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('fileInput');
  const descInput = document.getElementById('descInput');
  const startBtn  = document.getElementById('startUpload');
  const list      = document.getElementById('uploadList');

  // Drag & drop
  ['dragenter','dragover'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dropZone.classList.add('dragover'); }));
  ['dragleave','drop'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dropZone.classList.remove('dragover'); }));
  dropZone.addEventListener('drop', function(e){
    const files = e.dataTransfer.files;
    if (files && files.length) {
      fileInput.files = files;
      previewList(files);
    }
  });

  // Preview selected files
  function previewList(files){
    list.innerHTML = '';
    Array.from(files).forEach((f, idx) => {
      const id = 'u_'+idx+'_'+Date.now();
      const row = document.createElement('div');
      row.className = 'list-group-item';
      row.innerHTML = `
        <div class="d-flex justify-content-between align-items-center">
          <div class="me-2">
            <div class="fw-semibold">${escapeHtml(f.name)}</div>
            <div class="text-muted small">${fmtSize(f.size)}</div>
          </div>
          <div class="flex-grow-1 ms-3">
            <div class="progress">
              <div class="progress-bar" id="${id}_bar" style="width:0%">0%</div>
            </div>
            <div class="small mt-1" id="${id}_info">Várakozik…</div>
          </div>
        </div>`;
      row.dataset.uid = id;
      list.appendChild(row);
    });
  }

  fileInput.addEventListener('change', function(){ previewList(fileInput.files); });

  function escapeHtml(s){
    return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function setRowProgress(uid, p, text){
    const bar = document.getElementById(uid+'_bar');
    const info = document.getElementById(uid+'_info');
    if (bar){ bar.style.width = p+'%'; bar.textContent = p+'%'; }
    if (info){ info.textContent = text || ''; }
  }

  function uploadSingle(uid, file, commonDesc){
    return new Promise((resolve) => {
      if (clientMax > 0 && file.size > clientMax) {
        setRowProgress(uid, 0, 'Túl nagy fájl a kliens küszöbhöz: '+fmtSize(file.size));
        resolve({ok:false, name:file.name, error:'Kliens limit túllépve'});
        return;
      }
      const fd = new FormData();
      fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
      fd.append('project_id', <?= (int)$project_id ?>);
      fd.append('dir', '<?= htmlspecialchars($relSafe, ENT_QUOTES) ?>');
      fd.append('description', commonDesc || '');
      fd.append('file', file);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', '/file_upload_ajax.php', true);
      xhr.upload.onprogress = function(e){
        if (e.lengthComputable) {
          const p = Math.round(e.loaded * 100 / e.total);
          setRowProgress(uid, p, 'Feltöltve: '+fmtSize(e.loaded)+' / '+fmtSize(e.total));
        } else {
          setRowProgress(uid, 0, 'Feltöltés folyamatban…');
        }
      };
      xhr.onreadystatechange = function(){
        if (xhr.readyState === 4) {
          let res = {};
          try { res = JSON.parse(xhr.responseText || '{}'); } catch(e){}
          if (xhr.status === 200 && res.ok) {
            setRowProgress(uid, 100, 'Kész');
            resolve({ok:true, name:file.name});
          } else {
            setRowProgress(uid, 0, res.error || ('Hiba: '+xhr.status));
            resolve({ok:false, name:file.name, error:res.error || ('HTTP '+xhr.status)});
          }
        }
      };
      xhr.onerror = function(){
        setRowProgress(uid, 0, 'Hálózati hiba');
        resolve({ok:false, name:file.name, error:'Hálózati hiba'});
      };
      xhr.send(fd);
    });
  }

  startBtn.addEventListener('click', async function(){
    const files = fileInput.files;
    if (!files || !files.length){ alert('Válassz ki legalább egy fájlt.'); return; }
    const desc = descInput.value || '';

    const results = [];
    for (let i=0;i<files.length;i++){
      const uid = list.children[i]?.dataset?.uid;
      if (!uid) continue;
      const r = await uploadSingle(uid, files[i], desc);
      results.push(r);
    }

    const ok = results.filter(r => r.ok).map(r => '• '+r.name);
    const er = results.filter(r => !r.ok).map(r => '• '+r.name+' — '+r.error);
    document.getElementById('summaryOk').innerHTML = ok.length ? ('<strong>Sikeres feltöltések:</strong><br>'+ok.join('<br>')) : '<em>Nincs sikeres feltöltés.</em>';
    document.getElementById('summaryErr').innerHTML = er.length ? ('<strong>Sikertelenek:</strong><br>'+er.join('<br>')) : '';

    if (window.bootstrap && bootstrap.Modal) {
      const mm = bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadSummaryModal'));
      document.getElementById('uploadSummaryModal').addEventListener('hidden.bs.modal', function(){
        window.location = "/pm_files.php?id=<?= (int)$project_id ?>&dir=<?= urlencode($relSafe) ?>";
      }, {once:true});
      mm.show();
    } else {
      alert('Feltöltés kész. Az oldal frissül.');
      window.location = "/pm_files.php?id=<?= (int)$project_id ?>&dir=<?= urlencode($relSafe) ?>";
    }
  });
})();
</script>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>

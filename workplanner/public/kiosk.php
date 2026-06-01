<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/db.php';

// Kiosk beállítások a config táblából
function kiosk_cfg(string $key, string $default): string {
  try {
    $st = db()->prepare("SELECT v FROM config WHERE k=?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v !== false) ? (string)$v : $default;
  } catch (Throwable) { return $default; }
}

// IP alapú hozzáférés ellenőrzése
function kiosk_ip_allowed(): bool {
  $raw     = kiosk_cfg('kiosk_allowed_ips', '[]');
  $allowed = json_decode($raw, true);
  if (!is_array($allowed) || empty($allowed)) return false;
  $client  = $_SERVER['REMOTE_ADDR'] ?? '';
  return in_array($client, $allowed, true);
}

if (!kiosk_ip_allowed()) {
  require_once __DIR__ . '/../app/auth.php';
  require_login();
}

$kioskDays    = max(1, (int)kiosk_cfg('kiosk_days', '5'));
$kioskZoom    = max(0.5, min(2.0, (float)kiosk_cfg('kiosk_zoom', '1.00')));
$kioskRefresh = max(10, (int)kiosk_cfg('kiosk_refresh', '30'));
$kioskReload  = max(60, (int)kiosk_cfg('kiosk_reload', '1800'));

$today   = date('Y-m-d');
$days    = work_days($today, $kioskDays);
$tasks   = get_tasks_for_days($days);
$taskIdx = index_tasks($tasks);

// Kiosk: mindig az összes felvett dolgozó
$shownEmps = get_employees();
$n         = max(1, count($shownEmps));
$lastMod   = get_last_modified();

$hunDays   = ['','Hétfő','Kedd','Szerda','Csütörtök','Péntek','Szombat','Vasárnap'];
$hunMonths = ['','január','február','március','április','május','június','július','augusztus','szeptember','október','november','december'];
?>
<!doctype html>
<html lang="hu" style="zoom:<?= number_format($kioskZoom, 2, '.', '') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Napiterv – Kiosk</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; overflow: hidden; background: #f8f9fa; color: #212529;
        font-family: system-ui, sans-serif; }

    /* Fejléc */
    .k-hdr  { background: #fff; border-bottom: 1px solid #dee2e6; height: 50px;
        padding: 0 20px; display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; }
    .k-title { font-size: 1.05rem; font-weight: 700; }
    .k-clock { font-size: 1.6rem; font-weight: 700; color: #0d6efd; font-variant-numeric: tabular-nums; }
    .k-date  { font-size: .70rem; color: #6c757d; text-align: right; }
    .k-lastmod { text-align: center; color: #6c757d; line-height: 1.25; }
    .k-lastmod-lbl  { font-size: .68rem; }
    .k-lastmod-time { font-size: .85rem; font-weight: 600; color: #212529; }
    .k-lastmod-ago  { font-size: .68rem; }

    /* Tábla wrapper – rögzített cellákhoz görgethető */
    .k-wrap  { height: calc(100vh - 50px); overflow-y: auto; overflow-x: hidden; background: #ced4da; }

    /* Tábla: a wrapper magasságát veszi át */
    .k-table { border-collapse: separate; border-spacing: 2px; width: 100%;
        height: 100%; table-layout: fixed; background: #ced4da; }
    .k-table th, .k-table td { border: none; padding: 0; }

    /* Fejléc sor */
    .k-table thead th { background: #343a40; color: #fff; text-align: center;
        font-size: .76rem; font-weight: 600; white-space: nowrap; padding: 4px 6px;
        position: sticky; top: 0; z-index: 2; }
    .k-table thead th:first-child { position: sticky; left: 0; top: 0; z-index: 4;
        text-align: left; background: #343a40; }
    .k-table thead th.k-today { background: #0d6efd; }
    .k-table thead th.k-we    { background: #495057; }

    /* CSS változók – JS csak a font-méreteket állítja be, a cell-magasság rögzített */
    :root {
      --k-cell-h: 90px;
      --k-task-fs: .80rem;
      --k-time-fs: .72rem;
      --k-loc-fs:  .68rem;
      --k-emp-fs:  .80rem;
      --k-emp-sub-fs: .65rem;
      --k-hdr-fs:  .76rem;
    }

    /* Névsáv */
    .k-emp  { background: #f8f9fa; font-size: var(--k-emp-fs); font-weight: 600; padding: 4px 10px;
        overflow: hidden; word-break: break-word; line-height: 1.25;
        position: sticky; left: 0; z-index: 1; border-right: 2px solid #adb5bd;
        vertical-align: middle; height: var(--k-cell-h); }
    .k-emp small { display: block; color: #6c757d; font-weight: 400; font-size: var(--k-emp-sub-fs); line-height: 1.2; }

    /* Nap cellák: rögzített magasság (--k-cell-h: 90px, CSS :root-ban) */
    .k-cell { background: #fff; vertical-align: top;
        padding: 7px 5px;
        height: var(--k-cell-h); }
    .k-cell.k-today { background: #eff6ff; }

    /* Váltakozó sávszínek */
    .k-table tbody tr:nth-child(even) .k-emp        { background: #e2e8f0; }
    .k-table tbody tr:nth-child(even) .k-cell       { background: #f1f5f9; }
    .k-table tbody tr:nth-child(even) .k-cell.k-today { background: #dbeafe; }

    /* Fejléc betűméret */
    .k-table thead th { font-size: var(--k-hdr-fs) !important; }

    /* Feladat sávok – flex elosztás */
    .k-cell-flex { display: flex; flex-direction: column; height: 95%; gap: 3px; }
    .k-task { flex: 1; min-height: 0; border-radius: 6px; padding: 4px 12px;
        display: flex; align-items: center; gap: 10px; overflow: hidden; white-space: nowrap;
        font-size: var(--k-task-fs); box-shadow: 0 1px 4px rgba(0,0,0,.15); cursor: default; position: relative; }
    .k-task.overlap::after { content: ''; position: absolute; inset: 0; border-radius: inherit;
        background: repeating-linear-gradient(45deg,
          transparent 0px, transparent 5px,
          rgba(0,0,0,.18) 5px, rgba(0,0,0,.18) 7px);
        pointer-events: none; }
    .k-task-time  { font-weight: 700; font-size: .84em; flex-shrink: 0; opacity: .9; }
    .k-task-title { overflow: hidden; text-overflow: ellipsis; font-weight: 600; min-width: 0; }
    .k-task-loc   { font-size: .76em; opacity: .8; flex-shrink: 0; }
    /* Egyetlen feladat a cellában: nagyobb betű, törhet a szöveg */
    .k-cell-flex .k-task:only-child { font-size: calc(var(--k-task-fs) * 1.25); white-space: normal; align-items: flex-start; padding: 6px 12px; }
    .k-cell-flex .k-task:only-child .k-task-title { white-space: normal; overflow: visible; text-overflow: unset; line-height: 1.3; }
    .k-task.task-vacation {
        background: linear-gradient(135deg, #fde68a 0%, #fb923c 100%) !important;
        color: #7c2d12 !important; font-weight: 700;
    }
    .k-task.task-sick {
        background: linear-gradient(135deg, #e2e8f0 0%, #94a3b8 100%) !important;
        color: #1e293b !important;
    }
    .k-task.task-passive::after {
        content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
        background: repeating-linear-gradient(-45deg,
          transparent 0px, transparent 4px,
          rgba(0,0,0,.20) 4px, rgba(0,0,0,.20) 6px);
    }
    .k-task.task-waiting { padding-left: 18px !important; }
    .k-task.task-waiting::after {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0;
        width: 13px; border-radius: 5px 0 0 5px; pointer-events: none;
        background: repeating-linear-gradient(-45deg,
          #fbbf24 0px, #fbbf24 5px,
          #dc2626 5px, #dc2626 10px);
    }
    .k-task.task-archived { opacity: .72; }
    .k-task.task-archived .k-task-title { text-decoration: line-through; }

    /* Frissítés sáv */
    .k-rbar { position: fixed; bottom: 0; left: 0; right: 0; height: 3px;
        background: #0d6efd; transform-origin: left;
        animation: k-shrink <?= $kioskReload ?>s linear forwards; }
    @keyframes k-shrink { from { transform: scaleX(1); } to { transform: scaleX(0); } }
  </style>
</head>
<body>

<div class="k-hdr">
  <div style="display:flex;align-items:center;gap:10px">
    <div class="k-title">📅 Napiterv</div>
    <!-- button onclick="showDebug()" style="font-size:.65rem;padding:2px 7px;cursor:pointer;border:1px solid #adb5bd;border-radius:4px;background:#e9ecef;color:#495057">🔍 debug</button>
    <button onclick="location.reload()" style="font-size:.65rem;padding:2px 7px;cursor:pointer;border:1px solid #adb5bd;border-radius:4px;background:#e9ecef;color:#495057">🔄 frissít</button -->
  </div>
  <div class="k-lastmod">
    <div class="k-lastmod-lbl">Utolsó módosítás</div>
    <div class="k-lastmod-time" id="klastmod-time">—</div>
    <div class="k-lastmod-ago"  id="klastmod-ago"></div>
  </div>
  <div style="text-align:right">
    <div class="k-clock" id="kclock">--:--</div>
    <div class="k-date" id="kdate"></div>
  </div>
</div>

<div class="k-wrap">
<table class="k-table">
  <colgroup>
    <col id="col-name" style="width:170px">
    <?php foreach ($days as $_): ?>
    <col>
    <?php endforeach; ?>
  </colgroup>
  <thead>
    <tr>
      <th id="hdr-name">Dolgozó</th>
      <?php foreach ($days as $d):
        $dow     = (int)(new DateTime($d))->format('N');
        $isToday = ($d === $today);
        $isWe    = $dow >= 6;
        $dm      = explode('-', $d);
        $label   = $hunMonths[(int)$dm[1]].' '.(int)$dm[2].'. '.$hunDays[$dow];
      ?>
      <th class="<?= $isToday ? 'k-today' : ($isWe ? 'k-we' : '') ?>"><?= e($label) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($shownEmps as $emp):
      $eid = (int)$emp['id'];
    ?>
    <tr>
      <td class="k-emp">
        <?= e($emp['full_name']) ?>
        <?php if ($emp['company_division']): ?>
          <small><?= e($emp['company_division']) ?></small>
        <?php endif; ?>
      </td>
      <?php foreach ($days as $d):
        $isToday   = ($d === $today);
        $cellTasks = $taskIdx[$d][$eid] ?? [];
      ?>
      <td class="k-cell<?= $isToday ? ' k-today' : '' ?>">
        <div class="k-cell-flex">
          <?php foreach ($cellTasks as $t):
            $isArchiv = ($t['status'] === 'archív');
            $sysKey   = $t['system_key'] ?? '';
            $sysCls   = match($sysKey) {
              'vacation'  => ' task-vacation',
              'sick_leave'=> ' task-sick',
              default     => '',
            };
            $emoji = match($sysKey) {
              'vacation'  => '🌴 ',
              'sick_leave'=> '🤒 ',
              default     => '',
            };
            $statusCls = match($t['status']) {
              'passzív' => ' task-passive',
              'vár'     => ' task-waiting',
              'archív'  => ' task-archived',
              default   => '',
            };
            $bg  = $isArchiv ? '#9ca3af' : e($t['color']);
            $fg  = $isArchiv ? '#ffffff'  : e(contrast_color($t['color']));
          ?>
          <div class="k-task<?= $sysCls . $statusCls ?>" style="background:<?= $bg ?>;color:<?= $fg ?>">
            <span class="k-task-title"><?= $emoji . e($t['title']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="k-rbar"></div>

<script>
let LAST_MOD      = <?= $lastMod ?>;
let LAST_MOD_DATE = <?= json_encode(get_last_modified_date()) ?>;
const KIOSK_DAYS  = <?= json_encode($days) ?>;

function tick() {
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  document.getElementById('kclock').textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
  document.getElementById('kdate').textContent  = now.toLocaleDateString('hu-HU',
    {year:'numeric', month:'long', day:'numeric', weekday:'long'});
}
tick(); setInterval(tick, 1000);

function updateLastMod() {
  if (!LAST_MOD) return;
  const d       = new Date(LAST_MOD * 1000);
  const hhmm    = d.toLocaleTimeString('hu-HU', {hour: '2-digit', minute: '2-digit'});
  const diffMin = Math.round((Date.now() - d) / 60000);
  const ago     = diffMin < 1  ? 'éppen most' :
                  diffMin < 60 ? diffMin + ' perccel ezelőtt' :
                  Math.floor(diffMin / 60) + ' órával ezelőtt';
  const onPage  = !LAST_MOD_DATE || KIOSK_DAYS.includes(LAST_MOD_DATE);
  document.getElementById('klastmod-time').textContent = hhmm;
  document.getElementById('klastmod-ago').textContent  =
    onPage ? ago : ago + ' · más lapon';
}
updateLastMod();
setInterval(updateLastMod, 30000);

const N_EMPS = <?= $n ?>;

function fitFonts() {
  const R = document.documentElement;

  // 1. Fejléc dátum betűméret (előbb fut, hogy helyes thead-magasságot mérjünk utána)
  const thDate = document.querySelector('.k-table thead th:nth-child(2)');
  if (thDate) {
    const r = thDate.getBoundingClientRect();
    R.style.setProperty('--k-hdr-fs',
      Math.max(8, Math.min(r.width / 10, r.height * 0.65, 24)).toFixed(1) + 'px');
  }

  // 2. Cella magasság: rögzített CSS változóból olvasva (nem dinamikus)
  const cellH = parseInt(getComputedStyle(R).getPropertyValue('--k-cell-h')) || 90;

  // 3. Feladat sávok: minden téglalaphoz egyedi optimális betűméret
  const cvs = document.createElement('canvas').getContext('2d');
  document.querySelectorAll('.k-task').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.height < 6 || r.width < 30) return;

    const availH = r.height;
    const availW = r.width - 28; // padding (12px × 2) + gap tartalék

    const timeEl  = el.querySelector('.k-task-time');
    const titleEl = el.querySelector('.k-task-title');
    const locEl   = el.querySelector('.k-task-loc');
    const text = [
      timeEl  ? timeEl.textContent.trim()  : '',
      titleEl ? titleEl.textContent.trim() : '',
      locEl   ? '· ' + locEl.textContent.trim() : '',
    ].filter(Boolean).join('  ');

    let lo = 9, hi = Math.min(availH * 0.68, 48);
    while (hi - lo > 0.4) {
      const mid = (lo + hi) / 2;
      cvs.font = `600 ${mid}px system-ui,sans-serif`;
      if (cvs.measureText(text).width <= availW) lo = mid; else hi = mid;
    }
    el.style.fontSize = Math.max(9, lo).toFixed(1) + 'px';
  });

  // 4. Dolgozó névsáv: canvas-alapú bináris keresés
  const empCells = document.querySelectorAll('td.k-emp');
  if (empCells.length) {
    const LH = 1.25;

    const names = Array.from(empCells).map(cell => {
      const small = cell.querySelector('small');
      return small ? cell.textContent.replace(small.textContent, '').trim()
                   : cell.textContent.trim();
    });
    const subs = Array.from(empCells).map(cell => {
      const s = cell.querySelector('small');
      return s ? s.textContent.trim() : '';
    });

    const cvs2 = document.createElement('canvas').getContext('2d');
    function textW(txt, fs, weight) {
      cvs2.font = `${weight} ${fs}px system-ui,sans-serif`;
      return cvs2.measureText(txt).width;
    }

    const availH  = cellH - 8;  // cellH a 2. lépésből – nem DOM-mérés, elkerüli a körkörös függőséget
    const maxColW = Math.min(Math.round(window.innerWidth * 0.30), 260);

    function fitsInCol(fs, colW) {
      const aW = colW - 20;
      return names.every((name, i) => {
        const nameLines = Math.ceil(textW(name, fs, '600') / aW);
        if (nameLines > 2) return false;
        const subLines = subs[i] ? Math.ceil(textW(subs[i], fs * 0.78, '400') / aW) : 0;
        if (subLines > 1) return false;
        return (nameLines + subLines) * fs * LH <= availH;
      });
    }

    let colW = 170;
    let lo = 8, hi = 26;
    while (hi - lo > 0.4) {
      const mid = (lo + hi) / 2;
      if (fitsInCol(mid, colW)) lo = mid; else hi = mid;
    }
    if (lo <= 8.4) {
      for (let w = 190; w <= maxColW; w += 10) {
        let lo2 = 8, hi2 = 26;
        while (hi2 - lo2 > 0.4) {
          const mid = (lo2 + hi2) / 2;
          if (fitsInCol(mid, w)) lo2 = mid; else hi2 = mid;
        }
        if (lo2 > 8.4) { colW = w; lo = lo2; break; }
      }
    }

    const nameCol = document.getElementById('col-name');
    const hdrName = document.getElementById('hdr-name');
    if (nameCol) nameCol.style.width = colW + 'px';
    if (hdrName) hdrName.style.width = colW + 'px';

    const empFs = Math.max(8, lo);
    R.style.setProperty('--k-emp-fs',     empFs.toFixed(1) + 'px');
    R.style.setProperty('--k-emp-sub-fs', Math.max(7, empFs * 0.78).toFixed(1) + 'px');
  }
}

// requestAnimationFrame: garantálja, hogy a DOM teljesen renderelt legyen (Chromiumban fontos)
window.addEventListener('load', () => requestAnimationFrame(fitFonts));
window.addEventListener('resize', fitFonts);

function showDebug() {
  const wrapEl  = document.querySelector('.k-wrap');
  const theadEl = document.querySelector('.k-table thead');
  const hdrEl   = document.querySelector('.k-hdr');
  const cs      = getComputedStyle(document.documentElement);
  const lines = [
    '=== KIOSK DEBUG ===',
    'window.innerHeight:       ' + window.innerHeight,
    'window.innerWidth:        ' + window.innerWidth,
    'doc.documentElement.clientHeight: ' + document.documentElement.clientHeight,
    'doc.documentElement.clientWidth:  ' + document.documentElement.clientWidth,
    'devicePixelRatio:         ' + window.devicePixelRatio,
    '',
    '.k-hdr  offsetHeight:     ' + (hdrEl  ? hdrEl.offsetHeight  : 'N/A'),
    '.k-hdr  clientHeight:     ' + (hdrEl  ? hdrEl.clientHeight  : 'N/A'),
    '.k-hdr  getBCR().height:  ' + (hdrEl  ? hdrEl.getBoundingClientRect().height.toFixed(2) : 'N/A'),
    '',
    '.k-wrap offsetHeight:     ' + (wrapEl ? wrapEl.offsetHeight : 'N/A'),
    '.k-wrap clientHeight:     ' + (wrapEl ? wrapEl.clientHeight : 'N/A'),
    '.k-wrap getBCR().height:  ' + (wrapEl ? wrapEl.getBoundingClientRect().height.toFixed(2) : 'N/A'),
    '',
    'thead   offsetHeight:     ' + (theadEl ? theadEl.offsetHeight : 'N/A'),
    'thead   clientHeight:     ' + (theadEl ? theadEl.clientHeight : 'N/A'),
    'thead   getBCR().height:  ' + (theadEl ? theadEl.getBoundingClientRect().height.toFixed(2) : 'N/A'),
    '',
    'N_EMPS:                   ' + N_EMPS,
    '--k-cell-h (CSS var):     ' + cs.getPropertyValue('--k-cell-h').trim(),
    '--k-emp-fs (CSS var):     ' + cs.getPropertyValue('--k-emp-fs').trim(),
    '--k-hdr-fs (CSS var):     ' + cs.getPropertyValue('--k-hdr-fs').trim(),
    '',
    'html zoom style:          ' + (document.documentElement.style.zoom || '(nincs)'),
    'userAgent:                ' + navigator.userAgent,
  ];
  alert(lines.join('\n'));
}

setInterval(() => {
  fetch('kiosk_check.php').then(r => r.json()).then(d => {
    if (d.last_modified > LAST_MOD) {
      LAST_MOD      = d.last_modified;
      LAST_MOD_DATE = d.last_modified_date || '';
      updateLastMod();
      location.reload();
    }
  }).catch(() => {});
}, <?= $kioskRefresh * 1000 ?>);

setTimeout(() => location.reload(), <?= $kioskReload * 1000 ?>);
</script>
</body>
</html>

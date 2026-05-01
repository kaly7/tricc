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
        padding: 0 20px; display: flex; justify-content: space-between; align-items: center; }
    .k-title { font-size: 1.05rem; font-weight: 700; }
    .k-clock { font-size: 1.6rem; font-weight: 700; color: #0d6efd; font-variant-numeric: tabular-nums; }
    .k-date  { font-size: .70rem; color: #6c757d; text-align: right; }

    /* Tábla wrapper */
    .k-wrap  { height: calc(100vh - 50px); overflow: hidden; background: #ced4da; }

    /* Tábla: kitölti a maradék magasságot */
    .k-table { border-collapse: separate; border-spacing: 2px; width: 100%;
        height: calc(100vh - 50px); table-layout: fixed; background: #ced4da; }
    .k-table th, .k-table td { border: none; padding: 0; }

    /* Fejléc sor */
    .k-table thead th { background: #343a40; color: #fff; text-align: center;
        font-size: .76rem; font-weight: 600; white-space: nowrap; padding: 4px 6px;
        position: sticky; top: 0; z-index: 2; }
    .k-table thead th:first-child { position: sticky; left: 0; top: 0; z-index: 4;
        text-align: left; background: #343a40; }
    .k-table thead th.k-today { background: #0d6efd; }
    .k-table thead th.k-we    { background: #495057; }

    /* CSS változók – JS állítja be renderelés után */
    :root {
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
        vertical-align: middle; width: 170px; min-width: 140px; }
    .k-emp small { display: block; color: #6c757d; font-weight: 400; font-size: var(--k-emp-sub-fs); line-height: 1.2; }

    /* Nap cellák: egyenlő magasság, kitölti a képernyőt */
    .k-cell { background: #fff; vertical-align: top;
        padding: 7px 5px;
        height: calc((100vh - 82px) / <?= $n ?>); }
    .k-cell.k-today { background: #eff6ff; }

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
    .k-task-title { overflow: hidden; text-overflow: ellipsis; font-weight: 600; }
    .k-task-loc   { font-size: .76em; opacity: .8; flex-shrink: 0; }

    /* Frissítés sáv */
    .k-rbar { position: fixed; bottom: 0; left: 0; right: 0; height: 3px;
        background: #0d6efd; transform-origin: left;
        animation: k-shrink <?= $kioskReload ?>s linear forwards; }
    @keyframes k-shrink { from { transform: scaleX(1); } to { transform: scaleX(0); } }
  </style>
</head>
<body>

<div class="k-hdr">
  <div class="k-title">📅 Napiterv</div>
  <div>
    <div class="k-clock" id="kclock">--:--</div>
    <div class="k-date" id="kdate"></div>
  </div>
</div>

<div class="k-wrap">
<table class="k-table">
  <thead>
    <tr>
      <th style="width:170px;min-width:140px">Dolgozó</th>
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
          <?php
          $overlapIds = overlapping_task_ids($cellTasks);
          foreach ($cellTasks as $t):
            $timeStr = '';
            if ($t['time_from']) {
              $timeStr = fmt_time($t['time_from']);
              if ($t['time_to']) $timeStr .= '–' . fmt_time($t['time_to']);
            }
            $bg  = e($t['color']);
            $fg  = e(contrast_color($t['color']));
            $cls = 'k-task' . (isset($overlapIds[$t['id']]) ? ' overlap' : '');
          ?>
          <div class="<?= $cls ?>" style="background:<?= $bg ?>;color:<?= $fg ?>">
            <?php if ($timeStr): ?>
              <span class="k-task-time"><?= e($timeStr) ?></span>
            <?php endif; ?>
            <span class="k-task-title"><?= e($t['title']) ?></span>
            <?php if ($t['location_name']): ?>
              <span class="k-task-loc">· <?= e($t['location_name']) ?></span>
            <?php endif; ?>
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
const LAST_MOD = <?= $lastMod ?>;

function tick() {
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  document.getElementById('kclock').textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
  document.getElementById('kdate').textContent  = now.toLocaleDateString('hu-HU',
    {year:'numeric', month:'long', day:'numeric', weekday:'long'});
}
tick(); setInterval(tick, 1000);

function fitFonts() {
  const R = document.documentElement;

  // 1. Feladat sávok: minden téglalaphoz egyedi optimális betűméret
  const cvs = document.createElement('canvas').getContext('2d');
  document.querySelectorAll('.k-task').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.height < 6 || r.width < 30) return;

    const availH = r.height;
    const availW = r.width - 28; // padding (12px × 2) + gap tartalék

    // Teljes szöveg összefűzve (így mérjük a szélességet)
    const timeEl  = el.querySelector('.k-task-time');
    const titleEl = el.querySelector('.k-task-title');
    const locEl   = el.querySelector('.k-task-loc');
    const text = [
      timeEl  ? timeEl.textContent.trim()  : '',
      titleEl ? titleEl.textContent.trim() : '',
      locEl   ? '· ' + locEl.textContent.trim() : '',
    ].filter(Boolean).join('  ');

    // Bináris keresés: max fs ahol magasság és szélesség is megfelel
    let lo = 9, hi = Math.min(availH * 0.68, 48);
    while (hi - lo > 0.4) {
      const mid = (lo + hi) / 2;
      cvs.font = `600 ${mid}px system-ui,sans-serif`;
      if (cvs.measureText(text).width <= availW) lo = mid; else hi = mid;
    }
    el.style.fontSize = Math.max(9, lo).toFixed(1) + 'px';
  });

  // 2. Dolgozó névsáv: canvas-alapú bináris keresés – DOM reflow nélkül
  const empCells = document.querySelectorAll('td.k-emp');
  if (empCells.length) {
    const r      = empCells[0].getBoundingClientRect();
    const availW = r.width - 20;   // padding levonva
    const availH = r.height - 8;   // padding levonva
    const LH     = 1.25;           // line-height

    // Szövegek kiszedése (small tag nélkül)
    const names = Array.from(empCells).map(cell => {
      const small = cell.querySelector('small');
      return small
        ? cell.textContent.replace(small.textContent, '').trim()
        : cell.textContent.trim();
    });
    // Divíziók (small tagok)
    const subs = Array.from(empCells).map(cell => {
      const s = cell.querySelector('small');
      return s ? s.textContent.trim() : '';
    });

    // Canvas mérés – nincs layout reflow
    const cvs = document.createElement('canvas').getContext('2d');
    function textW(text, fs, weight) {
      cvs.font = `${weight} ${fs}px system-ui,sans-serif`;
      return cvs.measureText(text).width;
    }
    function fits(fs) {
      return names.every((name, i) => {
        const nameLines = Math.ceil(textW(name, fs, '600') / availW);
        const subLines  = subs[i] ? Math.ceil(textW(subs[i], fs * 0.78, '400') / availW) : 0;
        return (nameLines + subLines) * fs * LH <= availH;
      });
    }

    // Bináris keresés: max fs ahol még minden név belefér
    let lo = 8, hi = 26;
    while (hi - lo > 0.4) {
      const mid = (lo + hi) / 2;
      if (fits(mid)) lo = mid; else hi = mid;
    }
    const empFs = Math.max(8, lo);
    R.style.setProperty('--k-emp-fs',     empFs.toFixed(1) + 'px');
    R.style.setProperty('--k-emp-sub-fs', Math.max(7, empFs * 0.78).toFixed(1) + 'px');
  }

  // 3. Fejléc dátumok: th szélesség és magasság alapján
  const th = document.querySelector('.k-table thead th:nth-child(2)');
  if (th) {
    const r    = th.getBoundingClientRect();
    const hFs  = Math.max(8, Math.min(r.width / 10, r.height * 0.65, 24));
    R.style.setProperty('--k-hdr-fs', hFs.toFixed(1) + 'px');
  }
}

window.addEventListener('load', fitFonts);
window.addEventListener('resize', fitFonts);

setInterval(() => {
  fetch('kiosk_check.php').then(r => r.json()).then(d => {
    if (d.last_modified > LAST_MOD) location.reload();
  }).catch(() => {});
}, <?= $kioskRefresh * 1000 ?>);

setTimeout(() => location.reload(), <?= $kioskReload * 1000 ?>);
</script>
</body>
</html>

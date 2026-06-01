<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
agv_require_login();

$page  = 'map';
$title = 'Térkép';
require __DIR__ . '/_header.php';
?>

<style>
@keyframes map-breathe {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.3; }
}
#map-ts.live  { color: #22c55e; animation: map-breathe 2s ease-in-out infinite; }
#map-ts.error { color: #ef4444; animation: none; }
</style>

<div class="d-flex align-items-center justify-content-between mb-2">
  <h5 class="fw-bold mb-0">AGV Térkép</h5>
  <div class="d-flex gap-2 align-items-center">
    <span class="text-muted small" id="map-ts">–</span>
    <button class="btn btn-sm btn-outline-secondary" id="btn-fit" title="Nézet illesztése az összes AGV-re">⊡ Illesztés</button>
  </div>
</div>

<!-- Jelmagyarázat -->
<div class="d-flex gap-3 flex-wrap small mb-2 text-muted">
  <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e;vertical-align:middle;margin-right:4px"></span>Mozgásban</span>
  <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f59e0b;vertical-align:middle;margin-right:4px"></span>Szünetel</span>
  <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#6b7280;vertical-align:middle;margin-right:4px"></span>Áll</span>
  <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;vertical-align:middle;margin-right:4px"></span>Offline (&gt;90s)</span>
  <span class="ms-auto">Görgetés: zoom &nbsp;|&nbsp; Húzás: mozgatás &nbsp;|&nbsp; Kattintás: részletek</span>
</div>

<div class="card shadow-sm mb-3" style="position:relative;overflow:hidden">
  <canvas id="map-canvas" style="width:100%;display:block;cursor:grab"></canvas>

  <!-- AGV info popup -->
  <div id="agv-popup" style="display:none;position:absolute;z-index:10;width:220px" class="card shadow border-0">
    <div class="card-body p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong id="pp-name" style="font-size:13px">–</strong>
        <button onclick="closePopup()" class="btn-close" style="font-size:10px;padding:4px"></button>
      </div>
      <div id="pp-body" class="small"></div>
    </div>
  </div>
</div>

<script>
(function () {
const canvas  = document.getElementById('map-canvas');
const ctx     = canvas.getContext('2d');
const DPR     = window.devicePixelRatio || 1;

// ── Nézet állapot ───────────────────────────────────────────────────────────
let scale   = 60;   // pixel/méter
let originX = 0;    // canvas-on belüli világ-origó X (px)
let originY = 0;    // canvas-on belüli világ-origó Y (px)

// ── Adat ────────────────────────────────────────────────────────────────────
let agvData   = [];
let trailData = {};

// ── Koordináta-transzformáció ───────────────────────────────────────────────
function w2c(wx, wy) { return [originX + wx * scale, originY - wy * scale]; }
function c2w(cx, cy) { return [(cx - originX) / scale, -(cy - originY) / scale]; }

// ── Canvas méret ────────────────────────────────────────────────────────────
function canvasW() { return canvas.width  / DPR; }
function canvasH() { return canvas.height / DPR; }

function resizeCanvas() {
    const rect = canvas.getBoundingClientRect();
    const h = Math.max(480, window.innerHeight - 270);
    canvas.width  = rect.width  * DPR;
    canvas.height = h * DPR;
    canvas.style.height = h + 'px';
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
}

// ── Illesztés ───────────────────────────────────────────────────────────────
function fitView() {
    const pts = [];
    agvData.forEach(a => { if (a.x !== null) pts.push([+a.x, +a.y]); });
    Object.values(trailData).forEach(t => t.forEach(p => { if (p.x !== null) pts.push([+p.x, +p.y]); }));
    if (!pts.length) return;

    let minX = pts[0][0], maxX = pts[0][0], minY = pts[0][1], maxY = pts[0][1];
    pts.forEach(([x, y]) => { minX = Math.min(minX, x); maxX = Math.max(maxX, x); minY = Math.min(minY, y); maxY = Math.max(maxY, y); });

    const pad = 3;
    minX -= pad; maxX += pad; minY -= pad; maxY += pad;
    const W = canvasW(), H = canvasH();
    scale   = Math.min(W / (maxX - minX), H / (maxY - minY)) * 0.88;
    originX = W / 2 - ((minX + maxX) / 2) * scale;
    originY = H / 2 + ((minY + maxY) / 2) * scale;
    draw();
}

// ── AGV szín ────────────────────────────────────────────────────────────────
function agvColor(a) {
    if (!a.updated_at) return '#ef4444';
    const age = (Date.now() - new Date(a.updated_at.replace(' ', 'T') + 'Z')) / 1000;
    if (age > 90)           return '#ef4444';
    if (+a.paused)          return '#f59e0b';
    if (+a.driving)         return '#22c55e';
    return '#6b7280';
}

// ── Rács ────────────────────────────────────────────────────────────────────
function niceStep(approxPx) {
    const approxM = approxPx / scale;
    const mag = Math.pow(10, Math.floor(Math.log10(approxM)));
    for (const n of [1, 2, 5, 10]) {
        if (n * mag >= approxM) return n * mag;
    }
    return 10 * mag;
}

function drawGrid() {
    const W = canvasW(), H = canvasH();
    const mainStep = niceStep(80);
    const subStep  = mainStep / 5;
    const [wx0] = c2w(0,   0);
    const [wx1] = c2w(W,   0);
    const [, wy0] = c2w(0, 0);
    const [, wy1] = c2w(0, H);

    ctx.lineWidth = 0.5;

    // Al-rács
    ctx.strokeStyle = '#e9ecef';
    ctx.beginPath();
    for (let x = Math.floor(wx0 / subStep) * subStep; x <= wx1 + subStep; x += subStep) {
        const [cx] = w2c(x, 0); ctx.moveTo(cx, 0); ctx.lineTo(cx, H);
    }
    for (let y = Math.ceil(wy1 / subStep) * subStep; y <= wy0 + subStep; y += subStep) {
        const [, cy] = w2c(0, y); ctx.moveTo(0, cy); ctx.lineTo(W, cy);
    }
    ctx.stroke();

    // Fő rács + feliratok
    ctx.strokeStyle = '#ced4da';
    ctx.lineWidth   = 0.8;
    ctx.fillStyle   = '#adb5bd';
    ctx.font        = '10px monospace';
    ctx.textAlign   = 'left';
    ctx.beginPath();
    for (let x = Math.floor(wx0 / mainStep) * mainStep; x <= wx1 + mainStep; x += mainStep) {
        const [cx] = w2c(x, 0); ctx.moveTo(cx, 0); ctx.lineTo(cx, H);
    }
    for (let y = Math.ceil(wy1 / mainStep) * mainStep; y <= wy0 + mainStep; y += mainStep) {
        const [, cy] = w2c(0, y); ctx.moveTo(0, cy); ctx.lineTo(W, cy);
    }
    ctx.stroke();

    for (let x = Math.floor(wx0 / mainStep) * mainStep; x <= wx1 + mainStep; x += mainStep) {
        const [cx] = w2c(x, 0);
        ctx.fillText(x.toFixed(x % 1 ? 1 : 0) + 'm', cx + 3, H - 4);
    }
    for (let y = Math.ceil(wy1 / mainStep) * mainStep; y <= wy0 + mainStep; y += mainStep) {
        const [, cy] = w2c(0, y);
        ctx.fillText(y.toFixed(y % 1 ? 1 : 0) + 'm', 3, cy - 3);
    }

    // Origó kereszt
    const [ox, oy] = w2c(0, 0);
    if (ox > -20 && ox < W + 20 && oy > -20 && oy < H + 20) {
        ctx.strokeStyle = '#94a3b8'; ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(ox - 10, oy); ctx.lineTo(ox + 10, oy);
        ctx.moveTo(ox, oy - 10); ctx.lineTo(ox, oy + 10);
        ctx.stroke();
    }
}

// ── Rajz ────────────────────────────────────────────────────────────────────
function draw() {
    const W = canvasW(), H = canvasH();
    ctx.fillStyle = '#f8f9fa';
    ctx.fillRect(0, 0, W, H);

    drawGrid();

    // Trail-ek
    Object.entries(trailData).forEach(([agvId, trail]) => {
        if (trail.length < 2) return;
        const agv   = agvData.find(a => String(a.id) === agvId);
        const color = agv ? agvColor(agv) : '#94a3b8';
        ctx.strokeStyle = color;
        ctx.lineWidth   = 1.5;
        ctx.setLineDash([]);
        ctx.globalAlpha = 0.3;
        ctx.beginPath();
        let first = true;
        trail.forEach(p => {
            if (p.x === null) return;
            const [cx, cy] = w2c(+p.x, +p.y);
            if (first) { ctx.moveTo(cx, cy); first = false; } else { ctx.lineTo(cx, cy); }
        });
        ctx.stroke();
        ctx.globalAlpha = 1;
    });

    // AGV-k
    agvData.forEach(a => {
        if (a.x === null) return;
        const [cx, cy] = w2c(+a.x, +a.y);
        const color     = agvColor(a);
        const R         = 12;

        // Iránynyíl
        if (a.theta !== null) {
            const th  = +a.theta;
            const len = R + 14;
            const ex  = cx + Math.cos(th) * len;
            const ey  = cy - Math.sin(th) * len; // Y-tengely megfordítva
            ctx.strokeStyle = color; ctx.lineWidth = 2.5;
            ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(ex, ey); ctx.stroke();
            // nyílhegy
            ctx.fillStyle = color;
            ctx.beginPath();
            ctx.moveTo(ex, ey);
            ctx.lineTo(ex + Math.cos(th + 2.5) * 6, ey - Math.sin(th + 2.5) * 6);
            ctx.lineTo(ex + Math.cos(th - 2.5) * 6, ey - Math.sin(th - 2.5) * 6);
            ctx.closePath(); ctx.fill();
        }

        // Kör
        ctx.beginPath();
        ctx.arc(cx, cy, R, 0, Math.PI * 2);
        ctx.fillStyle   = color;
        ctx.fill();
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 2.5;
        ctx.stroke();

        // Felirat
        ctx.fillStyle  = '#1f2937';
        ctx.font       = 'bold 11px system-ui, sans-serif';
        ctx.textAlign  = 'center';
        ctx.textBaseline = 'top';
        ctx.fillText(a.name || a.serial_no, cx, cy + R + 4);
        ctx.textBaseline = 'alphabetic';

        // Akkumulátor szöveg
        if (a.battery_charge !== null) {
            ctx.fillStyle = '#6b7280';
            ctx.font      = '9px system-ui, sans-serif';
            ctx.fillText(parseFloat(a.battery_charge).toFixed(0) + '%', cx, cy + R + 17);
        }
    });
}

// ── Pan & Zoom ──────────────────────────────────────────────────────────────
let drag = false, dSX, dSY, dOX, dOY;

canvas.addEventListener('mousedown', e => {
    drag = true; canvas.style.cursor = 'grabbing';
    dSX = e.offsetX; dSY = e.offsetY; dOX = originX; dOY = originY;
});
canvas.addEventListener('mousemove', e => {
    if (!drag) return;
    originX = dOX + (e.offsetX - dSX);
    originY = dOY + (e.offsetY - dSY);
    draw();
});
canvas.addEventListener('mouseup', e => {
    const wasDrag = Math.hypot(e.offsetX - dSX, e.offsetY - dSY) > 5;
    drag = false; canvas.style.cursor = 'grab';
    if (!wasDrag) handleClick(e.offsetX, e.offsetY);
});
canvas.addEventListener('mouseleave', () => { drag = false; canvas.style.cursor = 'grab'; });
canvas.addEventListener('wheel', e => {
    e.preventDefault();
    const f  = e.deltaY < 0 ? 1.12 : 0.9;
    const mx = e.offsetX, my = e.offsetY;
    originX = mx + (originX - mx) * f;
    originY = my + (originY - my) * f;
    scale  *= f;
    draw();
}, { passive: false });

// Touch
let lastTD = 0, touchDrag = false, tSX, tSY, tOX, tOY;
canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    if (e.touches.length === 1) {
        touchDrag = true;
        const rect = canvas.getBoundingClientRect();
        tSX = e.touches[0].clientX - rect.left;
        tSY = e.touches[0].clientY - rect.top;
        tOX = originX; tOY = originY;
    } else if (e.touches.length === 2) {
        touchDrag = false;
        lastTD = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
    }
}, { passive: false });
canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    const rect = canvas.getBoundingClientRect();
    if (e.touches.length === 1 && touchDrag) {
        originX = tOX + (e.touches[0].clientX - rect.left - tSX);
        originY = tOY + (e.touches[0].clientY - rect.top  - tSY);
        draw();
    } else if (e.touches.length === 2) {
        const d  = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
        const f  = d / lastTD;
        const mx = (e.touches[0].clientX + e.touches[1].clientX) / 2 - rect.left;
        const my = (e.touches[0].clientY + e.touches[1].clientY) / 2 - rect.top;
        originX = mx + (originX - mx) * f;
        originY = my + (originY - my) * f;
        scale  *= f; lastTD = d;
        draw();
    }
}, { passive: false });
canvas.addEventListener('touchend', () => { touchDrag = false; });

// ── Popup ───────────────────────────────────────────────────────────────────
window.closePopup = function () { document.getElementById('agv-popup').style.display = 'none'; };

function handleClick(cx, cy) {
    const popup  = document.getElementById('agv-popup');
    const clicked = agvData.find(a => {
        if (a.x === null) return false;
        const [ax, ay] = w2c(+a.x, +a.y);
        return Math.hypot(cx - ax, cy - ay) < 18;
    });
    if (!clicked) { closePopup(); return; }

    const age = clicked.updated_at
        ? Math.round((Date.now() - new Date(clicked.updated_at.replace(' ', 'T') + 'Z')) / 1000)
        : null;
    const offlineBadge = (age !== null && age > 90) ? '<span class="badge bg-danger ms-1" style="font-size:9px">Offline</span>' : '';

    document.getElementById('pp-name').innerHTML = (clicked.name || clicked.serial_no) + offlineBadge;
    document.getElementById('pp-body').innerHTML = `
        <table style="width:100%;border-collapse:collapse">
            <tr><td style="color:#6b7280;padding:2px 8px 2px 0;white-space:nowrap">Pozíció</td>
                <td>${clicked.x !== null ? (+clicked.x).toFixed(3) + ' / ' + (+clicked.y).toFixed(3) + ' m' : '–'}</td></tr>
            <tr><td style="color:#6b7280;padding:2px 8px 2px 0">Szög (θ)</td>
                <td>${clicked.theta !== null ? (+clicked.theta * 180 / Math.PI).toFixed(1) + '°' : '–'}</td></tr>
            <tr><td style="color:#6b7280;padding:2px 8px 2px 0">Akkumulátor</td>
                <td>${clicked.battery_charge !== null ? (+clicked.battery_charge).toFixed(1) + '%' : '–'}</td></tr>
            <tr><td style="color:#6b7280;padding:2px 8px 2px 0">Sebesség vx</td>
                <td>${clicked.vx !== null ? (+clicked.vx).toFixed(3) + ' m/s' : '–'}</td></tr>
            <tr><td style="color:#6b7280;padding:2px 8px 2px 0">Üzemmód</td>
                <td>${clicked.operating_mode || '–'}</td></tr>
            <tr><td style="color:#6b7280;padding:2px 8px 2px 0">Frissítve</td>
                <td>${age !== null ? age + ' mp' : '–'}</td></tr>
        </table>
    `;

    const W = canvasW(), H = canvasH();
    const pW = 220, pH = 185;
    let left = cx + 18, top = cy - 10;
    if (left + pW > W - 10)  left = cx - pW - 8;
    if (top  + pH > H - 10)  top  = H - pH - 10;
    if (top < 5) top = 5;
    popup.style.left    = left + 'px';
    popup.style.top     = top  + 'px';
    popup.style.display = 'block';
}

// ── Fetch ───────────────────────────────────────────────────────────────────
let firstLoad = true;
const tsEl = document.getElementById('map-ts');

function refresh() {
    fetch('map_api.php')
        .then(r => r.json())
        .then(data => {
            agvData   = data.agvs;
            trailData = data.trails;
            tsEl.textContent = 'Frissítve: ' + data.ts;
            tsEl.classList.remove('error');
            tsEl.classList.add('live');
            if (firstLoad) { firstLoad = false; fitView(); }
            else draw();
        })
        .catch(() => {
            tsEl.textContent = 'Kapcsolat hiba!';
            tsEl.classList.remove('live');
            tsEl.classList.add('error');
        });
}

document.getElementById('btn-fit').addEventListener('click', fitView);


// ── Init ─────────────────────────────────────────────────────────────────────
window.addEventListener('resize', () => {
    resizeCanvas();
    draw();
});
resizeCanvas();
originX = canvasW() / 2;
originY = canvasH() / 2;
draw();
refresh();
setInterval(refresh, 2000);

})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>

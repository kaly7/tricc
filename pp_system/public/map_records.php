<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';
require_login_or_redirect();
if (is_worker()) { header('Location: my_om_jobs.php'); exit; }

$db = db();

$filterEventus  = trim($_GET['eventus'] ?? '');
$filterCity     = (int)($_GET['city_id'] ?? 0);
$filterArchived = $_GET['archived'] ?? '0'; // '0'=aktív, '1'=archivált, 'all'=mind

$where = ["r.deleted_at IS NULL", "r.gps_lat IS NOT NULL"];
$params = [];

if ($filterArchived === '0')   { $where[] = 'r.archived = 0'; }
elseif ($filterArchived === '1') { $where[] = 'r.archived = 1'; }

if ($filterEventus !== '') {
    $where[] = 'r.eventus LIKE ?';
    $params[] = '%' . $filterEventus . '%';
}
if ($filterCity > 0) {
    $where[] = 'r.city_id = ?';
    $params[] = $filterCity;
}

$sql = "
    SELECT r.id, r.eventus, r.address, r.operation, r.gps_lat, r.gps_lng,
           r.archived, c.name AS city_name, ps.name AS status_name, ps.color_hex
    FROM records r
    JOIN cities c ON c.id = r.city_id
    JOIN pp_status ps ON ps.id = r.pp_status_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.issued_at DESC
    LIMIT 2000
";
$st = $db->prepare($sql);
$st->execute($params);
$records = $st->fetchAll(PDO::FETCH_ASSOC);

$cities = $db->query("SELECT id, name FROM cities ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$totalWithGps = (int)$db->query("SELECT COUNT(*) FROM records WHERE deleted_at IS NULL AND gps_lat IS NOT NULL")->fetchColumn();
$totalNoGps   = (int)$db->query("SELECT COUNT(*) FROM records WHERE deleted_at IS NULL AND gps_lat IS NULL")->fetchColumn();

// Fotó GPS adatok rekord szerint csoportosítva
$photoGpsRaw = $db->query("
    SELECT j.record_id,
           AVG(p.gps_lat) AS avg_lat,
           AVG(p.gps_lng) AS avg_lng,
           COUNT(p.id)    AS photo_count
    FROM om_job_photos p
    JOIN om_jobs j ON j.id = p.job_id
    WHERE p.gps_lat IS NOT NULL
    GROUP BY j.record_id
")->fetchAll(PDO::FETCH_ASSOC);

$photoGps = [];
foreach ($photoGpsRaw as $row) {
    $photoGps[(int)$row['record_id']] = [
        'lat'   => (float)$row['avg_lat'],
        'lng'   => (float)$row['avg_lng'],
        'count' => (int)$row['photo_count'],
    ];
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rekordok térképe – PP rendszer</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css">
  <style>
    .leaflet-routing-container { background: #16213e; color: #eee; font-size: .8rem; max-width: 260px; }
    .leaflet-routing-container h2 { font-size: .85rem; color: #fff; }
    .leaflet-routing-container table { color: #ccc; }
    .leaflet-routing-alt { max-height: 200px; overflow-y: auto; }
    #route-info { position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%);
      background: #16213e; color: #eee; padding: 8px 18px; border-radius: 20px;
      font-size: .85rem; z-index: 1000; display: none; box-shadow: 0 2px 8px rgba(0,0,0,.5); }
    #route-info span { font-weight: 700; color: #39ff14; }
    #route-clear { margin-left: 12px; cursor: pointer; color: #e94560; font-weight: 700; }
    #edit-hint { position: absolute; top: 60px; left: 50%; transform: translateX(-50%);
      background: #e94560; color: #fff; padding: 7px 18px; border-radius: 20px;
      font-size: .85rem; z-index: 1000; display: none; box-shadow: 0 2px 8px rgba(0,0,0,.5);
      pointer-events: none; }
    .map-wrapper { position: relative; flex: 1; }
    #map { height: 100%; }
  </style>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: sans-serif; display: flex; flex-direction: column; height: 100vh; background: #1a1a2e; color: #eee; }
    #header { padding: 8px 14px; background: #16213e; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    #header a.back { color: #aaa; text-decoration: none; font-size: .9rem; white-space: nowrap; }
    #header a.back:hover { color: #fff; }
    #header h1 { margin: 0; font-size: 1rem; font-weight: 600; white-space: nowrap; }
    #filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-left: auto; }
    #filters input, #filters select { background: #0f3460; color: #eee; border: 1px solid #444; border-radius: 4px; padding: 4px 8px; font-size: .82rem; }
    #filters button { background: #e94560; color: #fff; border: none; border-radius: 4px; padding: 4px 12px; font-size: .82rem; cursor: pointer; }
    #filters button:hover { background: #c73652; }
    #stats { font-size: .75rem; color: #888; white-space: nowrap; }
    #map { height: 100%; }
    .leaflet-popup-content { font-size: .85rem; min-width: 180px; }
    .leaflet-popup-content .rec-title { font-weight: 600; margin-bottom: 4px; }
    .leaflet-popup-content .rec-op { color: #555; font-size: .8rem; margin-bottom: 6px; }
    .leaflet-popup-content a.rec-link { display: inline-block; color: #1a73e8; font-size: .82rem; text-decoration: none; }
    .leaflet-popup-content a.rec-link:hover { text-decoration: underline; }
    .leaflet-popup-content a.sv-link { display: inline-block; color: #e67e22; font-size: .82rem; margin-left: 8px; }
    /* Útvonaltervező panel */
    #planner-panel {
      position: absolute; top: 10px; right: 10px; z-index: 1000;
      background: #16213e; color: #eee; border-radius: 8px;
      padding: 12px 14px; width: 250px; max-height: 70vh; overflow-y: auto;
      box-shadow: 0 3px 14px rgba(0,0,0,.7); display: none; font-size: .85rem;
    }
    #planner-panel h3 { margin: 0 0 10px; font-size: .9rem; display: flex; justify-content: space-between; align-items: center; }
    #planner-close { cursor: pointer; color: #888; font-size: 1rem; line-height: 1; }
    #planner-close:hover { color: #fff; }
    #planner-list { margin-bottom: 10px; }
    .planner-item { display: flex; align-items: center; gap: 5px; padding: 5px 0; border-bottom: 1px solid #2a3a5e; }
    .planner-num { background: #e94560; color: #fff; border-radius: 50%; width: 20px; height: 20px;
      text-align: center; line-height: 20px; font-size: .72rem; font-weight: 700; flex-shrink: 0; }
    .planner-label { flex: 1; font-size: .78rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .planner-move { color: #888; cursor: pointer; font-size: .7rem; padding: 0 2px; user-select: none; }
    .planner-move:hover { color: #fff; }
    .planner-del { color: #e94560; cursor: pointer; font-weight: 700; padding: 0 3px; font-size: .9rem; }
    .planner-del:hover { color: #ff6b84; }
    #planner-actions { display: flex; flex-direction: column; gap: 6px; }
    #planner-actions button { border: none; border-radius: 4px; padding: 7px 10px; cursor: pointer; font-size: .82rem; text-align: left; }
    #btn-planner-calc { background: #39ff14; color: #000; font-weight: 700; }
    #btn-planner-calc:hover { background: #2de010; }
    #btn-planner-home { background: #0f3460; color: #eee; }
    #btn-planner-home:hover { background: #1a4a80; }
    #btn-planner-clear { background: #e94560; color: #fff; }
    #btn-planner-clear:hover { background: #c73652; }
    #planner-hint { margin-top: 10px; font-size: .75rem; color: #666; line-height: 1.4; }
    #planner-result { margin-top: 10px; font-size: .82rem; color: #39ff14; display: none; line-height: 1.5; }
    #btn-planner-toggle { background: #0f3460; color: #eee; border: 1px solid #2a5298; border-radius: 4px;
      padding: 4px 12px; font-size: .82rem; cursor: pointer; white-space: nowrap; }
    #btn-planner-toggle:hover { background: #1a4a80; }
    #btn-planner-toggle.active { background: #39ff14; color: #000; border-color: #39ff14; font-weight: 700; }
  </style>
</head>
<body>
<div id="header">
  <a class="back" href="records.php">&larr; Vissza</a>
  <h1>🗺 Rekordok térképe</h1>
  <form id="filters" method="get">
    <input type="text" name="eventus" placeholder="Eventus..." value="<?=h($filterEventus)?>" style="width:110px">
    <select name="city_id">
      <option value="0">Minden település</option>
      <?php foreach ($cities as $c): ?>
        <option value="<?=$c['id']?>" <?=$filterCity===$c['id']?'selected':''?>><?=h($c['name'])?></option>
      <?php endforeach; ?>
    </select>
    <select name="archived">
      <option value="0" <?=$filterArchived==='0'?'selected':''?>>Aktív</option>
      <option value="1" <?=$filterArchived==='1'?'selected':''?>>Archivált</option>
      <option value="all" <?=$filterArchived==='all'?'selected':''?>>Mind</option>
    </select>
    <button type="submit">Szűrés</button>
  </form>
  <button id="btn-planner-toggle" onclick="togglePlannerMode()">🗺 Tervező</button>
  <span id="stats"><?=count($records)?> pont | GPS: <?=$totalWithGps?> / <?=($totalWithGps+$totalNoGps)?></span>
</div>

<div class="map-wrapper">
  <div id="map"></div>
  <div id="edit-hint">📍 Kattints a térképen az új helyszínre – ESC a mégse</div>

  <div id="planner-panel">
    <h3>🗺 Útvonaltervező <span id="planner-close" onclick="togglePlannerMode()">✕</span></h3>
    <div id="planner-list"><em style="color:#666;font-size:.78rem">Még nincs kijelölt pont</em></div>
    <div id="planner-actions">
      <button id="btn-planner-home" onclick="addHomeToPlanner()">🏠 Induló pont hozzáadása</button>
      <button id="btn-planner-calc" onclick="calcPlannerRoute()">🚗 Útvonal számítása</button>
      <button id="btn-planner-clear" onclick="clearPlanner()">🗑 Összes törlése</button>
    </div>
    <div id="planner-hint">Kattints egy <strong>jelölőre</strong> a hozzáadáshoz, vagy kattints a <strong>térképre</strong> egyedi pont lerakásához.</div>
    <div id="planner-result"></div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
<script>
const records = <?= json_encode(array_map(fn($r) => [
    'id'       => (int)$r['id'],
    'lat'      => (float)$r['gps_lat'],
    'lng'      => (float)$r['gps_lng'],
    'eventus'  => $r['eventus'],
    'city'     => $r['city_name'],
    'address'  => $r['address'],
    'op'       => $r['operation'],
    'status'   => $r['status_name'],
    'color'    => $r['color_hex'] ?? '#3388ff',
    'archived' => (int)$r['archived'],
    'photo'    => isset($photoGps[(int)$r['id']]) ? $photoGps[(int)$r['id']] : null,
], $records), JSON_UNESCAPED_UNICODE) ?>;

// Haversine: km távolság két koordináta között
function haversine(lat1, lng1, lat2, lng2) {
  const R = 6371;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLng = (lng2 - lng1) * Math.PI / 180;
  const a = Math.sin(dLat/2) * Math.sin(dLat/2)
          + Math.cos(lat1 * Math.PI/180) * Math.cos(lat2 * Math.PI/180)
          * Math.sin(dLng/2) * Math.sin(dLng/2);
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

const PHOTO_DIST_WARN = 3;
const HOME = L.latLng(46.3428782, 18.7183096);

const map = L.map('map', { center: [46.5, 18.5], zoom: 9 });
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors'
}).addTo(map);

const homeIcon = L.divIcon({
  html: '<div style="background:#e94560;border:3px solid #fff;border-radius:50%;width:18px;height:18px;box-shadow:0 2px 6px rgba(0,0,0,.5)"></div>',
  iconSize: [18, 18], iconAnchor: [9, 9]
});
L.marker(HOME, { icon: homeIcon, zIndexOffset: 1000 })
  .addTo(map)
  .bindPopup('<strong>Szekszárd, Epreskert utca 6.</strong><br><em>Induló pont</em>');

// --- Ikonok ---
function colorIcon(hex) {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="36" viewBox="0 0 24 36">
    <path d="M12 0C5.37 0 0 5.37 0 12c0 9 12 24 12 24s12-15 12-24C24 5.37 18.63 0 12 0z"
      fill="${hex}" stroke="#000" stroke-width="1.5"/>
    <circle cx="12" cy="12" r="5" fill="#39ff14"/>
  </svg>`;
  return L.icon({ iconUrl: 'data:image/svg+xml;base64,' + btoa(svg), iconSize: [24, 36], iconAnchor: [12, 36], popupAnchor: [0, -36] });
}

function colorIconBadge(hex, num) {
  const fs = num >= 10 ? 8 : 10;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42">
    <path d="M15 0C6.72 0 0 6.72 0 15c0 11.25 15 27 15 27s15-15.75 15-27C30 6.72 23.28 0 15 0z"
      fill="${hex}" stroke="#000" stroke-width="2"/>
    <circle cx="15" cy="15" r="9" fill="#39ff14"/>
    <text x="15" y="19" text-anchor="middle" font-size="${fs}" font-weight="bold" fill="#000" font-family="sans-serif">${num}</text>
  </svg>`;
  return L.icon({ iconUrl: 'data:image/svg+xml;base64,' + btoa(svg), iconSize: [30, 42], iconAnchor: [15, 42], popupAnchor: [0, -42] });
}

function customWaypointIcon(num) {
  const fs = num >= 10 ? 9 : 11;
  return L.divIcon({
    html: `<div style="background:#ff9900;border:2px solid #000;border-radius:50%;width:26px;height:26px;
      display:flex;align-items:center;justify-content:center;font-size:${fs}px;font-weight:bold;
      color:#000;box-shadow:0 2px 8px rgba(0,0,0,.6)">${num}</div>`,
    iconSize: [26, 26], iconAnchor: [13, 13], className: ''
  });
}

// --- Állapotváltozók ---
let routeControl = null;
let editMode = false;
let editRecordId = null;
const markerMap = {};   // recordId → L.Marker
const recordsById = {}; // recordId → record adatok

// --- Egypont útvonal (meglévő) ---
function clearRoute() {
  if (routeControl) { map.removeControl(routeControl); routeControl = null; }
  document.getElementById('route-info').style.display = 'none';
}

function showRoute(destLat, destLng, label) {
  clearRoute();
  routeControl = L.Routing.control({
    waypoints: [ HOME, L.latLng(destLat, destLng) ],
    router: L.Routing.osrmv1({ serviceUrl: 'https://router.project-osrm.org/route/v1', profile: 'driving' }),
    lineOptions: { styles: [{ color: '#003399', weight: 6, opacity: 0.9 }] },
    createMarker: function() { return null; },
    addWaypoints: false, draggableWaypoints: false, fitSelectedRoutes: true, show: false
  }).addTo(map);
  routeControl.on('routesfound', function(e) {
    const route = e.routes[0];
    const km = (route.summary.totalDistance / 1000).toFixed(1);
    const min = Math.round(route.summary.totalTime / 60);
    const h = Math.floor(min / 60), m = min % 60;
    const idoStr = h > 0 ? `${h} ó ${m} perc` : `${m} perc`;
    const info = document.getElementById('route-info');
    info.innerHTML = `🚗 <span>${km} km</span> &nbsp;·&nbsp; ⏱ <span>${idoStr}</span> &nbsp;·&nbsp; ${label} <span id="route-clear" onclick="clearRoute()">✕</span>`;
    info.style.display = 'block';
  });
  routeControl.on('routingerror', function() {
    const info = document.getElementById('route-info');
    info.innerHTML = `Útvonal nem számolható. <span id="route-clear" onclick="clearRoute()">✕</span>`;
    info.style.display = 'block';
  });
}

// --- GPS szerkesztés (meglévő) ---
function startEditMode(recordId) {
  editMode = true;
  editRecordId = recordId;
  map.closePopup();
  map.getContainer().style.cursor = 'crosshair';
  document.getElementById('edit-hint').style.display = 'block';
}

function stopEditMode() {
  editMode = false;
  editRecordId = null;
  if (!plannerMode) map.getContainer().style.cursor = '';
  document.getElementById('edit-hint').style.display = 'none';
}

function snapToPhoto(recordId, photoLat, photoLng) {
  const fd = new FormData();
  fd.append('record_id', recordId);
  fd.append('lat', photoLat);
  fd.append('lng', photoLng);
  fetch('actions/record_update_gps.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        if (markerMap[recordId]) markerMap[recordId].setLatLng([photoLat, photoLng]);
        map.closePopup();
      } else {
        alert('Hiba: ' + (data.error || 'ismeretlen'));
      }
    });
}

// --- Útvonaltervező ---
let plannerMode = false;
let plannerWaypoints = []; // [{lat, lng, label, type:'record'|'custom'|'home', recordId, markerObj}]
let plannerRouteControl = null;

function togglePlannerMode() {
  plannerMode = !plannerMode;
  const btn = document.getElementById('btn-planner-toggle');
  const panel = document.getElementById('planner-panel');
  if (plannerMode) {
    btn.classList.add('active');
    panel.style.display = 'block';
    map.getContainer().style.cursor = 'crosshair';
    if (editMode) stopEditMode();
    clearRoute();
  } else {
    btn.classList.remove('active');
    panel.style.display = 'none';
    map.getContainer().style.cursor = '';
    clearPlanner();
    clearPlannerRoute();
  }
}

function addWaypointRecord(r) {
  // Ha nincs tervező mód, aktiváljuk
  if (!plannerMode) {
    plannerMode = true;
    document.getElementById('btn-planner-toggle').classList.add('active');
    document.getElementById('planner-panel').style.display = 'block';
    map.getContainer().style.cursor = 'crosshair';
    if (editMode) stopEditMode();
    clearRoute();
  }
  map.closePopup();
  // Toggle: ha már szerepel, eltávolítjuk
  const idx = plannerWaypoints.findIndex(w => w.recordId === r.id);
  if (idx >= 0) { removeWaypoint(idx); return; }

  plannerWaypoints.push({ lat: r.lat, lng: r.lng, label: r.eventus + ' – ' + r.city, type: 'record', recordId: r.id, markerObj: null });
  if (markerMap[r.id]) markerMap[r.id].setIcon(colorIconBadge(r.color, plannerWaypoints.length));
  renderPlannerPanel();
}

function removeWaypoint(idx) {
  const wp = plannerWaypoints[idx];
  if (wp.type === 'custom' && wp.markerObj) map.removeLayer(wp.markerObj);
  if (wp.type === 'record' && wp.recordId != null) {
    const rec = recordsById[wp.recordId];
    if (rec && markerMap[wp.recordId]) markerMap[wp.recordId].setIcon(colorIcon(rec.color));
  }
  plannerWaypoints.splice(idx, 1);
  refreshAllBadges();
  renderPlannerPanel();
}

function moveWaypoint(idx, dir) {
  const to = idx + dir;
  if (to < 0 || to >= plannerWaypoints.length) return;
  [plannerWaypoints[idx], plannerWaypoints[to]] = [plannerWaypoints[to], plannerWaypoints[idx]];
  refreshAllBadges();
  renderPlannerPanel();
}

function refreshAllBadges() {
  plannerWaypoints.forEach((wp, i) => {
    const n = i + 1;
    if (wp.type === 'record' && wp.recordId != null && markerMap[wp.recordId]) {
      const rec = recordsById[wp.recordId];
      if (rec) markerMap[wp.recordId].setIcon(colorIconBadge(rec.color, n));
    } else if (wp.type === 'custom' && wp.markerObj) {
      wp.markerObj.setIcon(customWaypointIcon(n));
    }
  });
}

function renderPlannerPanel() {
  const list = document.getElementById('planner-list');
  if (plannerWaypoints.length === 0) {
    list.innerHTML = '<em style="color:#666;font-size:.78rem">Még nincs kijelölt pont</em>';
    return;
  }
  list.innerHTML = plannerWaypoints.map((wp, i) => `
    <div class="planner-item">
      <div class="planner-num">${i + 1}</div>
      <div class="planner-label" title="${wp.label}">${wp.label}</div>
      <span class="planner-move" onclick="moveWaypoint(${i},-1)" title="Fel">▲</span>
      <span class="planner-move" onclick="moveWaypoint(${i},1)" title="Le">▼</span>
      <span class="planner-del" onclick="removeWaypoint(${i})" title="Törlés">✕</span>
    </div>
  `).join('');
}

function addHomeToPlanner() {
  if (plannerWaypoints.some(w => w.type === 'home')) return;
  plannerWaypoints.unshift({ lat: HOME.lat, lng: HOME.lng, label: 'Induló pont (Szekszárd)', type: 'home', recordId: null, markerObj: null });
  refreshAllBadges();
  renderPlannerPanel();
}

function clearPlanner() {
  plannerWaypoints.forEach(wp => {
    if (wp.type === 'custom' && wp.markerObj) map.removeLayer(wp.markerObj);
    if (wp.type === 'record' && wp.recordId != null) {
      const rec = recordsById[wp.recordId];
      if (rec && markerMap[wp.recordId]) markerMap[wp.recordId].setIcon(colorIcon(rec.color));
    }
  });
  plannerWaypoints = [];
  renderPlannerPanel();
  clearPlannerRoute();
}

function clearPlannerRoute() {
  if (plannerRouteControl) { map.removeControl(plannerRouteControl); plannerRouteControl = null; }
  document.getElementById('planner-result').style.display = 'none';
}

function calcPlannerRoute() {
  if (plannerWaypoints.length < 2) {
    alert('Legalább 2 pont szükséges az útvonal tervezéséhez!');
    return;
  }
  clearPlannerRoute();
  clearRoute();
  const waypoints = plannerWaypoints.map(wp => L.latLng(wp.lat, wp.lng));
  plannerRouteControl = L.Routing.control({
    waypoints,
    router: L.Routing.osrmv1({ serviceUrl: 'https://router.project-osrm.org/route/v1', profile: 'driving' }),
    lineOptions: { styles: [{ color: '#003399', weight: 5, opacity: 0.85 }] },
    createMarker: function() { return null; },
    addWaypoints: false, draggableWaypoints: false, fitSelectedRoutes: true, show: false
  }).addTo(map);
  plannerRouteControl.on('routesfound', function(e) {
    const route = e.routes[0];
    const km = (route.summary.totalDistance / 1000).toFixed(1);
    const min = Math.round(route.summary.totalTime / 60);
    const h = Math.floor(min / 60), m = min % 60;
    const idoStr = h > 0 ? `${h} ó ${m} perc` : `${m} perc`;
    const res = document.getElementById('planner-result');
    res.innerHTML = `🚗 <strong>${km} km</strong> &nbsp;·&nbsp; ⏱ <strong>${idoStr}</strong><br><span style="font-size:.73rem;color:#aaa">${plannerWaypoints.length} megálló</span>`;
    res.style.display = 'block';
  });
  plannerRouteControl.on('routingerror', function() {
    const res = document.getElementById('planner-result');
    res.innerHTML = '<span style="color:#e94560">Útvonal nem számolható</span>';
    res.style.display = 'block';
  });
}

// --- Billentyűzet ---
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    if (editMode) stopEditMode();
    if (plannerMode) togglePlannerMode();
  }
});

// --- Térkép kattintás ---
map.on('click', function(e) {
  if (editMode && editRecordId) {
    const lat = e.latlng.lat.toFixed(7);
    const lng = e.latlng.lng.toFixed(7);
    const fd = new FormData();
    fd.append('record_id', editRecordId);
    fd.append('lat', lat);
    fd.append('lng', lng);
    fetch('actions/record_update_gps.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          if (markerMap[editRecordId]) markerMap[editRecordId].setLatLng([parseFloat(lat), parseFloat(lng)]);
          stopEditMode();
          const hint = document.getElementById('edit-hint');
          hint.textContent = '✓ Koordináta elmentve';
          hint.style.background = '#28a745';
          hint.style.display = 'block';
          setTimeout(() => { hint.style.display = 'none'; hint.textContent = '📍 Kattints a térképen az új helyszínre – ESC a mégse'; hint.style.background = '#e94560'; }, 2000);
        } else {
          alert('Hiba: ' + (data.error || 'ismeretlen hiba'));
          stopEditMode();
        }
      })
      .catch(() => { alert('Hálózati hiba.'); stopEditMode(); });
  } else if (plannerMode) {
    // Egyedi pont lerakása a térképre
    const lat = e.latlng.lat;
    const lng = e.latlng.lng;
    const num = plannerWaypoints.length + 1;
    const mObj = L.marker([lat, lng], { icon: customWaypointIcon(num), bubblingMouseEvents: false }).addTo(map);
    const label = `Egyedi pont #${num}`;
    plannerWaypoints.push({ lat, lng, label, type: 'custom', recordId: null, markerObj: mObj });
    // Kattintás az egyedi markerre: eltávolítás
    mObj.on('click', function() {
      const idx = plannerWaypoints.findIndex(w => w.markerObj === mObj);
      if (idx >= 0) removeWaypoint(idx);
    });
    renderPlannerPanel();
  }
});

// --- Popup HTML összeállítása ---
function buildPopupHtml(r) {
  const svUrl = `https://www.google.com/maps?q=&layer=c&cbll=${r.lat},${r.lng}`;
  let photoHtml = '', warnHtml = '';
  if (r.photo) {
    const dist = haversine(r.lat, r.lng, r.photo.lat, r.photo.lng);
    if (dist > PHOTO_DIST_WARN) {
      warnHtml = `<div style="margin-top:6px;background:#fff3cd;color:#856404;padding:3px 7px;border-radius:4px;font-size:.78rem">
        ⚠ Fotó koordináta <strong>${dist.toFixed(1)} km</strong>-re van – nem pontosít</div>`;
    } else {
      photoHtml = `<br><a class="rec-link" style="margin-top:4px;color:#17a2b8" href="#"
        onclick="event.preventDefault();snapToPhoto(${r.id},${r.photo.lat},${r.photo.lng})">
        📷 Pontosítás fotóból (${dist.toFixed(2)} km, ${r.photo.count} fotó)</a>`;
    }
  }
  return `
    <div class="rec-title">${r.eventus} – ${r.city}</div>
    <div class="rec-op">${r.op}</div>
    <span style="background:${r.color};color:#fff;padding:1px 6px;border-radius:3px;font-size:.75rem">${r.status}</span>
    ${r.archived ? ' <em style="font-size:.75rem;color:#888">(archivált)</em>' : ''}
    ${warnHtml}
    <br><br>
    <a class="rec-link" href="records_edit.php?id=${r.id}" target="_blank">📋 Rekord megnyitása</a>
    <a class="sv-link" href="${svUrl}" target="_blank">🔭 Street View</a>
    <br>
    <a class="rec-link" style="margin-top:6px;color:#39ff14" href="#"
       onclick="event.preventDefault();showRoute(${r.lat},${r.lng},'${r.city.replace(/'/g,"\\'")}')">
      🚗 Útvonal ide</a>
    ${photoHtml}
    <br>
    <a class="rec-link" style="margin-top:4px;color:#ffa500" href="#"
       onclick="event.preventDefault();startEditMode(${r.id})">📍 Koordináta javítása</a>
    <br>
    <a class="rec-link" style="margin-top:4px;color:#17a2b8" href="#"
       onclick="event.preventDefault();addWaypointRecord(recordsById[${r.id}])">🗺 Tervező útvonalhoz</a>
  `;
}

// --- Markerek építése ---
const bounds = [];
records.forEach(function(r) {
  recordsById[r.id] = r;
  const marker = L.marker([r.lat, r.lng], { icon: colorIcon(r.color), bubblingMouseEvents: false }).addTo(map);
  markerMap[r.id] = marker;

  marker.on('click', function() {
    if (plannerMode) {
      addWaypointRecord(r);
    } else {
      L.popup({ maxWidth: 290 })
        .setLatLng(marker.getLatLng())
        .setContent(buildPopupHtml(r))
        .openOn(map);
    }
  });

  bounds.push([r.lat, r.lng]);
});

if (bounds.length > 0) map.fitBounds(bounds, { padding: [30, 30] });
</script>

<div id="route-info"></div>
</body>
</html>

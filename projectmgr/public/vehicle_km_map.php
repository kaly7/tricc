<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id   = filter_input(INPUT_GET, 'id',   FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$date = trim((string)($_GET['date'] ?? ''));

if (!$id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400); exit('Hibás paraméter.');
}

$sv = $pdo->prepare("SELECT id, license_plate, make, model FROM vehicles WHERE id=?");
$sv->execute([$id]);
$v = $sv->fetch(PDO::FETCH_ASSOC);
if (!$v) { http_response_code(404); exit('Jármű nem található.'); }

// Napi összesítő
$sk = $pdo->prepare("SELECT total_km, trip_count, fetched_at FROM vehicle_daily_km WHERE vehicle_id=? AND km_date=?");
$sk->execute([$id, $date]);
$day = $sk->fetch(PDO::FETCH_ASSOC);

// Szakaszok
$st = $pdo->prepare("
    SELECT trip_no,
           departure_time, departure_addr, departure_lat, departure_lon,
           arrival_time,   arrival_addr,   arrival_lat,   arrival_lon,
           distance_km, fuel_l
    FROM vehicle_daily_trips
    WHERE vehicle_id=? AND km_date=?
    ORDER BY trip_no
");
$st->execute([$id, $date]);
$trips = $st->fetchAll(PDO::FETCH_ASSOC);

// Szakasz párok JS-be (dep→arr koordinátával, markeradatokkal)
$segments = [];
foreach ($trips as $i => $t) {
    $has_dep = $t['departure_lat'] && $t['departure_lon'];
    $has_arr = $t['arrival_lat']   && $t['arrival_lon'];
    if (!$has_dep && !$has_arr) continue;
    $segments[] = [
        'trip'     => $i + 1,
        'dep'      => $has_dep ? [
            'lat'  => (float)$t['departure_lat'],
            'lon'  => (float)$t['departure_lon'],
            'addr' => $t['departure_addr'] ?? '',
            'time' => $t['departure_time'] ? substr($t['departure_time'], 11, 5) : '',
        ] : null,
        'arr'      => $has_arr ? [
            'lat'  => (float)$t['arrival_lat'],
            'lon'  => (float)$t['arrival_lon'],
            'addr' => $t['arrival_addr'] ?? '',
            'time' => $t['arrival_time'] ? substr($t['arrival_time'], 11, 5) : '',
            'km'   => $t['distance_km'],
        ] : null,
        'is_last'  => ($i === count($trips) - 1),
    ];
}

$has_map = count($segments) > 0;
?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($v['license_plate']) ?> – <?= h($date) ?> útvonal</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <style>
    body { background: #f8f9fa; }
    #map { height: calc(100vh - 180px); min-height: 400px; border-radius: .5rem; }
    .trip-card { font-size: .85rem; }
    .dep-dot  { color: #198754; font-size: 1.1em; }
    .arr-dot  { color: #dc3545; font-size: 1.1em; }
    .mid-dot  { color: #6c757d; font-size: .9em; }
  </style>
</head>
<body>
<div class="container-fluid py-3">

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <a href="/vehicle.php?id=<?= (int)$id ?>&tab=km" class="text-muted text-decoration-none small">← Vissza</a>
      <h1 class="h5 mb-0 mt-1">
        <?= h($v['license_plate']) ?> — <?= h(trim($v['make'].' '.$v['model'])) ?>
        <span class="text-muted fw-normal"><?= h($date) ?></span>
      </h1>
    </div>
    <?php if ($day): ?>
    <div class="d-flex gap-2">
      <div class="card text-center px-3 py-1">
        <div class="small text-muted">Összes km</div>
        <div class="fw-bold"><?= number_format((float)$day['total_km'], 1, ',', ' ') ?> km</div>
      </div>
      <div class="card text-center px-3 py-1">
        <div class="small text-muted">Szakaszok</div>
        <div class="fw-bold"><?= (int)$day['trip_count'] ?> db</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$trips): ?>
    <div class="alert alert-warning">Nincs rögzített útvonaladat erre a napra.</div>
  <?php elseif (!$has_map): ?>
    <div class="alert alert-secondary">Nincs elegendő koordináta a térkép megjelenítéséhez.</div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- Térkép -->
    <div class="col-lg-8">
      <div id="map"></div>
    </div>

    <!-- Szakasz lista -->
    <div class="col-lg-4" style="max-height:calc(100vh - 180px); overflow-y:auto;">
      <?php foreach ($trips as $t): ?>
      <div class="card trip-card mb-2 p-2" data-trip="<?= (int)$t['trip_no'] ?>">
        <div class="d-flex justify-content-between align-items-start">
          <span class="fw-semibold"><?= $t['trip_no'] ?>. szakasz</span>
          <?php if ($t['distance_km']): ?>
            <span class="badge bg-primary"><?= number_format((float)$t['distance_km'], 1, ',', ' ') ?> km</span>
          <?php endif; ?>
        </div>
        <div class="mt-1">
          <span class="dep-dot">●</span>
          <span class="text-muted small"><?= $t['departure_time'] ? substr($t['departure_time'],11,5) : '' ?></span>
          <?= h($t['departure_addr'] ?? '—') ?>
        </div>
        <div>
          <span class="arr-dot">●</span>
          <span class="text-muted small"><?= $t['arrival_time'] ? substr($t['arrival_time'],11,5) : '' ?></span>
          <?= h($t['arrival_addr'] ?? '—') ?>
        </div>
        <?php if ($t['fuel_l']): ?>
          <div class="text-muted small mt-1">⛽ <?= number_format((float)$t['fuel_l'], 2, ',', ' ') ?> l</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (!$trips): ?>
        <div class="text-muted">Nincs adat.</div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const segments = <?= json_encode($segments, JSON_UNESCAPED_UNICODE) ?>;

const COLOR_NORMAL   = '#0d6efd';
const COLOR_HIGHLIGHT = '#ff6600';
const WEIGHT_NORMAL   = 4;
const WEIGHT_HIGHLIGHT = 7;

// tripNo → { polyline, bounds }
const tripLayers = {};
let activeTrip = null;

const map = L.map('map');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors',
  maxZoom: 18,
}).addTo(map);

async function fetchOsrmRoute(dep, arr) {
  try {
    const url = `https://router.project-osrm.org/route/v1/driving/`
      + `${dep.lon},${dep.lat};${arr.lon},${arr.lat}`
      + `?overview=full&geometries=geojson`;
    const res = await fetch(url, { signal: AbortSignal.timeout(8000) });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (data.code === 'Ok' && data.routes?.[0]?.geometry?.coordinates) {
      return data.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
    }
  } catch (e) { /* fallback */ }
  return [[dep.lat, dep.lon], [arr.lat, arr.lon]];
}

function addMarker(latlng, color, radius, popup) {
  const m = L.circleMarker(latlng, {
    radius, color, fillColor: color, fillOpacity: 0.92, weight: 2,
  }).addTo(map);
  if (popup) m.bindPopup(popup);
  return m;
}

function popupHtml(p, extra) {
  let s = '';
  if (p.time) s += `<b>${p.time}</b> `;
  if (p.addr) s += p.addr;
  if (extra)  s += `<br><span style="color:#6c757d">${extra}</span>`;
  return s || null;
}

// Kiemelés be/ki
function highlightTrip(tripNo) {
  // Visszaállítjuk az előző kiemelt szakaszt
  if (activeTrip !== null && tripLayers[activeTrip]) {
    tripLayers[activeTrip].polyline.setStyle({ color: COLOR_NORMAL, weight: WEIGHT_NORMAL, opacity: 0.75 });
    document.querySelector(`.trip-card[data-trip="${activeTrip}"]`)?.classList.remove('border-warning', 'shadow-sm');
  }

  // Ha ugyanarra kattintunk → töröljük a kiemelést
  if (activeTrip === tripNo) {
    activeTrip = null;
    // Visszazoom az egészre
    const all = Object.values(tripLayers).flatMap(l => l.points);
    if (all.length) map.fitBounds(L.latLngBounds(all), { padding: [40, 40] });
    return;
  }

  activeTrip = tripNo;

  if (tripLayers[tripNo]) {
    tripLayers[tripNo].polyline.setStyle({ color: COLOR_HIGHLIGHT, weight: WEIGHT_HIGHLIGHT, opacity: 0.95 });
    tripLayers[tripNo].polyline.bringToFront();
    map.fitBounds(L.latLngBounds(tripLayers[tripNo].points), { padding: [60, 60], maxZoom: 16 });
  }

  document.querySelector(`.trip-card[data-trip="${tripNo}"]`)?.classList.add('border-warning', 'shadow-sm');
}

async function drawRoutes() {
  if (!segments.length) {
    map.setView([47.5, 19.05], 8);
    return;
  }

  const allLatLngs = [];

  for (let i = 0; i < segments.length; i++) {
    const seg = segments[i];
    const dep = seg.dep;
    const arr = seg.arr;

    let routePoints;
    if (dep && arr) {
      routePoints = await fetchOsrmRoute(dep, arr);
      if (i < segments.length - 1) await new Promise(r => setTimeout(r, 200));
    } else if (dep) {
      routePoints = [[dep.lat, dep.lon]];
    } else {
      routePoints = [[arr.lat, arr.lon]];
    }

    if (routePoints.length >= 2) {
      const poly = L.polyline(routePoints, {
        color: COLOR_NORMAL, weight: WEIGHT_NORMAL, opacity: 0.75,
      }).addTo(map);
      poly.bindTooltip(`${seg.trip}. szakasz${arr?.km ? ' · ' + parseFloat(arr.km).toFixed(1) + ' km' : ''}`);

      // Kattintás a vonalra → kiemelés
      poly.on('click', () => highlightTrip(seg.trip));

      tripLayers[seg.trip] = { polyline: poly, points: routePoints };
    }

    allLatLngs.push(...routePoints);

    if (dep) {
      const isFirstSeg = (i === 0);
      addMarker(
        [dep.lat, dep.lon],
        isFirstSeg ? '#198754' : '#20c997',
        isFirstSeg ? 10 : 6,
        popupHtml(dep, isFirstSeg ? 'Napi indulás' : `${seg.trip}. szakasz indulás`)
      );
    }

    if (arr) {
      addMarker(
        [arr.lat, arr.lon],
        seg.is_last ? '#dc3545' : '#fd7e14',
        seg.is_last ? 10 : 6,
        popupHtml(arr, arr.km ? `${parseFloat(arr.km).toFixed(1)} km` : null)
      );
    }
  }

  if (allLatLngs.length) {
    map.fitBounds(L.latLngBounds(allLatLngs), { padding: [40, 40] });
  }

  // Kártyák klikkelhetők
  document.querySelectorAll('.trip-card[data-trip]').forEach(card => {
    card.style.cursor = 'pointer';
    card.addEventListener('click', () => highlightTrip(parseInt(card.dataset.trip)));
  });
}

const loadingDiv = document.createElement('div');
loadingDiv.style.cssText = 'position:absolute;top:10px;left:50%;transform:translateX(-50%);z-index:1000;background:rgba(255,255,255,.9);padding:6px 14px;border-radius:20px;font-size:.85rem;box-shadow:0 2px 6px rgba(0,0,0,.2)';
loadingDiv.textContent = 'Útvonal tervezése…';
document.getElementById('map').style.position = 'relative';
document.getElementById('map').appendChild(loadingDiv);

drawRoutes().finally(() => loadingDiv.remove());
</script>
</body>
</html>

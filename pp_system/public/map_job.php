<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';
require_login_or_redirect();

$jobId = (int)($_GET['job_id'] ?? 0);
if (!$jobId) { header('Location: records.php'); exit; }

$db = db();

$job = $db->prepare("SELECT id, title FROM om_jobs WHERE id = ?");
$job->execute([$jobId]);
$job = $job->fetch(PDO::FETCH_ASSOC);
if (!$job) { header('Location: records.php'); exit; }

$photos = $db->prepare("
    SELECT p.id, p.file_path, p.note, p.gps_lat, p.gps_lng, p.uploaded_at, u.name AS uploader
    FROM om_job_photos p
    JOIN users u ON u.id = p.user_id
    WHERE p.job_id = ? AND p.gps_lat IS NOT NULL AND p.gps_lng IS NOT NULL
    ORDER BY p.uploaded_at ASC
");
$photos->execute([$jobId]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fotók térképe – <?=h($job['title'])?></title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <style>
    body { margin: 0; font-family: sans-serif; background: #1a1a2e; color: #eee; }
    #header { padding: 10px 16px; background: #16213e; display: flex; align-items: center; gap: 12px; }
    #header a { color: #aaa; text-decoration: none; font-size: .9rem; }
    #header a:hover { color: #fff; }
    #header h1 { margin: 0; font-size: 1rem; font-weight: 600; flex: 1; }
    #map { height: calc(100vh - 48px); }
    .no-gps { padding: 2rem; text-align: center; color: #aaa; }
    .leaflet-popup-content { font-size: .85rem; }
    .leaflet-popup-content img { max-width: 180px; border-radius: 4px; display: block; margin-bottom: 6px; }
    .streetview-link { display: inline-block; margin-top: 6px; color: #4ea1ff; font-size: .82rem; }
  </style>
</head>
<body>
<div id="header">
  <a href="om_job_view.php?id=<?=$jobId?>">&larr; Vissza</a>
  <h1>📍 <?=h($job['title'])?></h1>
  <span style="font-size:.8rem; color:#888"><?=count($photos)?> GPS-es fotó</span>
</div>

<?php if (empty($photos)): ?>
  <div class="no-gps">Ehhez a munkához nincs GPS koordinátával rendelkező fotó.</div>
<?php else: ?>
  <div id="map"></div>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    const photos = <?= json_encode(array_map(fn($p) => [
        'lat'      => (float)$p['gps_lat'],
        'lng'      => (float)$p['gps_lng'],
        'file'     => $baseUrl . '/' . $p['file_path'],
        'note'     => $p['note'] ?? '',
        'uploader' => $p['uploader'],
        'time'     => $p['uploaded_at'],
    ], $photos), JSON_UNESCAPED_UNICODE) ?>;

    const map = L.map('map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    const bounds = [];
    photos.forEach(function(p) {
      const marker = L.marker([p.lat, p.lng]).addTo(map);
      const svUrl  = `https://www.google.com/maps?q=&layer=c&cbll=${p.lat},${p.lng}`;
      marker.bindPopup(`
        <img src="${p.file}" alt="fotó"><br>
        <strong>${p.uploader}</strong> – ${p.time}<br>
        ${p.note ? p.note + '<br>' : ''}
        <a class="streetview-link" href="${svUrl}" target="_blank">🔭 Street View megnyitása</a>
      `);
      bounds.push([p.lat, p.lng]);
    });

    map.fitBounds(bounds, { padding: [40, 40] });
  </script>
<?php endif; ?>
</body>
</html>

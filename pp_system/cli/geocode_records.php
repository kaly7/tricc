<?php
// CLI: php geocode_records.php
// Végigmegy a records tábla GPS nélküli sorain és Nominatim-mal geocodol.
// Nominatim policy: max 1 kérés/mp, User-Agent kötelező.

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

$db = db();

$rows = $db->query("
    SELECT r.id, r.address, c.name AS city_name
    FROM records r
    JOIN cities c ON c.id = r.city_id
    WHERE r.gps_lat IS NULL AND r.deleted_at IS NULL
    ORDER BY r.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$total   = count($rows);
$ok      = 0;
$failed  = 0;

echo "GPS nélküli rekordok: {$total}\n";

foreach ($rows as $row) {
    $fullAddress = trim($row['city_name'] . ' ' . $row['address']);
    $geo = geocode_address($fullAddress);
    $fallback = false;

    if (!$geo && $row['city_name'] !== '') {
        sleep(1);
        $geo = geocode_address($row['city_name']);
        $fallback = true;
    }

    if ($geo) {
        $db->prepare("UPDATE records SET gps_lat=?, gps_lng=? WHERE id=?")
           ->execute([$geo['lat'], $geo['lng'], $row['id']]);
        $tag = $fallback ? '[FB] ' : '[OK] ';
        echo "{$tag} id={$row['id']} | " . ($fallback ? $row['city_name'] : $fullAddress) . " → {$geo['lat']}, {$geo['lng']}\n";
        $ok++;
    } else {
        echo "[---] id={$row['id']} | {$fullAddress} → nem találta\n";
        $failed++;
    }

    sleep(1); // Nominatim: max 1 kérés/mp
}

echo "\nKész. Sikeres: {$ok}, sikertelen: {$failed}, összesen: {$total}\n";

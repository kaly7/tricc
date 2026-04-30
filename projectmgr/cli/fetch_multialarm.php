<?php
/**
 * Multi Alarm GPS – napi km + útvonal lekérdező
 *
 * Futtatás: php /var/www/html/projectmgr/cli/fetch_multialarm.php [YYYY-MM-DD]
 * Cron (minden nap reggel 6:00): 0 6 * * * php /var/www/html/projectmgr/cli/fetch_multialarm.php
 *
 * Ha nincs dátum argumentum, tegnap adatait kéri le.
 * Ugyanaz a nap újra lekérve: az összes meglévő adat felülíródik (DELETE + INSERT).
 */

declare(strict_types=1);

$cfg = require dirname(__DIR__) . '/config/config.php';
$pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], $cfg['db']['options']);

$ma_user = $cfg['multialarm']['user'];
$ma_pass = $cfg['multialarm']['pass'];
$ma_url  = $cfg['multialarm']['url'];

// Lekérdezendő dátum
$date_arg = $argv[1] ?? null;
if ($date_arg && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_arg)) {
    $target_date = $date_arg;
} else {
    $target_date = date('Y-m-d', strtotime('-1 day'));
}

echo "[" . date('Y-m-d H:i:s') . "] Lekérdezés napja: $target_date\n";

// Aktív, multialarm-os járművek
$st = $pdo->query("
    SELECT id, license_plate, make, model
    FROM vehicles
    WHERE multialarm_enabled = 1
      AND license_plate IS NOT NULL
      AND license_plate != ''
      AND archived = 0
    ORDER BY license_plate
");
$vehicles = $st->fetchAll();

if (!$vehicles) {
    echo "Nincs multialarm_enabled=1 jármű.\n";
    exit(0);
}

echo "Feldolgozandó járművek száma: " . count($vehicles) . "\n";

$del_km    = $pdo->prepare("DELETE FROM vehicle_daily_km    WHERE vehicle_id=? AND km_date=?");
$del_trips = $pdo->prepare("DELETE FROM vehicle_daily_trips WHERE vehicle_id=? AND km_date=?");

$ins_km = $pdo->prepare("
    INSERT INTO vehicle_daily_km (vehicle_id, km_date, total_km, trip_count, fetched_at)
    VALUES (?, ?, ?, ?, NOW())
");

$ins_trip = $pdo->prepare("
    INSERT INTO vehicle_daily_trips
      (vehicle_id, km_date, trip_no,
       departure_time, departure_addr, departure_lat, departure_lon,
       arrival_time,   arrival_addr,   arrival_lat,   arrival_lon,
       distance_km, fuel_l)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($vehicles as $v) {
    $plate = $v['license_plate'];
    $label = "$plate ({$v['make']} {$v['model']})";

    // Auth token generálás
    $st_unix = time();
    $inner   = md5($ma_pass . $ma_user);
    $code    = md5('md5' . $inner . $st_unix);

    $url = $ma_url . '?' . http_build_query([
        'user'  => $ma_user,
        'st'    => $st_unix,
        'code'  => $code,
        'plate' => $plate,
        'date'  => $target_date,
    ]);

    $ctx = stream_context_create(['http' => [
        'timeout'    => 15,
        'user_agent' => 'VehicleMgr/1.0',
    ]]);

    $xml_raw = @file_get_contents($url, false, $ctx);

    if ($xml_raw === false) {
        echo "  [$label] HIBA: nem sikerült elérni a szervert.\n";
        continue;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_raw);
    if ($xml === false) {
        echo "  [$label] HIBA: érvénytelen XML válasz.\n";
        continue;
    }

    $error_msg = trim((string)($xml->error ?? ''));
    if ($error_msg !== '') {
        echo "  [$label] API hiba: $error_msg\n";
        continue;
    }

    // Szakaszok összegyűjtése — az utolsó "összesítő" item kiszűrésével
    // Az összesítő sornak nincs érvényes koordinátája (dep_lat/lon és arr_lat/lon is üres)
    $trips = [];
    foreach ($xml->item as $item) {
        $dep_lat = trim((string)($item->departure_coord->latitude  ?? ''));
        $dep_lon = trim((string)($item->departure_coord->longitude ?? ''));
        $arr_lat = trim((string)($item->arrival_coord->latitude    ?? ''));
        $arr_lon = trim((string)($item->arrival_coord->longitude   ?? ''));

        // Ha nincs egyetlen érvényes koordináta sem → összesítő sor, kihagyjuk
        $has_coords = ($dep_lat !== '' && $dep_lon !== '')
                   || ($arr_lat !== '' && $arr_lon !== '');
        if (!$has_coords) continue;

        $trips[] = [
            'dep_time' => trim((string)($item->departure         ?? '')),
            'dep_addr' => trim((string)($item->departure_address ?? '')),
            'dep_lat'  => $dep_lat,
            'dep_lon'  => $dep_lon,
            'arr_time' => trim((string)($item->arrival           ?? '')),
            'arr_addr' => trim((string)($item->arrival_address   ?? '')),
            'arr_lat'  => $arr_lat,
            'arr_lon'  => $arr_lon,
            'km'       => (float)($item->distance ?? 0),
            'fuel'     => (float)($item->fuel     ?? 0),
        ];
    }

    $total_km   = round(array_sum(array_column($trips, 'km')), 1);
    $trip_count = count($trips);

    // Felülírás: töröljük a régi adatokat, majd újra beírjuk
    try {
        $pdo->beginTransaction();

        $del_km->execute([$v['id'], $target_date]);
        $del_trips->execute([$v['id'], $target_date]);

        if ($trip_count > 0) {
            $ins_km->execute([$v['id'], $target_date, $total_km, $trip_count]);

            foreach ($trips as $i => $t) {
                $ins_trip->execute([
                    $v['id'], $target_date, $i + 1,
                    $t['dep_time'] !== '' ? $t['dep_time'] : null,
                    $t['dep_addr'] !== '' ? $t['dep_addr'] : null,
                    $t['dep_lat']  !== '' ? $t['dep_lat']  : null,
                    $t['dep_lon']  !== '' ? $t['dep_lon']  : null,
                    $t['arr_time'] !== '' ? $t['arr_time'] : null,
                    $t['arr_addr'] !== '' ? $t['arr_addr'] : null,
                    $t['arr_lat']  !== '' ? $t['arr_lat']  : null,
                    $t['arr_lon']  !== '' ? $t['arr_lon']  : null,
                    $t['km'] > 0 ? $t['km'] : null,
                    $t['fuel'] > 0 ? $t['fuel'] : null,
                ]);
            }
        }

        $pdo->commit();
        echo "  [$label] OK: $total_km km, $trip_count szakasz\n";

    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  [$label] DB HIBA: " . $e->getMessage() . "\n";
    }

    usleep(300000);
}

echo "[" . date('Y-m-d H:i:s') . "] Kész.\n";

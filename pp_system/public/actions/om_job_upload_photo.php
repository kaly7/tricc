<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../../src/db.php';

$job_id = (int)($_POST['job_id'] ?? 0);
if (!$job_id) { die('Invalid job'); }

// könyvtár
$uploadDir = __DIR__ . '/../../storage/om_photos/' . $job_id . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {

    if (!is_uploaded_file($tmp)) continue;

    $name = basename($_FILES['photos']['name'][$i]);

    $target = $uploadDir . time() . "_" . $name;

    move_uploaded_file($tmp, $target);

    $relPath = 'storage/om_photos/' . $job_id . '/' . basename($target);

    $gpsLat = isset($_POST['gps_lat']) && $_POST['gps_lat'] !== '' ? (float)$_POST['gps_lat'] : null;
    $gpsLng = isset($_POST['gps_lng']) && $_POST['gps_lng'] !== '' ? (float)$_POST['gps_lng'] : null;

    db()->prepare("
        INSERT INTO om_job_photos (job_id, user_id, file_path, original_name, gps_lat, gps_lng)
        VALUES (?,?,?,?,?,?)
    ")->execute([
        $job_id,
        current_user()['id'],
        $relPath,
        $name,
        $gpsLat,
        $gpsLng
    ]);
}

header("Location: ../my_om_job.php?id=".$job_id);
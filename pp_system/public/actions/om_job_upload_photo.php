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

    db()->prepare("
        INSERT INTO om_job_photos (job_id, user_id, file_path, original_name)
        VALUES (?,?,?,?)
    ")->execute([
        $job_id,
        $_SESSION['user']['id'],
        $relPath,
        $name
    ]);
}

header("Location: ../my_om_job.php?id=".$job_id);
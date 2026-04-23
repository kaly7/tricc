<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../../src/db.php';

if (is_worker()) { http_response_code(403); exit; }

header('Content-Type: application/json; charset=utf-8');

$photoId = (int)($_POST['photo_id'] ?? 0);
if (!$photoId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Hiányzó photo_id']);
    exit;
}

$db = db();

$photo = $db->prepare("SELECT * FROM om_job_photos WHERE id = ?");
$photo->execute([$photoId]);
$photo = $photo->fetch(PDO::FETCH_ASSOC);

if (!$photo) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Fotó nem található']);
    exit;
}

$filePath = __DIR__ . '/../../' . $photo['file_path'];
if (file_exists($filePath)) {
    unlink($filePath);
}

$db->prepare("DELETE FROM om_job_photos WHERE id = ?")->execute([$photoId]);

log_om_job_event(
    $db,
    $photo['job_id'],
    current_user()['id'],
    'photo_delete',
    'Fotó törölve: ' . $photo['original_name'] . ' (feltöltve: ' . $photo['uploaded_at'] . ')'
);

echo json_encode(['ok' => true]);

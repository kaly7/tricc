<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

header('Content-Type: application/json');

$uploadId = (int)($_GET['upload_id'] ?? 0);
if ($uploadId <= 0) { echo json_encode(['error' => 'invalid']); exit; }

$file = TMP_DIR . '/test_' . $uploadId . '.json';
if (!is_file($file)) {
    echo json_encode(['running' => true, 'done' => 0, 'total' => 0, 'pages' => []]);
    exit;
}

$raw = file_get_contents($file);
$data = json_decode($raw, true);
echo json_encode($data ?? ['error' => 'parse_error']);

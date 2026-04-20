<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0) { http_response_code(400); echo "Bad job_id"; exit; }

$pdo = Db::pdo();
$st = $pdo->prepare("SELECT id, upload_id, page_no, extracted_name, output_path FROM page_jobs WHERE id=? LIMIT 1");
$st->execute([$jobId]);
$row = $st->fetch();
if (!$row) { http_response_code(404); echo "Not found"; exit; }

$path = $row['output_path'] ?? '';
if (!$path || !is_file($path)) { http_response_code(404); echo "File missing"; exit; }

// Only allow downloads from OUTPUT_DIR to avoid path traversal
$real = realpath($path);
$outDir = realpath(OUTPUT_DIR);
if (!$real || !$outDir || strpos($real, $outDir) !== 0) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$baseName = basename($real);
$dispName = $baseName;
if (!empty($row['extracted_name'])) {
    $safe = preg_replace('/\s+/u', '_', trim($row['extracted_name']));
    $safe = preg_replace('/[^0-9A-Za-zÁÉÍÓÖŐÚÜŰáéíóöőúüű_\-]/u', '', $safe);
    if ($safe) $dispName = $safe . '.pdf';
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $dispName . '"');
header('Content-Length: ' . filesize($real));
readfile($real);
exit;

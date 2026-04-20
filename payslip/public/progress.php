<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$uploadId = (int)($_GET['upload_id'] ?? 0);
if ($uploadId <= 0) { header('Content-Type: application/json'); echo json_encode(['error'=>'bad upload_id']); exit; }

$pdo = Db::pdo();
$u = $pdo->prepare("SELECT total_pages FROM uploads WHERE id=?");
$u->execute([$uploadId]);
$upload = $u->fetch();
$total = $upload ? (int)$upload['total_pages'] : 0;

$q = $pdo->prepare("
  SELECT
    SUM(status IN ('SAVED','MAILED','NO_MATCH','ERROR')) AS done,
    SUM(status = 'MAILED') AS mailed,
    SUM(status = 'SAVED') AS saved,
    SUM(status = 'ERROR') AS error_cnt,
    SUM(status = 'NO_MATCH') AS no_match,
    MAX(CASE WHEN status IN ('SAVED','MAILED','NO_MATCH','ERROR') THEN page_no ELSE 0 END) AS current_page
  FROM page_jobs
  WHERE upload_id=?
");
$q->execute([$uploadId]);
$s = $q->fetch() ?: [];

$done = (int)($s['done'] ?? 0);
$running = ($total > 0) && ($done < $total);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'total' => $total,
  'done' => $done,
  'mailed' => (int)($s['mailed'] ?? 0),
  'saved' => (int)($s['saved'] ?? 0),
  'no_match' => (int)($s['no_match'] ?? 0),
  'error' => (int)($s['error_cnt'] ?? 0),
  'current_page' => (int)($s['current_page'] ?? 0),
  'running' => $running
], JSON_UNESCAPED_UNICODE);

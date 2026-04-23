<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php'; require_once __DIR__.'/../../src/helpers.php';

$db = db();
$u = current_user();
$jobId = (int)($_POST['job_id'] ?? 0);
$message = trim((string)($_POST['message'] ?? ''));
if ($jobId <= 0 || $message === '') { header('Location: ../my_om_jobs.php'); exit; }

if (!is_admin()) {
  $st = $db->prepare('SELECT 1 FROM om_job_workers WHERE job_id=? AND user_id=? LIMIT 1');
  $st->execute([$jobId, $u['id']]);
  if (!$st->fetchColumn()) { http_response_code(403); echo 'Forbidden'; exit; }
}

log_om_job_event($db, $jobId, $u['id'], 'comment', $message);
header('Location: ../my_om_job.php?id=' . $jobId . '&msg=' . urlencode('Megjegyzés elmentve.'));

<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php'; require_once __DIR__.'/../../src/helpers.php';

$db = db();
$u = current_user();
$jobId = (int)($_POST['job_id'] ?? 0);
$statusId = (int)($_POST['status_id'] ?? 0);
if ($jobId <= 0 || $statusId <= 0) { header('Location: ../my_om_jobs.php'); exit; }

if (!is_admin()) {
  $st = $db->prepare('SELECT 1 FROM om_job_workers WHERE job_id=? AND user_id=? LIMIT 1');
  $st->execute([$jobId, $u['id']]);
  if (!$st->fetchColumn()) { http_response_code(403); echo 'Forbidden'; exit; }
}

$st = $db->prepare('SELECT s.name FROM om_jobs j JOIN om_job_statuses s ON s.id=j.status_id WHERE j.id=? LIMIT 1');
$st->execute([$jobId]);
$oldName = (string)$st->fetchColumn();

$st = $db->prepare('SELECT name FROM om_job_statuses WHERE id=? LIMIT 1');
$st->execute([$statusId]);
$newName = (string)$st->fetchColumn();
if ($newName === '') { header('Location: ../my_om_job.php?id=' . $jobId . '&err=' . urlencode('Érvénytelen státusz.')); exit; }

$st = $db->prepare('UPDATE om_jobs SET status_id=? WHERE id=?');
$st->execute([$statusId, $jobId]);

log_om_job_event($db, $jobId, $u['id'], 'status_change', 'Státusz módosítva: ' . $oldName . ' → ' . $newName);

header('Location: ../my_om_job.php?id=' . $jobId . '&msg=' . urlencode('Státusz módosítva.'));

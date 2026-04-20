<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php'; require_once __DIR__.'/../../src/helpers.php';

$db = db();
$u = current_user();
$jobId = (int)($_POST['job_id'] ?? 0);
$workDate = trim((string)($_POST['work_date'] ?? ''));
$timeFrom = trim((string)($_POST['time_from'] ?? ''));
$timeTo = trim((string)($_POST['time_to'] ?? ''));
$note = substr(trim((string)($_POST['note'] ?? '')), 0, 255);
if ($jobId <= 0 || $workDate === '' || $timeFrom === '' || $timeTo === '') { header('Location: ../my_om_jobs.php'); exit; }

if (!is_admin()) {
  $st = $db->prepare('SELECT 1 FROM om_job_workers WHERE job_id=? AND user_id=? LIMIT 1');
  $st->execute([$jobId, $u['id']]);
  if (!$st->fetchColumn()) { http_response_code(403); echo 'Forbidden'; exit; }
}

$minutes = null;
try {
  $dt1 = new DateTime($workDate . ' ' . $timeFrom);
  $dt2 = new DateTime($workDate . ' ' . $timeTo);
  $diff = $dt2->getTimestamp() - $dt1->getTimestamp();
  if ($diff > 0) $minutes = (int) floor($diff / 60);
} catch (Throwable $e) {
  $minutes = null;
}

$st = $db->prepare('INSERT INTO om_job_worktimes (job_id, user_id, work_date, time_from, time_to, minutes, note) VALUES (?,?,?,?,?,?,?)');
$st->execute([$jobId, $u['id'], $workDate, $timeFrom, $timeTo, $minutes, $note !== '' ? $note : null]);

$logMsg = 'Munkaidő rögzítve: ' . $workDate . ' ' . $timeFrom . ' - ' . $timeTo;
if ($minutes) $logMsg .= ' (' . $minutes . ' perc)';
if ($note !== '') $logMsg .= ' | ' . $note;
$st = $db->prepare('INSERT INTO om_job_logs (job_id, user_id, log_type, message) VALUES (?,?,?,?)');
$st->execute([$jobId, $u['id'], 'work_report', $logMsg]);

header('Location: ../my_om_job.php?id=' . $jobId . '&msg=' . urlencode('Munkaidő elmentve.'));

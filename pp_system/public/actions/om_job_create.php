<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__.'/../../src/db.php'; require_once __DIR__.'/../../src/helpers.php';

$db = db();
$u = current_user();

$recordId    = (int)($_POST['record_id'] ?? 0);
$title       = substr(trim((string)($_POST['title'] ?? '')), 0, 190);
$description = trim((string)($_POST['description'] ?? ''));
$statusId    = (int)($_POST['status_id'] ?? 0);
$priority    = substr(trim((string)($_POST['priority'] ?? 'normal')), 0, 20);
$plannedDate = trim((string)($_POST['planned_date'] ?? ''));
$workerIds   = $_POST['worker_ids'] ?? [];
if (!is_array($workerIds)) $workerIds = [$workerIds];
$workerIds   = array_values(array_unique(array_filter(array_map('intval', $workerIds), fn($v) => $v > 0)));

if ($recordId <= 0 || $title === '') {
    header('Location: ../records.php'); exit;
}
if ($statusId <= 0) {
    header('Location: ../om_job_new.php?record_id=' . $recordId . '&err=status'); exit;
}
if (!$workerIds) {
    header('Location: ../om_job_new.php?record_id=' . $recordId . '&err=worker'); exit;
}
if ($plannedDate === '') $plannedDate = null;

$st = $db->prepare('SELECT id FROM records WHERE id=? AND deleted_at IS NULL LIMIT 1');
$st->execute([$recordId]);
if (!$st->fetchColumn()) {
    header('Location: ../records.php'); exit;
}

$st = $db->prepare('SELECT id FROM om_jobs WHERE record_id=? LIMIT 1');
$st->execute([$recordId]);
$existingJobId = $st->fetchColumn();
if ($existingJobId) {
    header('Location: ../om_job_view.php?id='.(int)$existingJobId); exit;
}

$db->beginTransaction();
try {
    $st = $db->prepare('INSERT INTO om_jobs (record_id, title, description, status_id, priority, planned_date, assigned_by) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$recordId, $title, $description !== '' ? $description : null, $statusId, $priority !== '' ? $priority : 'normal', $plannedDate, $u['id']]);
    $jobId = (int)$db->lastInsertId();

    $stWorker = $db->prepare('INSERT INTO om_job_workers (job_id, user_id, assigned_by) VALUES (?,?,?)');
    foreach ($workerIds as $wid) {
        $stWorker->execute([$jobId, $wid, $u['id']]);
    }

    $stLog = $db->prepare('INSERT INTO om_job_logs (job_id, user_id, log_type, message) VALUES (?,?,?,?)');
    $stLog->execute([$jobId, $u['id'], 'system', 'O&M munka létrehozva.']);

    $db->commit();
    header('Location: ../om_job_view.php?id='.$jobId);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php'; require_once __DIR__.'/../../src/helpers.php';
if (!is_admin()) { http_response_code(403); echo 'Forbidden'; exit; }

$jobId  = (int)($_POST['job_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);
if (!$jobId || !$userId) { header('Location: ../om_job_view.php?id='.$jobId.'&err=bad'); exit; }

$db = db();
$exists = $db->prepare('SELECT 1 FROM om_job_workers WHERE job_id=? AND user_id=? LIMIT 1');
$exists->execute([$jobId, $userId]);
if (!$exists->fetchColumn()) {
    $db->prepare('INSERT INTO om_job_workers (job_id, user_id, assigned_by) VALUES (?,?,?)
    ')->execute([$jobId, $userId, current_user()['id']]);
    $name = $db->prepare('SELECT name FROM users WHERE id=? LIMIT 1');
    $name->execute([$userId]);
    log_om_job_event($db, $jobId, current_user()['id'], 'system', 'Dolgozó hozzáadva: '.($name->fetchColumn() ?: $userId));
}
header('Location: ../om_job_view.php?id='.$jobId); exit;

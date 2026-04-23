<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';

$id    = (int)($_POST['id'] ?? 0);
$jobId = (int)($_POST['job_id'] ?? 0);
if (!$id || !$jobId) { header('Location: ../my_om_job.php?id='.$jobId.'&err=bad'); exit; }

db()->prepare('DELETE FROM om_job_materials WHERE id=? AND job_id=?')->execute([$id, $jobId]);
header('Location: ../my_om_job.php?id='.$jobId); exit;

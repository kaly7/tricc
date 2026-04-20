<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

if (!Auth::isAdmin()) { http_response_code(403); echo "Forbidden"; exit; }

use Services\MailService;
use Services\LoggerService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

$jobId = (int)($_POST['job_id'] ?? 0);
if ($jobId <= 0) { http_response_code(400); echo "Bad job_id"; exit; }

$toEmailOverride = trim((string)($_POST['to_email'] ?? ''));

$pdo = Db::pdo();
$st = $pdo->prepare("SELECT id, upload_id, page_no, extracted_name, email_to, output_path, status FROM page_jobs WHERE id=? LIMIT 1");
$st->execute([$jobId]);
$row = $st->fetch();
if (!$row) { http_response_code(404); echo "Not found"; exit; }

$name  = (string)($row['extracted_name'] ?? '');
$file  = (string)($row['output_path'] ?? '');
$status = (string)($row['status'] ?? '');

$email = (string)($row['email_to'] ?? '');
if ($toEmailOverride !== '') {
    $email = $toEmailOverride;
}

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    LoggerService::log('ERROR', 'RESEND_FAIL', 'Küldés: hibás email', (int)$row['upload_id'], $jobId, ['email'=>$email,'override_from_form'=>$toEmailOverride ?: null]);
    header("Location: log.php?upload_id=".(int)$row['upload_id']."&msg=bad_email");
    exit;
}
if (!$file || !is_file($file)) {
    LoggerService::log('ERROR', 'RESEND_FAIL', 'Küldés: hiányzó PDF', (int)$row['upload_id'], $jobId, ['file'=>$file]);
    header("Location: log.php?upload_id=".(int)$row['upload_id']."&msg=missing_file");
    exit;
}

$u = $pdo->prepare("SELECT u.month, d.name AS division_name FROM uploads u LEFT JOIN divisions d ON d.id=u.division_id WHERE u.id=? LIMIT 1");
$u->execute([(int)$row['upload_id']]);
$up = $u->fetch();
$month = $up['month'] ?? '';
$divName = $up['division_name'] ?? '';

$overrideTo = defined('MAIL_OVERRIDE_TO') ? (string)MAIL_OVERRIDE_TO : '';
$dryRun = defined('MAIL_DRY_RUN') ? (bool)MAIL_DRY_RUN : false;

$subject = "Számfejtő lap - {$month}" . ($divName ? " ({$divName})" : "");
$body = "Szia {$name}!\n\nCsatolva küldjük a számfejtő lapodat ({$month}" . ($divName ? " / {$divName}" : "") . ").\n\nÜdv,\n" . SMTP_FROM_NAME;

if ($dryRun) {
    LoggerService::log('INFO', 'SEND_DRY_RUN', 'Dry-run: küldés nem történt meg', (int)$row['upload_id'], $jobId, ['to'=>$email,'override_to'=>$overrideTo ?: null]);
    header("Location: log.php?upload_id=".(int)$row['upload_id']."&msg=dry_run");
    exit;
}

try {
    MailService::sendWithAttachment($email, $name, $subject, $body, $file, $overrideTo ?: null);

    // If email came from manual input (NO_MATCH case), store it for traceability
    if ($toEmailOverride !== '') {
        $pdo->prepare("UPDATE page_jobs SET email_to=? WHERE id=?")->execute([$email, $jobId]);
    }

    $pdo->prepare("UPDATE page_jobs SET status='MAILED', sent_at=NOW(), error_message=NULL WHERE id=?")->execute([$jobId]);

    LoggerService::log('INFO', 'MAIL_SENT_MANUAL', 'Email elküldve (kézi)', (int)$row['upload_id'], $jobId, [
        'to'=>$email,
        'prev_status'=>$status,
        'override_from_form'=>$toEmailOverride ?: null,
        'override_to'=>$overrideTo ?: null
    ]);

    header("Location: log.php?upload_id=".(int)$row['upload_id']."&msg=sent");
    exit;
} catch (Throwable $e) {
    $pdo->prepare("UPDATE page_jobs SET status='ERROR', error_message=? WHERE id=?")
        ->execute(["Küldés hiba: " . $e->getMessage(), $jobId]);
    LoggerService::log('ERROR', 'SEND_FAIL', 'Email küldés hiba', (int)$row['upload_id'], $jobId, ['err'=>$e->getMessage(),'to'=>$email]);
    header("Location: log.php?upload_id=".(int)$row['upload_id']."&msg=fail");
    exit;
}

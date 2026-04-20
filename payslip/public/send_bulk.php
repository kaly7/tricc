<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

if (!Auth::isAdmin()) { http_response_code(403); echo "Forbidden"; exit; }

use Services\MailService;
use Services\LoggerService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo "Method not allowed"; exit; }

$employeeId = (int)($_POST['employee_id'] ?? 0);
$jobIds = $_POST['job_ids'] ?? [];
$returnQ = (string)($_POST['return_q'] ?? '');
$scope = (string)($_POST['scope'] ?? 'employees');
$pdfName = (string)($_POST['pdf_name'] ?? '');
$toEmailOverride = trim((string)($_POST['to_email'] ?? ''));

if ($employeeId <= 0 && $scope === 'employees') { header("Location: employee_pdfs.php?q=" . urlencode($returnQ)); exit; }
if (!is_array($jobIds)) $jobIds = [];

$jobIds = array_values(array_unique(array_map('intval', $jobIds)));
$jobIds = array_filter($jobIds, fn($x)=>$x>0);
if (count($jobIds) === 0) {
    $redir = "employee_pdfs.php?q=" . urlencode($returnQ) . "&scope=" . urlencode($scope) . "&mode=exact";
    if ($scope === 'employees') $redir .= "&employee_id=" . $employeeId;
    else $redir .= "&pdf_name=" . urlencode($pdfName);
    header("Location: " . $redir);
    exit;
}
if (count($jobIds) > 200) {
    // hard limit
    $jobIds = array_slice($jobIds, 0, 200);
}

$pdo = Db::pdo();

// target email + name
$to = '';
$name = '';

if ($scope === 'employees') {
    // employee
    $st = $pdo->prepare("SELECT id,name,email FROM employees WHERE id=? LIMIT 1");
    $st->execute([$employeeId]);
    $emp = $st->fetch();
    if (!$emp) {
        header("Location: employee_pdfs.php?q=" . urlencode($returnQ));
        exit;
    }
    $name = (string)$emp['name'];
    $to = (string)$emp['email'];
} else {
    // pdf-scope bulk send: name is optional (use pdf_name)
    $name = $pdfName ?: 'Dolgozó';
}

// override target email if provided
if ($toEmailOverride !== '') {
    $to = $toEmailOverride;
}

if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    LoggerService::log('ERROR','BULK_SEND_FAIL','Tömeges küldés: hibás cél email', null, null, ['employee_id'=>$employeeId,'scope'=>$scope,'email'=>$to,'override'=>$toEmailOverride ?: null]);
    $redir = "employee_pdfs.php?q=" . urlencode($returnQ) . "&scope=" . urlencode($scope) . "&mode=exact";
    if ($scope === 'employees') $redir .= "&employee_id=" . $employeeId;
    else $redir .= "&pdf_name=" . urlencode($pdfName);
    header("Location: " . $redir . "&msg=bulk_fail");
    exit;
}
$overrideTo = defined('MAIL_OVERRIDE_TO') ? (string)MAIL_OVERRIDE_TO : '';
$dryRun = defined('MAIL_DRY_RUN') ? (bool)MAIL_DRY_RUN : false;

try {
    foreach ($jobIds as $jobId) {
        $st = $pdo->prepare("
          SELECT pj.*, u.month, d.name AS division_name
          FROM page_jobs pj
          JOIN uploads u ON u.id = pj.upload_id
          LEFT JOIN divisions d ON d.id = u.division_id
          WHERE pj.id=? LIMIT 1
        ");
        $st->execute([$jobId]);
        $p = $st->fetch();
        if (!$p) continue;

        $file = (string)($p['output_path'] ?? '');
        if (!$file || !is_file($file)) continue;

        $month = (string)($p['month'] ?? '');
        $divName = (string)($p['division_name'] ?? '');
        $subject = "Számfejtő lap - {$month}" . ($divName ? " ({$divName})" : "");
        $body = "Szia {$name}!\n\nCsatolva küldjük a számfejtő lapodat ({$month}" . ($divName ? " / {$divName}" : "") . ").\n\nÜdv,\n" . SMTP_FROM_NAME;

        if ($dryRun) {
            LoggerService::log('INFO','BULK_SEND_DRY_RUN','Dry-run: tömeges küldés (nem küldtem)', (int)$p['upload_id'], (int)$p['id'], ['to'=>$to,'override_to'=>$overrideTo ?: null]);
            continue;
        }

        MailService::sendWithAttachment($to, $name, $subject, $body, $file, $overrideTo ?: null);
        $pdo->prepare("UPDATE page_jobs SET status='MAILED', sent_at=NOW(), error_message=NULL, email_to=? WHERE id=?")
            ->execute([$to, $jobId]);

        LoggerService::log('INFO','BULK_SENT','Tömeges küldés: elküldve', (int)$p['upload_id'], (int)$p['id'], ['to'=>$to,'override_to'=>$overrideTo ?: null]);
    }

    $redir = "employee_pdfs.php?q=" . urlencode($returnQ) . "&scope=" . urlencode($scope) . "&mode=exact";
    if ($scope === 'employees') $redir .= "&employee_id=" . $employeeId;
    else $redir .= "&pdf_name=" . urlencode($pdfName);
    header("Location: " . $redir . "&msg=bulk_sent");
    exit;
} catch (\Throwable $e) {
    LoggerService::log('ERROR','BULK_SEND_FAIL','Tömeges küldés hiba: '.$e->getMessage(), null, null, ['employee_id'=>$employeeId,'to'=>$to]);
    $redir = "employee_pdfs.php?q=" . urlencode($returnQ) . "&scope=" . urlencode($scope) . "&mode=exact";
    if ($scope === 'employees') $redir .= "&employee_id=" . $employeeId;
    else $redir .= "&pdf_name=" . urlencode($pdfName);
    header("Location: " . $redir . "&msg=bulk_fail");
    exit;
}

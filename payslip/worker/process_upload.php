<?php
require __DIR__ . '/../bootstrap.php';

use Services\PdfService;
use Services\EmployeeService;
use Services\LoggerService;
use Services\MailService;

require_once __DIR__ . '/TaxIdExtractor.php';
require_once __DIR__ . '/EmployeeUpsert.php';

$uploadId = (int)($argv[1] ?? 0);
if ($uploadId <= 0) {
    echo "Usage: php process_upload.php <upload_id>\n";
    exit(1);
}

$pdo = Db::pdo();

$u = $pdo->prepare("
  SELECT u.*, d.slug AS division_slug, d.name AS division_name
  FROM uploads u
  LEFT JOIN divisions d ON d.id = u.division_id
  WHERE u.id=?
");
$u->execute([$uploadId]);
$upload = $u->fetch();
if (!$upload) {
    echo "Upload not found\n";
    exit(1);
}

$pdfPath = $upload['stored_path'];
$month   = $upload['month'];
$total   = (int)$upload['total_pages'];
$divSlug = $upload['division_slug'] ?: 'no-division';

$outputMonthDir = OUTPUT_DIR . '/' . $month . '/' . $divSlug;
$tmpMonthDir    = TMP_DIR . '/' . $month . '/' . $divSlug . '/split_' . $uploadId;
$noMatchDir     = $outputMonthDir . '/NO_MATCH';

@mkdir($outputMonthDir, 0770, true);
@mkdir($tmpMonthDir, 0770, true);
@mkdir($noMatchDir, 0770, true);

$overrideTo = defined('MAIL_OVERRIDE_TO') ? (string)MAIL_OVERRIDE_TO : '';
$dryRun = defined('MAIL_DRY_RUN') ? (bool)MAIL_DRY_RUN : false;

LoggerService::log('INFO', 'PROCESS_START', "Feldolgozás indul (split+mentés+email)", $uploadId, null, [
    'month' => $month,
    'division' => $divSlug,
    'pdf'   => $pdfPath,
    'dry_run' => $dryRun,
    'override_to' => $overrideTo ?: null
]);

/**
 * Run pdftotext for a single page pdf and return the plain text.
 */
function pagePdfToText(string $pagePdf): string {
    $cmd = 'pdftotext ' . escapeshellarg($pagePdf) . ' -';
    $out = shell_exec($cmd);
    return is_string($out) ? $out : '';
}

/**
 * Fetch employee by id.
 */
function findEmployeeById(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT id,name,email,tax_id FROM employees WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Fetch employee by tax_id (10 digits).
 */
function findEmployeeByTaxId(PDO $pdo, string $taxId): ?array {
    $taxId = preg_replace('/\D+/', '', $taxId);
    if (!preg_match('/^\d{10}$/', $taxId)) return null;
    $st = $pdo->prepare("SELECT id,name,email,tax_id FROM employees WHERE tax_id=? LIMIT 1");
    $st->execute([$taxId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

try {
    // 1) Split
    PdfService::splitToPages($pdfPath, $tmpMonthDir);
    LoggerService::log('INFO', 'SPLIT', "Szétbontás kész", $uploadId, null, ['dir' => $tmpMonthDir]);

    // 2) Oldal fájlok
    $pageFiles = glob($tmpMonthDir . '/page-*.pdf') ?: [];
    if (!$pageFiles) $pageFiles = glob($tmpMonthDir . '/*.pdf') ?: [];
    natsort($pageFiles);
    $pageFiles = array_values($pageFiles);

    if ($total <= 0) $total = count($pageFiles);

    // 3) Feldolgozás
    $idx = 0;
    foreach ($pageFiles as $pagePdf) {
        $idx++;

        $base = basename($pagePdf);

        $pageNo = 0;
        if (preg_match('/page-(\d+)\.pdf$/i', $base, $m)) $pageNo = (int)$m[1];
        if ($pageNo <= 0) $pageNo = $idx;

        // page_jobs upsert
        $pdo->prepare("INSERT IGNORE INTO page_jobs(upload_id, page_no, status) VALUES(?, ?, 'PENDING')")
            ->execute([$uploadId, $pageNo]);

        $stmt = $pdo->prepare("SELECT id,status FROM page_jobs WHERE upload_id=? AND page_no=?");
        $stmt->execute([$uploadId, $pageNo]);
        $job = $stmt->fetch();
        $jobId = (int)($job['id'] ?? 0);
        $prevStatus = (string)($job['status'] ?? 'PENDING');

        if (!MAIL_ALLOW_DUPLICATE_SENDS && $prevStatus === 'MAILED') {
            continue;
        }

        // Extract tax_id from pdftotext
        $pageText = pagePdfToText($pagePdf);
        $taxId = TaxIdExtractor::extract($pageText); // 10 digits or null

        // Existing name extraction
        $name = PdfService::extractNameFromPagePdf($pagePdf);
        if (!$name) {
            $pdo->prepare("UPDATE page_jobs SET tax_id=?, status='ERROR', error_message=? WHERE id=?")
                ->execute([$taxId, "Nem található Név mező", $jobId]);
            LoggerService::log('ERROR', 'EXTRACT_NAME', "Nem találtam nevet", $uploadId, $jobId, ['page' => $pageNo, 'file' => $base, 'tax_id' => $taxId]);
            continue;
        }

        $nameNorm = EmployeeService::normalizeName($name);
        $safe = PdfService::safeFileName($name);

        // employee match priority: tax_id first, then name_norm
        $emp = null;
        $autoCreateErr = null;

        if ($taxId) {
            $emp = findEmployeeByTaxId($pdo, $taxId);
            if (!$emp) {
                try {
                    $newId = EmployeeUpsert::upsertByTaxId($pdo, $name, $taxId, $nameNorm);
                    $emp = findEmployeeById($pdo, (int)$newId);
                    LoggerService::log('INFO', 'EMP_AUTO_CREATE', "Dolgozó automatikusan felvéve tax_id alapján", $uploadId, $jobId, [
                        'employee_id' => (int)$newId,
                        'name' => $name,
                        'tax_id' => $taxId
                    ]);
                } catch (Throwable $e) {
                    $autoCreateErr = $e->getMessage();
                    LoggerService::log('ERROR', 'EMP_AUTO_CREATE_FAIL', "Auto felvitel hiba: " . $autoCreateErr, $uploadId, $jobId, [
                        'name' => $name,
                        'tax_id' => $taxId
                    ]);
                }
            }
        }

        if (!$emp && $nameNorm !== '') {
            $emp = EmployeeService::findByNorm($nameNorm);
        }

        // Decide target dir:
        // If we matched by tax_id and auto-created, treat it as a MATCH even without email.
        $targetDir = $emp ? $outputMonthDir : $noMatchDir;
        $finalPath = $targetDir . '/' . $safe . '-p' . $pageNo . '.pdf';

        if (!copy($pagePdf, $finalPath)) {
            $pdo->prepare("UPDATE page_jobs SET extracted_name=?, extracted_name_norm=?, tax_id=?, status='ERROR', error_message=? WHERE id=?")
                ->execute([$name, $nameNorm, $taxId, "Nem tudtam menteni: $finalPath", $jobId]);
            LoggerService::log('ERROR', 'SAVE', "Mentés sikertelen", $uploadId, $jobId, ['page' => $pageNo, 'path' => $finalPath]);
            continue;
        }

        if (!$emp) {
            $msg = $autoCreateErr ? ("Auto-create hiba: " . $autoCreateErr) : null;
            $pdo->prepare("UPDATE page_jobs SET extracted_name=?, extracted_name_norm=?, tax_id=?, output_path=?, status='NO_MATCH', error_message=?, employee_id=NULL, email_to=NULL, sent_at=NULL WHERE id=?")
                ->execute([$name, $nameNorm, $taxId, $finalPath, $msg, $jobId]);
            LoggerService::log('WARN', 'NO_MATCH', "Mentve NO_MATCH mappába", $uploadId, $jobId, ['page' => $pageNo, 'name' => $name, 'tax_id' => $taxId, 'out' => $finalPath]);
            continue;
        }

        $empId = (int)($emp['id'] ?? 0);
        $empEmail = isset($emp['email']) ? trim((string)$emp['email']) : '';
        $emailTo = ($empEmail !== '') ? $empEmail : null;

        // 4) Match: SAVED (email optional)
        $pdo->prepare("UPDATE page_jobs SET extracted_name=?, extracted_name_norm=?, tax_id=?, employee_id=?, email_to=?, output_path=?, status='SAVED', error_message=NULL, sent_at=NULL WHERE id=?")
            ->execute([$name, $nameNorm, $taxId, $empId, $emailTo, $finalPath, $jobId]);

        LoggerService::log('INFO', 'SAVED', "Mentve (egyezés OK)", $uploadId, $jobId, [
            'page' => $pageNo,
            'name' => $name,
            'tax_id' => $taxId,
            'out' => $finalPath,
            'email' => $emailTo
        ]);

        // If no email, skip automatic sending (but keep SAVED + employee_id)
        if (!$emailTo) {
            LoggerService::log('WARN', 'NO_EMAIL', "Dolgozóhoz nincs email, automatikus küldés kihagyva", $uploadId, $jobId, [
                'employee_id' => $empId,
                'name' => $name,
                'tax_id' => $taxId
            ]);
            continue;
        }

        // 5) Email send
        $to = (string)$emailTo;
        $subject = "Számfejtő lap - {$month}";
        $body = "Szia {$name}!\n\nCsatolva küldjük a számfejtő lapodat ({$month}).\n\nÜdv,\n" . SMTP_FROM_NAME;

        if ($dryRun) {
            LoggerService::log('INFO', 'MAIL_DRY_RUN', "Dry-run: nem küldtem emailt", $uploadId, $jobId, ['to'=>$to, 'override_to'=>$overrideTo ?: null]);
            continue;
        }

        try {
            MailService::sendWithAttachment($to, $name, $subject, $body, $finalPath, $overrideTo ?: null);
            $pdo->prepare("UPDATE page_jobs SET status='MAILED', sent_at=NOW() WHERE id=?")->execute([$jobId]);
            LoggerService::log('INFO', 'MAILED', "Email elküldve", $uploadId, $jobId, ['to'=>$to, 'override_to'=>$overrideTo ?: null]);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE page_jobs SET status='ERROR', error_message=? WHERE id=?")
                ->execute(["Email hiba: " . $e->getMessage(), $jobId]);
            LoggerService::log('ERROR', 'MAIL_FAIL', "Email küldés hiba", $uploadId, $jobId, ['err'=>$e->getMessage(), 'to'=>$to]);
        }
    }

    LoggerService::log('INFO', 'PROCESS_DONE', "Feldolgozás kész (split+mentés+email)", $uploadId);

} catch (Throwable $e) {
    LoggerService::log('ERROR', 'PROCESS_FATAL', "Feldolgozás leállt: " . $e->getMessage(), $uploadId);
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

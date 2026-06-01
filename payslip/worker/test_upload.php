<?php
/**
 * Teszt worker – PDF feldolgozás éles hatások nélkül.
 * Nincs DB írás, nincs email küldés, nincs fájl másolás output-ba.
 * Eredmény: TMP_DIR/test_{uploadId}.json
 */
require __DIR__ . '/../bootstrap.php';

use Services\PdfService;
use Services\EmployeeService;

require_once __DIR__ . '/TaxIdExtractor.php';

$uploadId = (int)($argv[1] ?? 0);
if ($uploadId <= 0) {
    echo "Usage: php test_upload.php <upload_id>\n";
    exit(1);
}

$pdo = Db::pdo();

$u = $pdo->prepare("
    SELECT u.*, d.slug AS division_slug
    FROM uploads u
    LEFT JOIN divisions d ON d.id = u.division_id
    WHERE u.id = ?
");
$u->execute([$uploadId]);
$upload = $u->fetch();
if (!$upload) { echo "Upload not found\n"; exit(1); }

$resultFile = TMP_DIR . '/test_' . $uploadId . '.json';

function writeResult(array $data): void {
    global $resultFile;
    file_put_contents($resultFile, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function pagePdfToText(string $pagePdf): string {
    $out = shell_exec('pdftotext ' . escapeshellarg($pagePdf) . ' -');
    return is_string($out) ? $out : '';
}

function findInHr(PDO $pdo, string $taxId): ?array {
    $taxId = preg_replace('/\D+/', '', $taxId);
    if (!preg_match('/^\d{10}$/', $taxId)) return null;
    $st = $pdo->prepare("
        SELECT id, full_name, email, email_private, payslip_email_target
        FROM hr.employees
        WHERE tax_id = ? AND is_active = 1 LIMIT 1
    ");
    $st->execute([$taxId]);
    return $st->fetch() ?: null;
}

$splitDir = TMP_DIR . '/test_split_' . $uploadId . '_' . getmypid();
@mkdir($splitDir, 0770, true);

writeResult(['running' => true, 'done' => 0, 'total' => 0, 'pages' => []]);

try {
    PdfService::splitToPages($upload['stored_path'], $splitDir);

    $pageFiles = glob($splitDir . '/page-*.pdf') ?: [];
    if (!$pageFiles) $pageFiles = glob($splitDir . '/*.pdf') ?: [];
    natsort($pageFiles);
    $pageFiles = array_values($pageFiles);
    $total = count($pageFiles);

    $pages = [];
    foreach ($pageFiles as $idx => $pagePdf) {
        $base   = basename($pagePdf);
        $pageNo = 0;
        if (preg_match('/page-(\d+)\.pdf$/i', $base, $m)) $pageNo = (int)$m[1];
        if ($pageNo <= 0) $pageNo = $idx + 1;

        $pageText = pagePdfToText($pagePdf);
        $taxId    = TaxIdExtractor::extract($pageText);
        $name     = PdfService::extractNameFromPagePdf($pagePdf);

        if (!$name) {
            $pages[] = [
                'page_no' => $pageNo,
                'name'    => null,
                'tax_id'  => $taxId,
                'status'  => 'no_name',
                'note'    => 'Nem található Név mező a PDF-ben.',
            ];
            writeResult(['running' => true, 'done' => $idx + 1, 'total' => $total, 'pages' => $pages]);
            continue;
        }

        $entry = [
            'page_no' => $pageNo,
            'name'    => $name,
            'tax_id'  => $taxId,
        ];

        if (!$taxId) {
            $entry['status'] = 'no_tax_id';
            $entry['note']   = 'Nincs adójel a PDF-ben – HR egyeztetés nem lehetséges.';
            $pages[] = $entry;
            writeResult(['running' => true, 'done' => $idx + 1, 'total' => $total, 'pages' => $pages]);
            continue;
        }

        $hrEmp = findInHr($pdo, $taxId);

        if ($hrEmp) {
            $target = ($hrEmp['payslip_email_target'] ?? 'ceges');
            $effEmail = ($target === 'privat' && !empty($hrEmp['email_private']))
                ? $hrEmp['email_private']
                : ($hrEmp['email'] ?? '');

            $entry['hr_found']     = true;
            $entry['hr_name']      = $hrEmp['full_name'];
            $entry['email_target'] = $target;
            $entry['email']        = $effEmail ?: null;

            if ($effEmail) {
                $entry['status'] = 'ok';
                $entry['note']   = '';
            } else {
                $entry['status'] = 'no_email';
                $entry['note']   = 'HR-ben megvan, de nincs effektív email (' . ($target === 'privat' ? 'privát' : 'céges') . ' célpont üres).';
            }
        } else {
            // Payslip cache-ben van?
            $st = $pdo->prepare("SELECT id, name, email FROM employees WHERE tax_id = ? LIMIT 1");
            $st->execute([$taxId]);
            $cached = $st->fetch();

            $entry['hr_found'] = false;
            if ($cached) {
                $entry['email'] = $cached['email'] ?: null;
                if ($cached['email']) {
                    $entry['status'] = 'cache_only';
                    $entry['note']   = 'Nincs HR rekord, de payslip cache-ben van email – küldhető, de érdemes HR-be felvenni.';
                } else {
                    $entry['status'] = 'no_email';
                    $entry['note']   = 'Nincs HR rekord és a payslip cache-ben sincs email.';
                }
            } else {
                $entry['email']  = null;
                $entry['status'] = 'no_hr';
                $entry['note']   = 'Nincs HR rekord és nincs payslip cache találat – unmatched lenne.';
            }
        }

        $pages[] = $entry;
        writeResult(['running' => true, 'done' => $idx + 1, 'total' => $total, 'pages' => $pages]);
    }

    writeResult(['running' => false, 'done' => $total, 'total' => $total, 'pages' => $pages]);

} catch (Throwable $e) {
    writeResult(['running' => false, 'error' => $e->getMessage(), 'done' => 0, 'total' => 0, 'pages' => []]);
} finally {
    // Tmp split könyvtár törlése
    array_map('unlink', glob($splitDir . '/*') ?: []);
    @rmdir($splitDir);
}

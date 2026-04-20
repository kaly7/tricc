<?php
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/ResetTool.php';

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n";
    exit(1);
}

$force = in_array('--force', $argv, true);

echo "Payslip RESET tool\n";
echo "This will DELETE:\n";
echo "- DB tables: page_jobs, uploads, audit_log (keeps divisions/users/employees)\n";
echo "- Files under: storage/uploads, storage/output, storage/tmp\n\n";

if (!$force) {
    echo "Type RESET to continue: ";
    $line = trim((string)fgets(STDIN));
    if ($line !== 'RESET') {
        echo "Aborted.\n";
        exit(0);
    }
}

$pdo = Db::pdo();

$db = ResetTool::resetDatabase($pdo);

$dirs = [
    'uploads' => UPLOADS_DIR,
    'output'  => OUTPUT_DIR,
    'tmp'     => TMP_DIR,
];
$fs = ResetTool::resetFiles($dirs);

echo "\nDB: " . ($db['ok'] ? "OK" : "FAIL") . "\n";
if (!$db['ok']) echo "Error: " . $db['error'] . "\n";
else echo "Truncated: " . implode(', ', $db['tables']) . "\n";

echo "Files: OK\n";
foreach ($fs['results'] as $k => $r) {
    $note = isset($r['note']) ? " ({$r['note']})" : "";
    echo "- $k: deleted {$r['deleted']} file(s)$note\n";
}
echo "\nDone.\n";

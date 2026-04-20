<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Fejlesztői / teszt eszköz a warehousemgr adatok visszaállításához vagy ürítéséhez.
 * Éles rendszerben csak nagyon körültekintően használandó.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ezt a scriptet CLI-ből futtasd.\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Nem találom a projekt gyökérkönyvtárát.\n");
    exit(1);
}

$configFile = $root . '/app/config/app.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Hiányzik a config fájl: {$configFile}\n");
    exit(1);
}

$config = require $configFile;
if (!isset($config['app_db']['dsn'], $config['app_db']['user'], $config['app_db']['pass'])) {
    fwrite(STDERR, "Hiányos app_db konfiguráció.\n");
    exit(1);
}

$tablesToReset = [
    'audit_log',
    'material_import_errors',
    'material_import_batches',
    'material_identifiers',
    'stock_transfer_items',
    'stock_transfers',
    'stock_movements',
    'warehouse_stock',
    'warehouse_user_access',
    'warehouses',
    'warehouse_partners',
    'material_items',
];

$pathsToClean = [
    $root . '/storage/documents/external_transfer',
    $root . '/storage/documents/external-transfer',
    $root . '/storage/tmp',
    $root . '/storage/archive/warehouse_delete',
];

$options = getopt('', ['yes', 'help']);
if (isset($options['help'])) {
    echo "Használat:\n";
    echo "  php tools/reset_warehousemgr.php --yes\n\n";
    echo "Mit csinál:\n";
    echo "  - törli a warehousemgr üzleti adatait az adatbázisból\n";
    echo "  - lenullázza az AUTO_INCREMENT értékeket a TRUNCATE miatt\n";
    echo "  - törli a generált PDF-eket, ideiglenes fájlokat és raktártörlési archív JSON-okat\n";
    echo "\nFigyelem: a művelet visszafordíthatatlan.\n";
    exit(0);
}

if (!isset($options['yes'])) {
    fwrite(STDOUT, "FIGYELEM: Ez a script törli a warehousemgr összes üzleti adatát és a generált fájlokat.\n");
    fwrite(STDOUT, "Az auth_center / HR adatbázist nem bántja.\n");
    fwrite(STDOUT, "\nFuttasd így a megerősítéshez:\n  php tools/reset_warehousemgr.php --yes\n");
    exit(2);
}

$pdo = new PDO(
    $config['app_db']['dsn'],
    $config['app_db']['user'],
    $config['app_db']['pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function recursiveDeleteContents(string $dir): array
{
    $deleted = 0;
    $errors = [];

    if (!is_dir($dir)) {
        return ['deleted' => 0, 'errors' => []];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) {
            if (@rmdir($path)) {
                $deleted++;
            } else {
                $errors[] = "Nem sikerült törölni a könyvtárat: {$path}";
            }
        } else {
            if (@unlink($path)) {
                $deleted++;
            } else {
                $errors[] = "Nem sikerült törölni a fájlt: {$path}";
            }
        }
    }

    return ['deleted' => $deleted, 'errors' => $errors];
}

$existingTables = [];
foreach ($tablesToReset as $table) {
    if (tableExists($pdo, $table)) {
        $existingTables[] = $table;
    }
}

$rowCounts = [];
foreach ($existingTables as $table) {
    $rowCounts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

$summary = [
    'tables' => $rowCounts,
    'paths' => array_values(array_filter($pathsToClean, 'is_dir')),
];

fwrite(STDOUT, "Törlés indul...\n");
foreach ($summary['tables'] as $table => $count) {
    fwrite(STDOUT, sprintf("  DB: %-24s %8d sor\n", $table, $count));
}
foreach ($summary['paths'] as $dir) {
    fwrite(STDOUT, "  Fájlok: {$dir}\n");
}

$pdo->beginTransaction();
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($existingTables as $table) {
        $pdo->exec("TRUNCATE TABLE `{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, "Adatbázis reset hiba: {$e->getMessage()}\n");
    exit(1);
}

$totalDeletedFiles = 0;
$fileErrors = [];
foreach ($pathsToClean as $dir) {
    $result = recursiveDeleteContents($dir);
    $totalDeletedFiles += $result['deleted'];
    $fileErrors = array_merge($fileErrors, $result['errors']);
}

fwrite(STDOUT, "\nKész.\n");
fwrite(STDOUT, sprintf("  Törölt DB táblák: %d\n", count($existingTables)));
fwrite(STDOUT, sprintf("  Törölt fájlok/könyvtárak: %d\n", $totalDeletedFiles));

if ($fileErrors !== []) {
    fwrite(STDOUT, "\nFájltörlési figyelmeztetések:\n");
    foreach ($fileErrors as $error) {
        fwrite(STDOUT, "  - {$error}\n");
    }
}

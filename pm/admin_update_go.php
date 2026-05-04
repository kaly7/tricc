<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["admin"] != "on") {
    header("location: index.php"); exit;
}

$log_file   = "/var/www/html/pm/tmp/update_log.txt";
$admin_name = $_SESSION['username'] ?? 'ismeretlen';

function upd_log(string $sor): void {
    global $log_file;
    $ts = date("Y-m-d H:i:s");
    file_put_contents($log_file, "[$ts] $sor\n", FILE_APPEND | LOCK_EX);
}

function upd_die(string $hiba): void {
    upd_log("HIBA | " . $hiba);
    header("location: admin_update.php?hiba=" . urlencode($hiba)); exit;
}

// --- Validáció ---
if (($_POST['megerosites'] ?? '') !== 'FRISSITES') {
    upd_die("Hibás megerősítő szó.");
}
if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
    upd_die("Fájl feltöltési hiba (kód: " . ($_FILES['zip_file']['error'] ?? '?') . ")");
}
$ext = strtolower(pathinfo($_FILES['zip_file']['name'], PATHINFO_EXTENSION));
if ($ext !== 'zip') {
    upd_die("Csak .zip fájl fogadható el.");
}

// --- ZIP megnyitás ---
$za = new ZipArchive();
if ($za->open($_FILES['zip_file']['tmp_name']) !== true) {
    upd_die("Nem sikerült megnyitni a ZIP fájlt.");
}

// Biztonsági ellenőrzés: path traversal tiltása
for ($i = 0; $i < $za->numFiles; $i++) {
    $name = $za->getNameIndex($i);
    $norm = str_replace('\\', '/', $name);
    if (strpos($norm, '..') !== false || strpos($norm, './') === 0 || strpos($norm, '/') === 0) {
        $za->close();
        upd_die("Gyanús fájlútvonal a ZIP-ben: $name");
    }
}

// --- Kicsomagolás temp könyvtárba ---
$temp_dir = sys_get_temp_dir() . '/pm_update_' . time() . '_' . rand(1000, 9999);
if (!mkdir($temp_dir, 0755)) {
    $za->close();
    upd_die("Nem sikerült temp könyvtárat létrehozni.");
}
if (!$za->extractTo($temp_dir)) {
    $za->close();
    upd_die("A ZIP kicsomagolása sikertelen.");
}
$za->close();

// --- Kötelező: ZIP-ben legyen pm/ alkönyvtár ---
$src_dir = $temp_dir . '/pm';
if (!is_dir($src_dir)) {
    rmdirRecursive($temp_dir);
    upd_die("A ZIP nem tartalmaz 'pm/' alkönyvtárat. Csomagold be így: pm/index.php, pm/styles.css, stb.");
}

// --- Verziók kiolvasása ---
$old_version    = file_exists(__DIR__.'/version.txt')    ? trim(file_get_contents(__DIR__.'/version.txt'))    : '?';
$old_db_version = file_exists(__DIR__.'/db_version.txt') ? trim(file_get_contents(__DIR__.'/db_version.txt')) : '?';

$new_version    = file_exists("$src_dir/version.txt")    ? trim(file_get_contents("$src_dir/version.txt"))    : 'ismeretlen';
$new_db_version = file_exists("$src_dir/db_version.txt") ? trim(file_get_contents("$src_dir/db_version.txt")) : null;

$migration_needed = ($new_db_version !== null && $new_db_version !== $old_db_version);

// --- Fájlok másolása ---
function copyDirRecursive(string $src, string $dst): int {
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel    = substr($item->getPathname(), strlen($src) + 1);
        $target = $dst . '/' . $rel;
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0755, true);
        } else {
            copy($item->getPathname(), $target);
            chmod($target, 0644);
            $count++;
        }
    }
    return $count;
}

$copied = copyDirRecursive($src_dir, __DIR__);

// --- Temp könyvtár törlése ---
function rmdirRecursive(string $dir): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
rmdirRecursive($temp_dir);

// --- Naplózás ---
$zip_name = basename($_FILES['zip_file']['name']);
$db_info  = $migration_needed
    ? "DB verzió változott ($old_db_version -> $new_db_version) – MIGRÁCIÓ SZÜKSÉGES"
    : "DB változatlan ($old_db_version)";
upd_log("$admin_name | $old_version -> $new_version | $copied fájl | $zip_name | $db_info");

// --- Átirányítás eredményoldalra ---
header("location: admin_update_ok.php?"
    . "v_old=" . urlencode($old_version)
    . "&v_new=" . urlencode($new_version)
    . "&files=" . $copied
    . "&migration=" . ($migration_needed ? '1' : '0')
    . "&db_old=" . urlencode($old_db_version)
    . "&db_new=" . urlencode($new_db_version ?? $old_db_version)
);

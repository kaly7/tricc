<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
agv_require_login();

header('Content-Type: application/json');

$service  = 'agvmgr-worker';
$log_file = '/var/log/agvmgr_worker.log';

// Systemd státusz
$active = trim((string)shell_exec("systemctl is-active " . escapeshellarg($service) . " 2>/dev/null"));

// Log fájl utolsó módosítása + utolsó sora
$log_mtime    = null;
$log_age_sec  = null;
$last_log     = null;

if (is_readable($log_file)) {
    $mtime       = filemtime($log_file);
    $log_mtime   = date('Y-m-d H:i:s', $mtime);
    $log_age_sec = time() - $mtime;

    // Utolsó nem-üres sor (max 8 KB-t olvasunk a fájl végéről)
    $fh = fopen($log_file, 'r');
    if ($fh) {
        fseek($fh, 0, SEEK_END);
        $size = ftell($fh);
        $read = min($size, 8192);
        fseek($fh, -$read, SEEK_END);
        $tail = fread($fh, $read);
        fclose($fh);
        $lines = array_filter(array_map('trim', explode("\n", $tail)));
        $last_log = $lines ? end($lines) : null;
    }
}

echo json_encode([
    'active'      => $active,          // "active" | "inactive" | "failed" | "unknown" | ""
    'log_mtime'   => $log_mtime,
    'log_age_sec' => $log_age_sec,
    'last_log'    => $last_log,
]);

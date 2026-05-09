<?php
header('Content-Type: application/json; charset=utf-8');

$conn = new mysqli('localhost', 'robot', 'abrakadabra', 'Robot');
if ($conn->connect_error) {
    echo json_encode(['status' => 'error'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result_file = '/var/www/html/pm/tmp/query_result.json';
$lock_file   = '/var/www/html/pm/tmp/query.lock';
$completed   = [];

// Fázis A: ha van friss eredmény, feldolgozzuk
if (file_exists($result_file) && (time() - filemtime($result_file)) < 60) {
    $raw  = file_get_contents($result_file);
    $data = json_decode($raw, true);
    if ($data && isset($data['results'])) {
        foreach ($data['results'] as $r) {
            if (in_array($r['status'], ['completed', 'cancelled', 'failed', 'interrupted'])) {
                $jid = $conn->real_escape_string($r['job_id']);
                $conn->query("UPDATE Button_Goals SET akcio='deleted' WHERE Megjegyzes='$jid'");
                $completed[] = $r['job_id'];
            }
        }
    }
    unlink($result_file);
}

// Fázis B: aktív job-ok lekérése, új lekérdezés indítása
$res = $conn->query("SELECT DISTINCT Megjegyzes FROM Button_Goals WHERE akcio='aktiv'");
$active_jobs = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $active_jobs[] = $row['Megjegyzes'];
    }
}
$conn->close();

$lock_running = file_exists($lock_file) && (time() - filemtime($lock_file)) < 30;

if (!empty($active_jobs) && !$lock_running) {
    $args = implode(' ', array_map('escapeshellarg', $active_jobs));
    exec("/var/www/html/pm/query_multi.pl $args > /dev/null 2>&1 &");
    echo json_encode([
        'status'    => 'polling',
        'completed' => $completed,
        'jobs'      => $active_jobs,
    ], JSON_UNESCAPED_UNICODE);
} elseif ($lock_running) {
    echo json_encode([
        'status'    => 'busy',
        'completed' => $completed,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'status'    => 'idle',
        'completed' => $completed,
    ], JSON_UNESCAPED_UNICODE);
}

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

    if ($data) {
        // Robot állapotok mentése DB-be
        if (!empty($data['robots'])) {
            foreach ($data['robots'] as $r) {
                $name  = $conn->real_escape_string($r['name']);
                $avail = $conn->real_escape_string($r['availability']);
                $fmst  = $conn->real_escape_string($r['fm_status']);
                $conn->query(
                    "UPDATE Robots SET availability='$avail', fm_status='$fmst',
                     frissitve=NOW() WHERE Robot_name='$name'"
                );
            }
        }

        // Job pickup státuszok mentése
        if (!empty($data['results'])) {
            foreach ($data['results'] as $r) {
                $jid = $conn->real_escape_string($r['job_id']);

                // Célpont-szintű frissítés
                if (!empty($r['pickups'])) {
                    foreach ($r['pickups'] as $p) {
                        $pickup_id = $conn->real_escape_string($p['pickup_id']);
                        $pstatus   = $conn->real_escape_string($p['status']);
                        $goal      = $conn->real_escape_string($p['goal']);
                        $robot     = $conn->real_escape_string($p['robot']);
                        $kezdes    = $conn->real_escape_string($p['kezdes']);
                        $vegzes    = $conn->real_escape_string($p['vegzes']);
                        // LIMIT 1: ha ugyanaz a goal kétszer van, sorban frissülnek
                        $conn->query(
                            "UPDATE Button_Goals
                             SET pickup_id='$pickup_id', pickup_status='$pstatus',
                                 robot_nev='$robot', fm_kezdes='$kezdes', fm_vegzes='$vegzes'
                             WHERE Megjegyzes='$jid' AND Goal_name='$goal'
                               AND (pickup_id IS NULL OR pickup_id='$pickup_id')
                             ORDER BY Index_ LIMIT 1"
                        );
                    }
                }

                // Job törlés csak ha MINDEN célpont befejező állapotban van
                $done = "('completed','cancelled','failed','interrupted')";
                $cnt_res = $conn->query(
                    "SELECT COUNT(*) as cnt FROM Button_Goals
                     WHERE Megjegyzes='$jid' AND akcio='aktiv'
                       AND (pickup_status IS NULL OR LOWER(pickup_status) NOT IN $done)"
                );
                $cnt_row = $cnt_res ? $cnt_res->fetch_assoc() : null;
                if ($cnt_row && (int)$cnt_row['cnt'] === 0) {
                    $conn->query("UPDATE Button_Goals SET akcio='deleted' WHERE Megjegyzes='$jid'");
                    $completed[] = $r['job_id'];
                }
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

if (!$lock_running) {
    $args = implode(' ', array_map('escapeshellarg', $active_jobs));
    exec("perl /var/www/html/pm/query_multi.pl $args > /dev/null 2>&1 &");
    echo json_encode([
        'status'    => !empty($active_jobs) ? 'polling' : 'robot_only',
        'completed' => $completed,
        'jobs'      => $active_jobs,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'status'    => 'busy',
        'completed' => $completed,
    ], JSON_UNESCAPED_UNICODE);
}

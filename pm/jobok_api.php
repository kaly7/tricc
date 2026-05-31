<?php
// Publikus endpoint: pont_pont és robot_ide oldalak használják (login nélkül is elérhetők)
header('Content-Type: application/json; charset=utf-8');

$tipus      = isset($_GET['tipus'])      ? $_GET['tipus']      : 'PP';
$lathatosag = isset($_GET['lathatosag']) ? $_GET['lathatosag'] : 'semmi';

if ($lathatosag === 'semmi') {
    echo json_encode(['jobs' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$conn = new mysqli('localhost', 'robot', 'abrakadabra', 'Robot');
$jobs = [];
if (!$conn->connect_error) {
    // Státusz az fm_jobs_live-ból jön (pickup_status a Button_Goals-ban nem frissül megbízhatóan)
    $res = $conn->query("
        SELECT bg.Goal_name, bg.Megjegyzes,
               f.status AS fm_status, f.robot AS fm_robot,
               bg.Index_
        FROM Button_Goals bg
        LEFT JOIN fm_jobs_live f ON f.job_id = bg.Megjegyzes AND f.goal = bg.Goal_name
        WHERE bg.akcio = 'aktiv'
        ORDER BY bg.Megjegyzes, bg.Index_
    ");
    $current = null;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $jid    = $row['Megjegyzes'];
            $suffix = '_' . $tipus;
            if ($lathatosag === 'sajat' && substr($jid, -strlen($suffix)) !== $suffix) {
                continue;
            }
            if ($current === null || $current['id'] !== $jid) {
                if ($current !== null) $jobs[] = $current;
                $current = ['id' => $jid, 'goals' => []];
            }
            $current['goals'][] = ['name' => $row['Goal_name'], 'status' => $row['fm_status']];
        }
        if ($current !== null) $jobs[] = $current;
    }
    $conn->close();
}

echo json_encode(['jobs' => $jobs], JSON_UNESCAPED_UNICODE);

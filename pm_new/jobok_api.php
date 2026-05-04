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
    $res = $conn->query("SELECT Goal_name, Megjegyzes FROM Button_Goals WHERE akcio='aktiv' ORDER BY Megjegyzes");
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
            $current['goals'][] = $row['Goal_name'];
        }
        if ($current !== null) $jobs[] = $current;
    }
    $conn->close();
}

echo json_encode(['jobs' => $jobs], JSON_UNESCAPED_UNICODE);

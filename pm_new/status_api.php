<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$servername = "localhost"; $db_user = "robot"; $db_pass = "abrakadabra"; $dbname = "Robot";

$data = ['robots' => [], 'jobs' => []];

// Robot státuszok fájlokból
$robot_files = [
    'Kiss_Gyuri' => '/var/www/html/pm/tmp/GYURI',
    'Kiss_Marci'  => '/var/www/html/pm/tmp/MARCI',
];
foreach ($robot_files as $name => $path) {
    $data['robots'][] = [
        'name'   => $name,
        'status' => file_exists($path) ? trim(file_get_contents($path)) : '?',
    ];
}

// Aktív jobok
$conn = new mysqli($servername, $db_user, $db_pass, $dbname);
if (!$conn->connect_error) {
    $res = $conn->query("SELECT Goal_name, Megjegyzes FROM Button_Goals WHERE akcio='aktiv' ORDER BY Megjegyzes");
    $current = null;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($current === null || $current['id'] !== $row['Megjegyzes']) {
                if ($current !== null) $data['jobs'][] = $current;
                $current = ['id' => $row['Megjegyzes'], 'goals' => []];
            }
            $current['goals'][] = $row['Goal_name'];
        }
        if ($current !== null) $data['jobs'][] = $current;
    }
    $conn->close();
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);

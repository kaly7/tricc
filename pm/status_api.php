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

$conn = new mysqli($servername, $db_user, $db_pass, $dbname);
if (!$conn->connect_error) {
    // Robot állapotok DB-ből
    $rres = $conn->query("SELECT Robot_name, availability, fm_status, frissitve FROM Robots WHERE Active != 'N'");
    if ($rres) {
        while ($rrow = $rres->fetch_assoc()) {
            $data['robots'][] = [
                'name'         => $rrow['Robot_name'],
                'availability' => $rrow['availability'] ?? null,
                'fm_status'    => $rrow['fm_status']    ?? null,
                'frissitve'    => $rrow['frissitve'],
            ];
        }
    }

    // Aktív jobok pickup státuszokkal
    $res = $conn->query("SELECT Goal_name, Megjegyzes, pickup_status FROM Button_Goals WHERE akcio='aktiv' ORDER BY Megjegyzes, Index_");
    $current = null;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($current === null || $current['id'] !== $row['Megjegyzes']) {
                if ($current !== null) $data['jobs'][] = $current;
                $current = ['id' => $row['Megjegyzes'], 'goals' => []];
            }
            $current['goals'][] = ['name' => $row['Goal_name'], 'status' => $row['pickup_status']];
        }
        if ($current !== null) $data['jobs'][] = $current;
    }
    $conn->close();
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);

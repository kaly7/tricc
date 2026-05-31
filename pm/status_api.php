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
    // Robot állapotok DB-ből + státuszfájlból (pm/tmp/GYURI, pm/tmp/MARCI stb.)
    $rres = $conn->query("SELECT Robot_name, availability, fm_status, frissitve FROM Robots WHERE Active != 'N'");
    if ($rres) {
        while ($rrow = $rres->fetch_assoc()) {
            $status = null;
            if (!empty($rrow['availability']) && !empty($rrow['fm_status'])) {
                $status = $rrow['availability'] . ' ' . $rrow['fm_status'];
            } elseif (!empty($rrow['availability'])) {
                $status = $rrow['availability'];
            } elseif (!empty($rrow['fm_status'])) {
                $status = $rrow['fm_status'];
            }
            $data['robots'][] = [
                'name'      => $rrow['Robot_name'],
                'status'    => $status,
                'frissitve' => $rrow['frissitve'],
            ];
        }
    }

    // Aktív jobok: fm_jobs_live (összes FM job) UNION saját jobok amelyek még nem kerültek be
    // Sorrend: pickup_id numerikus része (PICKUP1639 → 1639) – az FM sorrendnek megfelelően
    $res = $conn->query("
        SELECT job_id, goal, robot, status, can_delete, sort_key
        FROM (
            SELECT f.job_id, f.goal, f.robot, f.status,
                   IF(b.Megjegyzes IS NOT NULL, 1, 0) AS can_delete,
                   1 AS src,
                   CAST(SUBSTRING(f.pickup_id, 7) AS UNSIGNED) AS sort_key
            FROM fm_jobs_live f
            LEFT JOIN (SELECT DISTINCT Megjegyzes FROM Button_Goals WHERE akcio='aktiv') b
                ON b.Megjegyzes = f.job_id
            UNION ALL
            SELECT bg.Megjegyzes, bg.Goal_name, NULL, NULL,
                   1 AS can_delete,
                   2 AS src,
                   bg.Index_ AS sort_key
            FROM Button_Goals bg
            WHERE bg.akcio = 'aktiv'
              AND NOT EXISTS (SELECT 1 FROM fm_jobs_live f2 WHERE f2.job_id = bg.Megjegyzes)
        ) t
        ORDER BY job_id, src, sort_key
    ");
    $current = null;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($current === null || $current['id'] !== $row['job_id']) {
                if ($current !== null) $data['jobs'][] = $current;
                $current = [
                    'id'         => $row['job_id'],
                    'can_delete' => (bool)$row['can_delete'],
                    'goals'      => [],
                ];
            }
            $current['goals'][] = [
                'name'   => $row['goal'],
                'status' => $row['status'],
                'robot'  => $row['robot'],
            ];
        }
        if ($current !== null) $data['jobs'][] = $current;
    }
    $conn->close();
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);

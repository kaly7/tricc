<?php
// Publikus endpoint: robot_ide.php AJAX pollere használja
header('Content-Type: application/json; charset=utf-8');

$conn = new mysqli('localhost', 'robot', 'abrakadabra', 'Robot');
if ($conn->connect_error) {
    echo json_encode(['error' => 'DB error']);
    exit;
}

$ip_safe = $conn->real_escape_string($_SERVER['REMOTE_ADDR']);

$res = $conn->query(
    "SELECT m.*, gc.Goal_name as cel_goal_name, gc.Megjegyzes as cel_megjegyzes,
            gk.Goal_name as kozbenso_goal_name, gk.Megjegyzes as kozbenso_megjegyzes
     FROM munkaallomas m
     JOIN Goals gc ON m.cel_goal_index = gc.Index_
     LEFT JOIN Goals gk ON m.kozbenso_goal_index = gk.Index_
     WHERE m.ip = '$ip_safe' LIMIT 1"
);

if (!$res || $res->num_rows === 0) {
    $conn->close();
    echo json_encode(['found' => false]);
    exit;
}

$a = $res->fetch_assoc();

// Robot végzett ellenőrzés – ha nincs már aktív goal a job_id-hoz, állomás visszaáll szabadra
if ($a['allapot'] === 'uton' && !empty($a['aktiv_job_id'])) {
    $jid  = $conn->real_escape_string($a['aktiv_job_id']);
    $jres = $conn->query(
        "SELECT COUNT(*) as cnt FROM Button_Goals WHERE Megjegyzes='$jid' AND akcio != 'deleted'"
    );
    $jrow = $jres ? $jres->fetch_assoc() : null;
    if (!$jrow || (int)$jrow['cnt'] === 0) {
        $conn->query("UPDATE munkaallomas SET allapot='szabad', aktiv_job_id=NULL WHERE ip='$ip_safe'");
        $a['allapot']      = 'szabad';
        $a['aktiv_job_id'] = null;
    }
}

$conn->close();

echo json_encode([
    'found'          => true,
    'id'             => (int)$a['id'],
    'nev'            => $a['nev'],
    'allapot'        => $a['allapot'],
    'cel_label'      => $a['cel_megjegyzes'] ?: $a['cel_goal_name'],
    'kozbenso_label' => ($a['kozbenso_goal_index'] > 0)
                        ? ($a['kozbenso_megjegyzes'] ?: $a['kozbenso_goal_name'])
                        : null,
], JSON_UNESCAPED_UNICODE);

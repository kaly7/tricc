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
    "SELECT * FROM munkaallomas WHERE ip = '$ip_safe' LIMIT 1"
);

if (!$res || $res->num_rows === 0) {
    $conn->close();
    echo json_encode(['found' => false]);
    exit;
}

$a = $res->fetch_assoc();

// Robot végzett ellenőrzés
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

// Útvonal pontok
$route_labels = [];
$rres = $conn->query(
    "SELECT g.Megjegyzes, g.Goal_name
     FROM munkaallomas_utvonal u
     JOIN Goals g ON u.goal_index = g.Index_
     WHERE u.allomas_id = " . (int)$a['id'] . "
     ORDER BY u.sorrend"
);
if ($rres) {
    while ($rrow = $rres->fetch_assoc()) {
        $route_labels[] = $rrow['Megjegyzes'] ?: $rrow['Goal_name'];
    }
}

$conn->close();

echo json_encode([
    'found'         => true,
    'id'            => (int)$a['id'],
    'nev'           => $a['nev'],
    'allapot'       => $a['allapot'],
    'route_labels'  => $route_labels,
], JSON_UNESCAPED_UNICODE);

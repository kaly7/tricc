<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();

$allomas_id = (int)$_POST["allomas_id"];

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$res = $conn->query("SELECT * FROM munkaallomas WHERE id = $allomas_id LIMIT 1");
if (!$res || $res->num_rows == 0) {
    die("Érvénytelen munkaállomás. <a href='robot_ide.php'>Vissza</a>");
}
$allomas = $res->fetch_assoc();

if ($allomas['allapot'] === 'uton') {
    header("location: robot_ide.php");
    exit;
}

// Útvonal pontok betöltése sorrendben
$route_res = $conn->query(
    "SELECT u.goal_index, g.Goal_name
     FROM munkaallomas_utvonal u
     JOIN Goals g ON u.goal_index = g.Index_
     WHERE u.allomas_id = $allomas_id
     ORDER BY u.sorrend"
);
$route_points = [];
if ($route_res) {
    while ($row = $route_res->fetch_assoc()) {
        $route_points[] = $row;
    }
}

if (empty($route_points)) {
    die("Nincs útvonal konfigurálva ehhez az állomáshoz. <a href='robot_ide.php'>Vissza</a>");
}

$caller  = isset($_SESSION["username"]) ? $_SESSION["username"] : str_replace('.', '_', $_SERVER['REMOTE_ADDR']);
$job_id  = date("Y_m_d_H_i_s") . "_" . $caller . "_RI";
$jid     = $conn->real_escape_string($job_id);

// Button_Goals sorok + queuemulti parancs összeállítása
$n = count($route_points);
$parancs = "queuemulti $n 2";
foreach ($route_points as $p) {
    $gne = $conn->real_escape_string($p['Goal_name']);
    $conn->query("INSERT INTO Button_Goals(Goal_name, Megjegyzes, akcio) VALUES('$gne', '$jid', 'aktiv')");
    $parancs .= " " . $p['Goal_name'] . " pickup 10";
}
$parancs .= " $job_id";

$conn->query("UPDATE munkaallomas SET allapot='uton', aktiv_job_id='$jid' WHERE id=$allomas_id");
$conn->close();

$myfile = fopen("/var/www/html/pm/tmp/newfile.txt", "w");
fwrite($myfile, $parancs);
fclose($myfile);
exec("/var/www/html/pm/go.pl > /dev/null 2>&1 &");

header("location: robot_ide.php");
exit;

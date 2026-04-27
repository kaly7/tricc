<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();

$allomas_id = (int)$_POST["allomas_id"];

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$res = $conn->query(
    "SELECT m.*, gc.Goal_name as cel_goal_name,
            gv.Goal_name as vissza_goal_name
     FROM munkaallomas m
     JOIN Goals gc ON m.cel_goal_index    = gc.Index_
     JOIN Goals gv ON m.vissza_goal_index = gv.Index_
     WHERE m.id = $allomas_id LIMIT 1"
);
if (!$res || $res->num_rows == 0) {
    die("Érvénytelen munkaállomás vagy hiányzó goal konfiguráció. <a href='robot_ide.php'>Vissza</a>");
}
$allomas = $res->fetch_assoc();

// Ha már úton van, ne indítsunk új jobot
if ($allomas['allapot'] === 'uton') {
    header("location: robot_ide.php");
    exit;
}

$caller       = isset($_SESSION["username"]) ? $_SESSION["username"] : str_replace('.', '_', $_SERVER['REMOTE_ADDR']);
$job_id       = date("Y_m_d_H_i_s") . "_" . $caller . "_RI";
$cel_goal     = $allomas['cel_goal_name'];
$vissza_goal  = $allomas['vissza_goal_name'];

$jid = $conn->real_escape_string($job_id);
$gne_cel     = $conn->real_escape_string($cel_goal);
$gne_vissza  = $conn->real_escape_string($vissza_goal);

// Mindkét célponthoz bejegyzés – a monitoring addig mutat "Robot úton"-t,
// amíg legalább egy sor aktív marad ezzel a job_id-val
$conn->query("INSERT INTO Button_Goals(Goal_name, Megjegyzes, akcio) VALUES('$gne_cel',    '$jid', 'aktiv')");
$conn->query("INSERT INTO Button_Goals(Goal_name, Megjegyzes, akcio) VALUES('$gne_vissza', '$jid', 'aktiv')");
$conn->query("UPDATE munkaallomas SET allapot='uton', aktiv_job_id='$jid' WHERE id=$allomas_id");
$conn->close();

// cel → vissza sorrendben küldi a robotot, automatikusan visszatér
$parancs = "queuemulti 2 2 $cel_goal pickup 10 $vissza_goal pickup 10 $job_id";
$myfile  = fopen("/var/www/html/pm/tmp/newfile.txt", "w");
fwrite($myfile, $parancs);
fclose($myfile);
exec("/var/www/html/pm/go.pl");

header("location: robot_ide.php");
exit;

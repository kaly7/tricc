<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$allomas_id = (int)$_POST["allomas_id"];

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$res = $conn->query(
    "SELECT m.*, gc.Goal_name as cel_goal_name, gv.Goal_name as vissza_goal_name
     FROM munkaallomas m
     JOIN Goals gc ON m.cel_goal_index = gc.Index_
     JOIN Goals gv ON m.vissza_goal_index = gv.Index_
     WHERE m.id = $allomas_id LIMIT 1"
);
if (!$res || $res->num_rows == 0) {
    die("Érvénytelen munkaállomás. <a href='robot_ide.php'>Vissza</a>");
}
$allomas = $res->fetch_assoc();

$job_id = date("Y_m_d_H_i_s") . "_" . $_SESSION["username"] . "_RI";

if ($allomas['allapot'] === 'vissza') {
    $goal_name  = $allomas['cel_goal_name'];
    $uj_allapot = 'ide';
} else {
    $goal_name  = $allomas['vissza_goal_name'];
    $uj_allapot = 'vissza';
}

$gne = $conn->real_escape_string($goal_name);
$jid = $conn->real_escape_string($job_id);
$conn->query("INSERT INTO Button_Goals(Goal_name, Megjegyzes, akcio) VALUES('$gne', '$jid', 'aktiv')");
$conn->query("UPDATE munkaallomas SET allapot='$uj_allapot' WHERE id=$allomas_id");
$conn->close();

$parancs = "queuemulti 1 2 $goal_name pickup 10 $job_id";
$myfile  = fopen("/var/www/html/pm/tmp/newfile.txt", "w");
fwrite($myfile, $parancs);
fclose($myfile);
exec("/var/www/html/pm/go.pl");

header("location: robot_ide.php");
exit;

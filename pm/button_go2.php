<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php"); exit;
}

$servername = "localhost"; $username = "robot"; $password = "abrakadabra"; $dbname = "Robot";

$felhasznalo = $_SESSION["username"];
$job_id      = date("Y_m_d_H_i_s") . "_" . $felhasznalo;

// Eleje szekvencia lekérése
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query("SELECT * FROM Felhasznalo_goal_eleje WHERE Felhasznalo_index='" . (int)$_SESSION["user_id"] . "'");
$eleje_Goal_index = []; $eleje_Akcio = []; $eleje_number = 0;
while ($row = $result->fetch_assoc()) {
    $eleje_Goal_index[$eleje_number] = $row["Goal_index"];
    $eleje_Akcio[$eleje_number]      = $row["Akcio"];
    $eleje_number++;
}
$conn->close();

// Vége szekvencia lekérése
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query("SELECT * FROM Felhasznalo_goal_vege WHERE Felhasznalo_index='" . (int)$_SESSION["user_id"] . "'");
$vege_Goal_index = []; $vege_Akcio = []; $vege_number = 0;
while ($row = $result->fetch_assoc()) {
    $vege_Goal_index[$vege_number] = $row["Goal_index"];
    $vege_Akcio[$vege_number]      = $row["Akcio"];
    $vege_number++;
}
$conn->close();

// Összes goal neve + megjegyzése
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query("SELECT * FROM Goals");
$goal_name = []; $goal_name_megjegyzes = []; $goal_Index_ = []; $goals_number = 0;
while ($row = $result->fetch_assoc()) {
    $goal_name[$goals_number]            = $row["Goal_name"];
    $goal_name_megjegyzes[$goals_number] = $row["Megjegyzes"];
    $goal_Index_[$goals_number]          = $row["Index_"];
    $goals_number++;
}
$conn->close();

// Parancs összerakása
$parancs  = "";
$elemszam = 0;
$funkcio_all = "pickup";

// Eleje szekvencia
if (($_POST["eleje_check"] ?? '') === "on") {
    for ($i = 0; $i < $eleje_number; $i++) {
        $goal_parancs_name = ""; $goal_mm = "";
        for ($j = 0; $j < $goals_number; $j++) {
            if ($goal_Index_[$j] == $eleje_Goal_index[$i]) {
                $goal_parancs_name = $goal_name[$j];
                $goal_mm           = $goal_name_megjegyzes[$j];
            }
        }
        $akcio    = ($elemszam === 0) ? "pickup" : $eleje_Akcio[$i];
        $parancs .= " $goal_parancs_name $akcio 10 ";
        $elemszam++;

        $conn = new mysqli($servername, $username, $password, $dbname);
        $gm = $conn->real_escape_string($goal_mm);
        $jid = $conn->real_escape_string($job_id);
        $conn->query("INSERT INTO Button_Goals(Goal_name,Megjegyzes,akcio) VALUES('$gm','$jid','aktiv')");
        $conn->close();
    }
}

// Célpontok
$goals = $_POST['mytext2'] ?? [];
$value_fleet_mgr = "";
foreach ($goals as $value) {
    for ($k = 0; $k < $goals_number; $k++) {
        if ($goal_name_megjegyzes[$k] == $value) {
            $value_fleet_mgr = $goal_name[$k];
        }
    }
    $parancs .= "$value_fleet_mgr $funkcio_all 10 ";
    $elemszam++;

    $conn = new mysqli($servername, $username, $password, $dbname);
    $gm = $conn->real_escape_string($value);
    $jid = $conn->real_escape_string($job_id);
    $conn->query("INSERT INTO Button_Goals(Goal_name,Megjegyzes,akcio) VALUES('$gm','$jid','aktiv')");
    $conn->close();
}

// Vége szekvencia
if (($_POST["vege_check"] ?? '') === "on") {
    for ($i = 0; $i < $vege_number; $i++) {
        $goal_parancs_name = ""; $goal_mm = "";
        for ($j = 0; $j < $goals_number; $j++) {
            if ($goal_Index_[$j] == $vege_Goal_index[$i]) {
                $goal_parancs_name = $goal_name[$j];
                $goal_mm           = $goal_name_megjegyzes[$j];
            }
        }
        $akcio    = ($elemszam === 0) ? "pickup" : $vege_Akcio[$i];
        $parancs .= " $goal_parancs_name $akcio 10 ";
        $elemszam++;

        $conn = new mysqli($servername, $username, $password, $dbname);
        $gm = $conn->real_escape_string($goal_mm);
        $jid = $conn->real_escape_string($job_id);
        $conn->query("INSERT INTO Button_Goals(Goal_name,Megjegyzés,akcio) VALUES('$gm','$jid','aktiv')");
        $conn->close();
    }
}

// Fleet Manager parancs
if ($elemszam > 1) {
    $parancs_send = "queuemulti $elemszam 2 $parancs $job_id";
} else {
    $parancs_send = "queuepickup \\\"" . substr($value_fleet_mgr, 1) . "\\\" 10 $job_id";
}

$myfile = fopen("/var/www/html/pm/tmp/newfile.txt", "w");
fwrite($myfile, $parancs_send);
fclose($myfile);
exec("/var/www/html/pm/go.pl");
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Refresh" content="3; url=index.php">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager</title>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text" style="max-width:600px; text-align:center;">
  <h2 class="page-title" style="color:#2e7d32;">&#10003; Feladat elküldve a Fleet Managernek</h2>
  <p style="color:#555; font-size:15px; margin:12px 0;">
    <?php
    $labels = [];
    foreach ($goals as $v) $labels[] = htmlspecialchars($v);
    echo implode(' &rarr; ', $labels);
    ?>
  </p>
  <p style="color:#aaa; font-size:13px; margin-top:16px;">Átirányítás a főmenübe...</p>
  <a href="index.php" class="mybutton_vh" style="margin-top:20px; display:inline-block;">Főmenü</a>
</div>
<?php include __DIR__ . '/footer_inc.php'; ?>
</body>
</html>

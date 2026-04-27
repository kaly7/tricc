<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$indulo_index  = (int)$_POST["indulo_goal"];
$cel_index     = (int)$_POST["cel_goal"];
$tipusa        = $_POST["tipusa"];

// Közbenső goal
$res_kb = $conn->query("SELECT goal_index FROM kozbenso_goal LIMIT 1");
if (!$res_kb || $res_kb->num_rows == 0) {
    die("Hiba: közbenső célpont nincs beállítva! <a href='pont_pont.php'>Vissza</a>");
}
$kozbenso_index = (int)$res_kb->fetch_assoc()['goal_index'];

// Goal nevek lekérése
function getGoalName($conn, $index) {
    $res = $conn->query("SELECT Goal_name FROM Goals WHERE Index_=" . (int)$index . " LIMIT 1");
    if (!$res || $res->num_rows == 0) return null;
    return $res->fetch_assoc()['Goal_name'];
}

$indulo_name   = getGoalName($conn, $indulo_index);
$cel_name      = getGoalName($conn, $cel_index);
$kozbenso_name = getGoalName($conn, $kozbenso_index);

if (!$indulo_name || !$cel_name || !$kozbenso_name) {
    die("Hiba: érvénytelen célpont! <a href='pont_pont.php'>Vissza</a>");
}

// Naplózás segédfüggvény
function pp_log($sor) {
    $f = "/var/www/html/pm/tmp/pp_log.txt";
    $ts = date("Y-m-d H:i:s");
    file_put_contents($f, "[$ts] $sor\n", FILE_APPEND | LOCK_EX);
}

if ($tipusa === "azonnali") {
    $caller  = isset($_SESSION["username"]) ? $_SESSION["username"] : str_replace('.', '_', $_SERVER['REMOTE_ADDR']);
    $job_id  = date("Y_m_d_H_i_s") . "_" . $caller . "_PP";
    $parancs = "queuemulti 3 2 $indulo_name pickup 10 $kozbenso_name pickup 10 $cel_name pickup 10 $job_id";

    foreach ([$indulo_name, $kozbenso_name, $cel_name] as $gn) {
        $gne = $conn->real_escape_string($gn);
        $jid = $conn->real_escape_string($job_id);
        $conn->query("INSERT INTO Button_Goals(Goal_name, Megjegyzes, akcio) VALUES('$gne', '$jid', 'aktiv')");
    }
    $conn->close();

    $myfile = fopen("/var/www/html/pm/tmp/newfile.txt", "w");
    fwrite($myfile, $parancs);
    fclose($myfile);
    exec("/var/www/html/pm/go.pl");

    pp_log("AZONNALI | " . $caller . " | " . $parancs);
    ?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Refresh" content="3; url=index.php">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
</head>
<body>
<div class="bg-image"></div>
<div class="bg-text">
<center><br>
<p style="color:#7fff7f; font-size:20px;">Feladat elküldve!</p>
<p style="color:#fff; font-size:16px;">Parancs:</p>
<p style="color:#ffdd88; font-size:13px; font-family:monospace; word-break:break-all; max-width:600px;"><?php echo htmlspecialchars($parancs); ?></p>
<p style="color:#aaa;">Átirányítás a főmenübe...</p>
</center>
</div>
</body>
</html>
    <?php
    exit;

} else {
    // Időzített – egyszeri
    $idopont_raw = $_POST["idopont"];
    $idopont_sql = $conn->real_escape_string(str_replace("T", " ", $idopont_raw) . ":00");

    // Előre összeállított parancs (a timer.php fogja ténylegesen elküldeni)
    $caller      = isset($_SESSION["username"]) ? $_SESSION["username"] : str_replace('.', '_', $_SERVER['REMOTE_ADDR']);
    $job_id_terv = date("Y_m_d_H_i_s", strtotime(str_replace("T", " ", $idopont_raw)))
                   . "_" . $caller . "_PP";
    $parancs_terv = "queuemulti 3 2 $indulo_name pickup 10 $kozbenso_name pickup 10 $cel_name pickup 10 $job_id_terv";

    $conn->query("INSERT INTO egyedi_utemezesek(indulo_goal_index, kozbenso_goal_index, cel_goal_index, idopont, active)
                  VALUES($indulo_index, $kozbenso_index, $cel_index, '$idopont_sql', 1)");
    $conn->close();

    pp_log("IDOZITETT | " . $caller . " | inditas: " . str_replace("T", " ", $idopont_raw) . " | " . $parancs_terv);
    ?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Refresh" content="4; url=index.php">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
</head>
<body>
<div class="bg-image"></div>
<div class="bg-text">
<center><br>
<p style="color:#7fff7f; font-size:20px;">Időzített feladat elmentve!</p>
<p style="color:#fff; font-size:16px;">Indítás: <?php echo htmlspecialchars(str_replace("T", " ", $idopont_raw)); ?></p>
<p style="color:#fff; font-size:15px;">Tervezett parancs:</p>
<p style="color:#ffdd88; font-size:13px; font-family:monospace; word-break:break-all; max-width:600px;"><?php echo htmlspecialchars($parancs_terv); ?></p>
<p style="color:#aaa;">Átirányítás a főmenübe...</p>
</center>
</div>
</body>
</html>
    <?php
    exit;
}

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

// Ha nem főmenüből jött (nincs bejelentkezve), visszaküldés a pont_pont oldalra
$redirect_url = (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true)
    ? "index.php"
    : "pont_pont.php";

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
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Refresh" content="3; url=<?php echo $redirect_url; ?>">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager</title>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text" style="max-width:600px; text-align:center;">
<h2 class="page-title" style="color:#2e7d32;">&#10003; Feladat elküldve</h2>
<p style="color:#333; font-size:15px; margin:8px 0;">
  <?php echo htmlspecialchars($indulo_name); ?> &rarr; <?php echo htmlspecialchars($kozbenso_name); ?> &rarr; <?php echo htmlspecialchars($cel_name); ?>
</p>
<p style="color:#888; font-size:12px; font-family:monospace; word-break:break-all; max-width:560px; margin:10px auto;">
  <?php echo htmlspecialchars($parancs); ?>
</p>
<p style="color:#aaa; font-size:13px; margin-top:16px;">Átirányítás...</p>
</div>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>
    <?php
    exit;
}

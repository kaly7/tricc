<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$indulo_index = (int)$_POST["indulo_goal"];
$cel_index    = (int)$_POST["cel_goal"];
$tipusa       = $_POST["tipusa"] ?? 'azonnali';
$sablon_id    = 1;

// Goal nevek lekérése
function getGoalName($conn, $index) {
    $res = $conn->query("SELECT Goal_name FROM Goals WHERE Index_=" . (int)$index . " LIMIT 1");
    if (!$res || $res->num_rows == 0) return null;
    return $res->fetch_assoc()['Goal_name'];
}

$indulo_name = getGoalName($conn, $indulo_index);
$cel_name    = getGoalName($conn, $cel_index);

if (!$indulo_name || !$cel_name) {
    $conn->close();
    die("Hiba: érvénytelen célpont! <a href='pont_pont.php'>Vissza</a>");
}

// Sablon pontok lekérése egy szekcióhoz
function getSablonPontok($conn, $sablon_id, $szekcio) {
    $s   = $conn->real_escape_string($szekcio);
    $sid = (int)$sablon_id;
    $res = $conn->query(
        "SELECT g.Goal_name FROM pp_utvonal_sablon_pont p
         JOIN Goals g ON p.goal_index = g.Index_
         WHERE p.sablon_id = $sid AND p.szekcio = '$s'
         ORDER BY p.sorrend"
    );
    $result = [];
    if ($res) { while ($row = $res->fetch_assoc()) { $result[] = $row['Goal_name']; } }
    return $result;
}

$elotte = getSablonPontok($conn, $sablon_id, 'elotte');
$kozben = getSablonPontok($conn, $sablon_id, 'kozben');
$utana  = getSablonPontok($conn, $sablon_id, 'utana');

// Teljes útvonal: [elotte] + induló + [kozben] + cél + [utana]
$all_points = array_merge($elotte, [$indulo_name], $kozben, [$cel_name], $utana);

// Naplózás
function pp_log($sor) {
    $f = "/var/www/html/pm/tmp/pp_log.txt";
    $ts = date("Y-m-d H:i:s");
    file_put_contents($f, "[$ts] $sor\n", FILE_APPEND | LOCK_EX);
}

$caller       = isset($_SESSION["username"]) ? $_SESSION["username"] : str_replace('.', '_', $_SERVER['REMOTE_ADDR']);
$redirect_url = (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) ? "index.php" : "pont_pont.php";

// --- AZONNALI ---
if ($tipusa === "azonnali") {
    $job_id  = date("Y_m_d_H_i_s") . "_" . $caller . "_PP";
    $jid     = $conn->real_escape_string($job_id);
    $n       = count($all_points);
    $parancs = "queuemulti $n 2";
    foreach ($all_points as $gn) {
        $gne     = $conn->real_escape_string($gn);
        $conn->query("INSERT INTO Button_Goals(Goal_name, Megjegyzes, akcio) VALUES('$gne', '$jid', 'aktiv')");
        $parancs .= " $gn pickup 10";
    }
    $parancs .= " $job_id";
    $conn->close();

    $myfile = fopen("/var/www/html/pm/tmp/newfile.txt", "w");
    fwrite($myfile, $parancs);
    fclose($myfile);
    exec("/var/www/html/pm/go.pl");

    pp_log("AZONNALI | $caller | $parancs");
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
<h2 class="page-title" style="color:#2e7d32;">&#10003; Feladat elküldve a Fleet Managernek</h2>
<p style="color:#555; font-size:15px; margin:12px 0;">
  <?php echo htmlspecialchars(implode(' → ', $all_points)); ?>
</p>
<p style="color:#aaa; font-size:13px; margin-top:16px;">Átirányítás...</p>
</div>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>
    <?php
    exit;
}

// --- IDŐZÍTETT ---
$idopont_raw = trim($_POST["idopont"] ?? '');
$dt = DateTime::createFromFormat('Y-m-d\TH:i', $idopont_raw);
if (!$dt) {
    $conn->close();
    die("Érvénytelen időpont formátum. <a href='pont_pont.php'>Vissza</a>");
}
$idopont_sql   = $dt->format('Y-m-d H:i:00');
$idopont_safe  = $conn->real_escape_string($idopont_sql);

$conn->query(
    "INSERT INTO egyedi_utemezesek(indulo_goal_index, cel_goal_index, kozbenso_goal_index, sablon_id, idopont)
     VALUES($indulo_index, $cel_index, 0, $sablon_id, '$idopont_safe')"
);
$conn->close();

pp_log("IDŐZÍTETT | $caller | $idopont_sql | " . implode(' → ', $all_points));
header("location: pont_pont.php");
exit;

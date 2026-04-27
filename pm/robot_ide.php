<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$client_ip = $_SERVER['REMOTE_ADDR'];
$ip_safe   = $conn->real_escape_string($client_ip);

$res = $conn->query(
    "SELECT m.*, gc.Goal_name as cel_goal_name, gc.Megjegyzes as cel_megjegyzes
     FROM munkaallomas m
     JOIN Goals gc ON m.cel_goal_index = gc.Index_
     WHERE m.ip = '$ip_safe' LIMIT 1"
);
$allomas = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;

// Ha robot úton van, ellenőrizzük a job státuszát.
// Addig "úton", amíg legalább egy aktív (nem deleted) sor létezik a job_id-hoz.
$robot_vegzett = false;
if ($allomas && $allomas['allapot'] === 'uton' && !empty($allomas['aktiv_job_id'])) {
    $jid  = $conn->real_escape_string($allomas['aktiv_job_id']);
    $jres = $conn->query(
        "SELECT COUNT(*) as cnt FROM Button_Goals WHERE Megjegyzes='$jid' AND akcio != 'deleted'"
    );
    $jrow = $jres ? $jres->fetch_assoc() : null;
    if (!$jrow || (int)$jrow['cnt'] === 0) {
        $robot_vegzett = true;
    }
    if ($robot_vegzett) {
        $conn->query("UPDATE munkaallomas SET allapot='szabad', aktiv_job_id=NULL WHERE ip='$ip_safe'");
        $allomas['allapot']       = 'szabad';
        $allomas['aktiv_job_id']  = null;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot ide</title>
<?php if ($allomas && $allomas['allapot'] === 'uton'): ?>
<meta http-equiv="Refresh" content="5">
<?php endif; ?>
<style>
.nagy_gomb {
    font-size: 36px;
    padding: 40px 100px;
    border-radius: 14px;
    border: 3px solid #000;
    cursor: pointer;
    font-weight: bold;
    margin-top: 50px;
    display: block;
    width: 80%;
    margin-left: auto;
    margin-right: auto;
}
.gomb_ide    { background-color: #2e7d32; color: #fff; }
.gomb_ide:hover    { background-color: #1b5e20; }
.gomb_uton   { background-color: #f57f17; color: #fff; cursor: default; opacity: 0.85; }
.allapot_info { color: #ccc; font-size: 15px; margin-top: 15px; }
</style>
</head>
<body>
<div class="bg-image"></div>
<div class="bg-text">
Felhasználó: <?php echo isset($_SESSION["username"]) ? htmlspecialchars($_SESSION["username"]) : htmlspecialchars($client_ip); ?>
<center><br>
<a href="index.php" class="button_x">Főmenü</a><br><br><hr>

<?php if (!$allomas): ?>
  <h2 style="color:#ff8888;">Ismeretlen munkaállomás</h2>
  <p style="color:#ccc;">Ez az IP cím (<?php echo htmlspecialchars($client_ip); ?>) nincs konfigurálva.</p>
  <?php if (isset($_SESSION["admin"]) && $_SESSION["admin"] == "on"): ?>
  <a href="admin_munkaallomas.php" class="button_mentes">Munkaállomás beállítás</a>
  <?php endif; ?>
<?php else: ?>
  <h2 style="color:#fff;"><?php echo htmlspecialchars($allomas['nev']); ?></h2>

  <?php if ($allomas['allapot'] === 'uton'): ?>
    <button type="button" class="nagy_gomb gomb_uton" disabled>Robot úton...</button>
    <p class="allapot_info">Cél: <?php echo htmlspecialchars($allomas['cel_megjegyzes'] ?: $allomas['cel_goal_name']); ?></p>
    <p class="allapot_info" style="font-size:13px;">Az oldal automatikusan frissül, amint a robot megérkezett.</p>
  <?php else: ?>
    <form action="robot_ide_go.php" method="POST">
      <input type="hidden" name="allomas_id" value="<?php echo (int)$allomas['id']; ?>">
      <button type="submit" class="nagy_gomb gomb_ide">Robot ide</button>
      <p class="allapot_info">Cél: <?php echo htmlspecialchars($allomas['cel_megjegyzes'] ?: $allomas['cel_goal_name']); ?></p>
    </form>
  <?php endif; ?>
<?php endif; ?>
</center>
</div>
</body>
</html>

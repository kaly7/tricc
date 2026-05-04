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
    "SELECT m.*, gc.Goal_name as cel_goal_name, gc.Megjegyzes as cel_megjegyzes,
            gk.Goal_name as kozbenso_goal_name, gk.Megjegyzes as kozbenso_megjegyzes
     FROM munkaallomas m
     JOIN Goals gc ON m.cel_goal_index = gc.Index_
     LEFT JOIN Goals gk ON m.kozbenso_goal_index = gk.Index_
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

$job_lathatosag_ri = $allomas ? $allomas['job_lathatosag'] : 'semmi';
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
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center><br>
<hr>

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
    <?php if ($allomas['kozbenso_goal_index'] > 0): ?>
    <p class="allapot_info" style="font-size:12px;">Közbenső: <?php echo htmlspecialchars($allomas['kozbenso_megjegyzes'] ?: $allomas['kozbenso_goal_name']); ?></p>
    <?php endif; ?>
    <p class="allapot_info" style="font-size:13px;">Az oldal automatikusan frissül, amint a robot megérkezett.</p>
  <?php else: ?>
    <form action="robot_ide_go.php" method="POST">
      <input type="hidden" name="allomas_id" value="<?php echo (int)$allomas['id']; ?>">
      <button type="submit" class="nagy_gomb gomb_ide">Robot ide</button>
      <p class="allapot_info">Cél: <?php echo htmlspecialchars($allomas['cel_megjegyzes'] ?: $allomas['cel_goal_name']); ?></p>
      <?php if ($allomas['kozbenso_goal_index'] > 0): ?>
      <p class="allapot_info" style="font-size:12px;">Közbenső: <?php echo htmlspecialchars($allomas['kozbenso_megjegyzes'] ?: $allomas['kozbenso_goal_name']); ?></p>
      <?php endif; ?>
    </form>
  <?php endif; ?>
  <?php
    $aktiv_jobok_conn = new mysqli('localhost', 'robot', 'abrakadabra', 'Robot');
    $aktiv_jobok_lathatosag = $job_lathatosag_ri;
    $aktiv_jobok_tipus = 'RI';
    include __DIR__ . '/aktiv_jobok_inc.php';
    $aktiv_jobok_conn->close();
  ?>
<?php endif; ?>
</center>
</div>
</body>
</html>

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

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$client_ip = $_SERVER['REMOTE_ADDR'];
$ip_safe   = $conn->real_escape_string($client_ip);

$res = $conn->query(
    "SELECT m.*, gc.Goal_name as cel_goal_name, gc.Megjegyzes as cel_megjegyzes,
            gv.Goal_name as vissza_goal_name, gv.Megjegyzes as vissza_megjegyzes
     FROM munkaallomas m
     JOIN Goals gc ON m.cel_goal_index = gc.Index_
     JOIN Goals gv ON m.vissza_goal_index = gv.Index_
     WHERE m.ip = '$ip_safe' LIMIT 1"
);
$allomas = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot ide</title>
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
.gomb_vissza { background-color: #b71c1c; color: #fff; }
.gomb_vissza:hover { background-color: #7f0000; }
.allapot_info { color: #ccc; font-size: 15px; margin-top: 15px; }
</style>
</head>
<body>
<div class="bg-image"></div>
<div class="bg-text">
Felhasználó: <?php echo htmlspecialchars($_SESSION["username"]); ?>
<center><br>
<a href="index.php" class="button_x">Főmenü</a><br><br><hr>

<?php if (!$allomas): ?>
  <h2 style="color:#ff8888;">Ismeretlen munkaállomás</h2>
  <p style="color:#ccc;">Ez az IP cím (<?php echo htmlspecialchars($client_ip); ?>) nincs konfigurálva.</p>
  <?php if ($_SESSION["admin"] == "on"): ?>
  <a href="admin_munkaallomas.php" class="button_mentes">Munkaállomás beállítás</a>
  <?php endif; ?>
<?php else: ?>
  <h2 style="color:#fff;"><?php echo htmlspecialchars($allomas['nev']); ?></h2>
  <form action="robot_ide_go.php" method="POST">
    <input type="hidden" name="allomas_id" value="<?php echo (int)$allomas['id']; ?>">
    <?php if ($allomas['allapot'] === 'vissza'): ?>
      <button type="submit" class="nagy_gomb gomb_ide">Robot ide</button>
      <p class="allapot_info">Cél: <?php echo htmlspecialchars($allomas['cel_megjegyzes'] ?: $allomas['cel_goal_name']); ?></p>
    <?php else: ?>
      <button type="submit" class="nagy_gomb gomb_vissza">Vissza</button>
      <p class="allapot_info">Visszatér: <?php echo htmlspecialchars($allomas['vissza_megjegyzes'] ?: $allomas['vissza_goal_name']); ?></p>
    <?php endif; ?>
  </form>
<?php endif; ?>
</center>
</div>
</body>
</html>

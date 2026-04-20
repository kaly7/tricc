<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["admin"] != "on") {
    header("location: index.php");
    exit;
}

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$uzenet = "";
if (isset($_POST["kozbenso_save"])) {
    $goal_index = (int)$_POST["goal_index"];
    $conn->query("DELETE FROM kozbenso_goal");
    if ($goal_index > 0) {
        $conn->query("INSERT INTO kozbenso_goal(goal_index) VALUES($goal_index)");
    }
    $uzenet = "Mentve!";
}

$aktualis = 0;
$res = $conn->query("SELECT goal_index FROM kozbenso_goal LIMIT 1");
if ($res && $res->num_rows > 0) { $aktualis = (int)$res->fetch_assoc()['goal_index']; }

$goals = [];
$result = $conn->query("SELECT Index_, Goal_name, Megjegyzes FROM Goals WHERE Active='Y' ORDER BY Megjegyzes");
while ($row = $result->fetch_assoc()) { $goals[] = $row; }
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Közbenső célpont beállítás</title>
<style>
select { padding: 6px 10px; font-size: 15px; border-radius: 5px; border: 1px solid #888; background: #eee; color: #222; }
</style>
</head>
<body>
<div class="bg-image"></div>
<div class="bg-text">
Felhasználó: <?php echo htmlspecialchars($_SESSION["username"]); ?>
<center><br>
<a href="index.php" class="button_x">Főmenü</a><br><br><hr>
<h2 style="color:#fff;">Pont-pont: közbenső célpont beállítása</h2>
<p style="color:#ccc;">Ez a célpont automatikusan bekerül minden pont-pont útvonalba az induló és a cél közé. A felhasználók nem látják.</p>

<?php if ($uzenet): ?>
<p style="color:#7fff7f; font-size:18px;"><?php echo $uzenet; ?></p>
<?php endif; ?>

<form action="admin_kozbenso_goal.php" method="POST">
<input type="hidden" name="kozbenso_save" value="1">
<table class="blueTable">
<thead><tr><th>Közbenső célpont</th></tr></thead>
<tbody>
<tr><td style="padding:12px;">
  <select name="goal_index">
    <option value="0">-- nincs beállítva --</option>
    <?php foreach ($goals as $g): ?>
    <option value="<?php echo (int)$g['Index_']; ?>" <?php if ($aktualis == (int)$g['Index_']) echo 'selected'; ?>>
      <?php echo htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']); ?>
      (<?php echo htmlspecialchars($g['Goal_name']); ?>)
    </option>
    <?php endforeach; ?>
  </select>
</td></tr>
</tbody>
</table>
<br>
<input type="submit" class="button_mentes" value="Mentés">
</form>
</center>
</div>
</body>
</html>

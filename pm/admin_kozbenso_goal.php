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

if (isset($_POST["mentes"])) {
    $goal_index = (int)$_POST["goal_index"];
    $conn->query("DELETE FROM kozbenso_goal");
    if ($goal_index > 0) {
        $conn->query("INSERT INTO kozbenso_goal(goal_index) VALUES($goal_index)");
    }

    $val = in_array($_POST["pp_job_lathatosag"], ['semmi','sajat','osszes'])
           ? $_POST["pp_job_lathatosag"] : 'sajat';
    $v = $conn->real_escape_string($val);
    $conn->query("INSERT INTO pm_konfig(kulcs, ertek) VALUES('pp_job_lathatosag','$v')
                  ON DUPLICATE KEY UPDATE ertek='$v'");
    $uzenet = "Mentve!";
}

$aktualis = 0;
$res = $conn->query("SELECT goal_index FROM kozbenso_goal LIMIT 1");
if ($res && $res->num_rows > 0) { $aktualis = (int)$res->fetch_assoc()['goal_index']; }

$goals = [];
$result = $conn->query("SELECT Index_, Goal_name, Megjegyzes FROM Goals WHERE Active='Y' ORDER BY Megjegyzes");
while ($row = $result->fetch_assoc()) { $goals[] = $row; }

$res_kfg = $conn->query("SELECT ertek FROM pm_konfig WHERE kulcs='pp_job_lathatosag' LIMIT 1");
$pp_lathatosag = ($res_kfg && $res_kfg->num_rows > 0) ? $res_kfg->fetch_assoc()['ertek'] : 'sajat';

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Pont-pont beállítások</title>
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
<h2 style="color:#fff;">Pont-pont beállítások</h2>

<?php if ($uzenet): ?>
<p style="color:#7fff7f; font-size:18px;"><?php echo $uzenet; ?></p>
<?php endif; ?>

<form action="admin_kozbenso_goal.php" method="POST">
<input type="hidden" name="mentes" value="1">
<table class="blueTable">
<thead><tr><th colspan="2">Pont-pont beállítások</th></tr></thead>
<tbody>
<tr>
  <td style="padding:12px;">Közbenső célpont</td>
  <td style="padding:12px;">
    <p style="color:#555; font-size:12px; margin:0 0 6px;">Automatikusan bekerül minden pont-pont útvonalba az induló és a cél közé.</p>
    <select name="goal_index">
      <option value="0">-- nincs beállítva --</option>
      <?php foreach ($goals as $g): ?>
      <option value="<?php echo (int)$g['Index_']; ?>" <?php if ($aktualis == (int)$g['Index_']) echo 'selected'; ?>>
        <?php echo htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']); ?>
        (<?php echo htmlspecialchars($g['Goal_name']); ?>)
      </option>
      <?php endforeach; ?>
    </select>
  </td>
</tr>
<tr>
  <td style="padding:12px;">Aktív job lista</td>
  <td style="padding:12px;">
    <p style="color:#555; font-size:12px; margin:0 0 6px;">Mit jelenítsen meg a Pont-pont oldalon?</p>
    <select name="pp_job_lathatosag">
      <?php foreach (['semmi' => 'Semmi', 'sajat' => 'Csak saját (PP)', 'osszes' => 'Összes'] as $val => $label):
          $sel = ($pp_lathatosag === $val) ? ' selected' : '';
          echo "<option value=\"$val\"$sel>$label</option>";
      endforeach; ?>
    </select>
  </td>
</tr>
</tbody>
</table>
<br>
<input type="submit" class="button_mentes" value="Mentés">
</form>

</center>
</div>
</body>
</html>

<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$kozbenso_ok = false;
$res_kb = $conn->query("SELECT goal_index FROM kozbenso_goal LIMIT 1");
if ($res_kb && $res_kb->num_rows > 0) { $kozbenso_ok = true; }

$goals = [];
$result = $conn->query("SELECT Index_, Goal_name, Megjegyzes FROM Goals WHERE Active='Y' ORDER BY Megjegyzes");
while ($row = $result->fetch_assoc()) { $goals[] = $row; }

$res_kfg = $conn->query("SELECT ertek FROM pm_konfig WHERE kulcs='pp_job_lathatosag' LIMIT 1");
$pp_job_lathatosag = ($res_kfg && $res_kfg->num_rows > 0) ? $res_kfg->fetch_assoc()['ertek'] : 'sajat';

$conn->close();

// Szerver aktuális ideje + 1 óra, datetime-local formátumban
$default_idopont = date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Pont-pont útvonal</title>
<style>
.pp-table td { padding: 10px 8px; }
select, input[type=datetime-local] {
    padding: 6px 10px;
    font-size: 15px;
    border-radius: 5px;
    border: 1px solid #888;
    background: #eee;
    color: #222;
}
.figyelmeztes {
    background: #8B0000;
    color: #fff;
    padding: 10px 20px;
    border-radius: 6px;
    margin-bottom: 15px;
    font-size: 15px;
}
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center><br>
<hr>
<h2 class="page-title">Pont-pont útvonal</h2>

<?php if (!$kozbenso_ok): ?>
<div class="figyelmeztes">Figyelem: a közbenső célpont nincs beállítva! Az útvonal nem indítható.<br>
<?php if (isset($_SESSION["admin"]) && $_SESSION["admin"] == "on"): ?>
<a href="admin_kozbenso_goal.php" style="color:#ffdd88;">Beállítás itt</a>
<?php endif; ?>
</div>
<?php endif; ?>

<form action="pont_pont_go.php" method="POST">
<table class="blueTable pp-table">
<thead><tr><th colspan="2">Útvonal beállítás</th></tr></thead>
<tbody>
<tr>
  <td>Induló célpont:</td>
  <td>
    <select name="indulo_goal" required>
      <option value="">-- válassz --</option>
      <?php foreach ($goals as $g): ?>
      <option value="<?php echo (int)$g['Index_']; ?>">
        <?php echo htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']); ?>
      </option>
      <?php endforeach; ?>
    </select>
  </td>
</tr>
<tr>
  <td>Cél célpont:</td>
  <td>
    <select name="cel_goal" required>
      <option value="">-- válassz --</option>
      <?php foreach ($goals as $g): ?>
      <option value="<?php echo (int)$g['Index_']; ?>">
        <?php echo htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']); ?>
      </option>
      <?php endforeach; ?>
    </select>
  </td>
</tr>
<tr>
  <td>Indítás:</td>
  <td>
    <label><input type="radio" name="tipusa" value="azonnali" checked onchange="toggleIdopont(false)"> Azonnali</label>
    &nbsp;&nbsp;
    <label><input type="radio" name="tipusa" value="idozitett" onchange="toggleIdopont(true)"> Időzített</label>
  </td>
</tr>
<tr id="idopont_sor" style="display:none;">
  <td>Időpont:</td>
  <td>
    <input type="datetime-local" name="idopont" id="idopont">
  </td>
</tr>
</tbody>
</table>
<br>
<input type="submit" class="button_mentes" value="Elküldés" <?php if (!$kozbenso_ok) echo 'disabled title="Közbenső célpont nincs beállítva"'; ?>>
</form>

<?php
$aktiv_jobok_conn = new mysqli('localhost', 'robot', 'abrakadabra', 'Robot');
$aktiv_jobok_lathatosag = $pp_job_lathatosag;
$aktiv_jobok_tipus = 'PP';
include __DIR__ . '/aktiv_jobok_inc.php';
$aktiv_jobok_conn->close();
?>

</center>
</div>
<script>
function toggleIdopont(show) {
    var sor = document.getElementById('idopont_sor');
    var inp = document.getElementById('idopont');
    sor.style.display = show ? 'table-row' : 'none';
    inp.required = show;
    if (show && !inp.value) {
        inp.value = '<?php echo $default_idopont; ?>';
    }
}
</script>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>

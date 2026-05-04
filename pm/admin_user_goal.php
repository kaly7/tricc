<?php
$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";

session_start();
if (isset($_POST["login_name"])) {
    $login_name   = $_POST["login_name"];
    $login_passwd = $_POST["login_passwd"];
    $sql  = "select * from Felhasznalok where nev=\"".$login_name."\" and jelszo=\"".$login_passwd."\"";
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
    $result = $conn->query($sql);
    $number = 0;
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $nev[$number]   = $row["nev"];
            $admin[$number] = $row["admin"];
            $Index[$number] = $row["Index_"];
            $number++;
        }
        $_SESSION["loggedin"]  = true;
        $_SESSION["username"]  = $nev[0];
        $_SESSION["admin"]     = $admin[0];
        $_SESSION["logintime"] = time();
        $_SESSION["user_id"]   = $Index[0];
    } else {
        header("location: login.php?x=1");
    }
} else {
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        header("location: login.php"); exit;
    }
}

$_felhasznalo = '';
if (isset($_GET["id"])) {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
    $result = $conn->query("SELECT * FROM Felhasznalok WHERE Index_ = '".$_GET["id"]."'");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_felhasznalo = $row["nev"];
    }
    $conn->close();
}

$user_id_ = isset($_GET["id"]) ? $_GET["id"] : $_SESSION["user_id"];

$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query("SELECT * FROM Felhasznalo_goal_eleje WHERE Felhasznalo_index = \"".$user_id_."\"");
$number = 0;
$eleje_Goal_index = []; $eleje_Akcio = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $eleje_Goal_index[$number] = $row["Goal_index"];
        $eleje_Akcio[$number]      = $row["Akcio"];
        $number++;
    }
}
$eleje_number = $number;
$conn->close();

$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query("SELECT * FROM Felhasznalo_goal_vege WHERE Felhasznalo_index = \"".$user_id_."\"");
$number = 0;
$vege_Goal_index = []; $vege_Akcio = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $vege_Goal_index[$number] = $row["Goal_index"];
        $vege_Akcio[$number]      = $row["Akcio"];
        $number++;
    }
}
$vege_number = $number;
$conn->close();

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$result = $conn->query("SELECT Index_, Megjegyzes FROM Goals WHERE Active='Y' ORDER BY Megjegyzes");
$goals_number = 0; $goal_megjegyzes = []; $goal_Index_ = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $goal_megjegyzes[$goals_number] = $row["Megjegyzes"];
        $goal_Index_[$goals_number]     = $row["Index_"];
        $goals_number++;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager</title>
<style>
.seq-section {
    background: #f8f8f8;
    border: 1px solid #e0e0e0;
    border-left: 4px solid #EE3124;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 20px;
    text-align: left;
}
.seq-section h3 {
    margin: 0 0 12px;
    font-size: 13px;
    font-weight: 700;
    color: #EE3124;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.seq-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
    flex-wrap: wrap;
}
.seq-row select { flex: 1; min-width: 140px; }
.remove_field, .remove_field2 {
    color: #fff;
    background-color: #c62828;
    font-size: 12px;
    font-weight: 600;
    border: none;
    border-radius: 8px;
    padding: 5px 12px;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
}
.remove_field:hover, .remove_field2:hover {
    background-color: #8b0000;
    text-decoration: none;
    color: #fff;
}
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center>

<?php if ($_felhasznalo): ?>
<h2 style="margin:8px 0 4px; color:#333;">Fix célpontok – <span style="color:#EE3124;"><?php echo htmlspecialchars($_felhasznalo); ?></span></h2>
<?php else: ?>
<h2 style="margin:8px 0 4px; color:#333;">Fix célpontok – saját</h2>
<?php endif; ?>
<p style="color:#666; font-size:13px; margin-bottom:20px;">
  Az <strong>Eleje</strong> szekvencia minden küldetés előtt, a <strong>Vége</strong> szekvencia minden küldetés után fut le automatikusan.
</p>

<script src="jquery.min.js"></script>
<script>
$(document).ready(function() {
    var max = 30;
    var w1 = $(".input_fields_wrap");
    var w2 = $(".input_fields_wrap2");

    var goalOptions = '<?php
        $opts = '';
        for ($i = 0; $i < $goals_number; $i++) {
            $opts .= '<option value="'.$goal_Index_[$i].'">'.htmlspecialchars($goal_megjegyzes[$i], ENT_QUOTES).'</option>';
        }
        echo addslashes($opts);
    ?>';
    var akcioOptions = '<option value="pickup">pickup</option><option value="dropoff">dropoff</option>';

    function newRow(nameG, nameA) {
        return '<div class="seq-row">'
            + '<select name="' + nameG + '">' + goalOptions + '</select>'
            + '<select name="' + nameA + '">' + akcioOptions + '</select>'
            + '<a href="#" class="remove_field">Törlés</a>'
            + '</div>';
    }

    $(".add_field_button").click(function(e) {
        e.preventDefault();
        if (w1.find('.seq-row').length < max) w1.append(newRow('mytext2[]','akcio[]'));
    });
    $(".add_field_button2").click(function(e) {
        e.preventDefault();
        if (w2.find('.seq-row').length < max) w2.append(newRow('mytext22[]','akcio22[]'));
    });
    w1.on("click", ".remove_field",  function(e) { e.preventDefault(); $(this).closest('.seq-row').remove(); });
    w2.on("click", ".remove_field2", function(e) { e.preventDefault(); $(this).closest('.seq-row').remove(); });
});
</script>

<form action="admin_user_goal_save.php" method="post" style="width:100%;max-width:680px;margin:0 auto;text-align:left;">
<?php if (isset($_GET["id"])): ?>
<input type="hidden" name="id" value="<?php echo (int)$_GET['id']; ?>">
<?php endif; ?>

<div class="seq-section">
  <h3>&#9654; Eleje szekvencia</h3>
  <div class="input_fields_wrap">
    <?php for ($ie = 0; $ie < $eleje_number; $ie++): ?>
    <div class="seq-row">
      <select name="mytext2[]">
        <?php for ($i = 0; $i < $goals_number; $i++): ?>
        <option value="<?php echo $goal_Index_[$i]; ?>" <?php echo $goal_Index_[$i] == $eleje_Goal_index[$ie] ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($goal_megjegyzes[$i]); ?>
        </option>
        <?php endfor; ?>
      </select>
      <select name="akcio[]">
        <option value="pickup"  <?php echo $eleje_Akcio[$ie] === 'pickup'  ? 'selected' : ''; ?>>pickup</option>
        <option value="dropoff" <?php echo $eleje_Akcio[$ie] === 'dropoff' ? 'selected' : ''; ?>>dropoff</option>
      </select>
      <a href="#" class="remove_field">Törlés</a>
    </div>
    <?php endfor; ?>
  </div>
  <button class="add_field_button mybutton_vh" style="margin-top:10px;font-size:13px;padding:6px 18px;">+ Új lépés</button>
</div>

<div class="seq-section">
  <h3>&#9654; Vége szekvencia</h3>
  <div class="input_fields_wrap2">
    <?php for ($ie = 0; $ie < $vege_number; $ie++): ?>
    <div class="seq-row">
      <select name="mytext22[]">
        <?php for ($i = 0; $i < $goals_number; $i++): ?>
        <option value="<?php echo $goal_Index_[$i]; ?>" <?php echo $goal_Index_[$i] == $vege_Goal_index[$ie] ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($goal_megjegyzes[$i]); ?>
        </option>
        <?php endfor; ?>
      </select>
      <select name="akcio22[]">
        <option value="pickup"  <?php echo $vege_Akcio[$ie] === 'pickup'  ? 'selected' : ''; ?>>pickup</option>
        <option value="dropoff" <?php echo $vege_Akcio[$ie] === 'dropoff' ? 'selected' : ''; ?>>dropoff</option>
      </select>
      <a href="#" class="remove_field2">Törlés</a>
    </div>
    <?php endfor; ?>
  </div>
  <button class="add_field_button2 mybutton_vh" style="margin-top:10px;font-size:13px;padding:6px 18px;">+ Új lépés</button>
</div>

<div style="text-align:center;margin-top:4px;">
  <input type="submit" class="button_mentes" value="Mentés">
</div>
</form>

</center>
</div>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>

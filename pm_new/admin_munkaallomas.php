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

// Törlés
if (isset($_GET["delete"])) {
    $del_id = (int)$_GET["delete"];
    $conn->query("DELETE FROM munkaallomas WHERE id=$del_id");
    $uzenet = "Munkaállomás törölve.";
}

// Mentés (új vagy szerkesztés)
if (isset($_POST["mentes"])) {
    $id  = (int)$_POST["id"];
    $ip  = $conn->real_escape_string(trim($_POST["ip"]));
    $nev = $conn->real_escape_string(trim($_POST["nev"]));
    $cel       = (int)$_POST["cel_goal_index"];
    $vissza    = (int)$_POST["vissza_goal_index"];
    $kozbenso  = (int)$_POST["kozbenso_goal_index"];
    $lathatosag = in_array($_POST["job_lathatosag"], ['semmi','sajat','osszes'])
                  ? $_POST["job_lathatosag"] : 'sajat';

    if ($id === 0) {
        $conn->query("INSERT INTO munkaallomas(ip, nev, cel_goal_index, vissza_goal_index, kozbenso_goal_index, job_lathatosag, allapot)
                      VALUES('$ip', '$nev', $cel, $vissza, $kozbenso, '$lathatosag', 'szabad')");
        $uzenet = "Új munkaállomás hozzáadva.";
    } else {
        $conn->query("UPDATE munkaallomas SET ip='$ip', nev='$nev', cel_goal_index=$cel, vissza_goal_index=$vissza,
                      kozbenso_goal_index=$kozbenso, job_lathatosag='$lathatosag' WHERE id=$id");
        $uzenet = "Módosítás mentve.";
    }
}

// Lista
$allomas_list = [];
$res = $conn->query(
    "SELECT m.*, gc.Megjegyzes as cel_megjegyzes, gc.Goal_name as cel_goal_name,
            gv.Megjegyzes as vissza_megjegyzes, gv.Goal_name as vissza_goal_name,
            gk.Megjegyzes as kozbenso_megjegyzes, gk.Goal_name as kozbenso_goal_name
     FROM munkaallomas m
     JOIN Goals gc ON m.cel_goal_index = gc.Index_
     LEFT JOIN Goals gv ON m.vissza_goal_index = gv.Index_
     LEFT JOIN Goals gk ON m.kozbenso_goal_index = gk.Index_
     ORDER BY m.nev"
);
if ($res) { while ($row = $res->fetch_assoc()) { $allomas_list[] = $row; } }

// Aktív goal-ok a selectekhez
$goals = [];
$res2 = $conn->query("SELECT Index_, Goal_name, Megjegyzes FROM Goals WHERE Active='Y' ORDER BY Megjegyzes");
while ($row = $res2->fetch_assoc()) { $goals[] = $row; }

// Szerkesztett rekord
$edit = null;
if (isset($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];
    $res3 = $conn->query("SELECT * FROM munkaallomas WHERE id=$edit_id LIMIT 1");
    if ($res3 && $res3->num_rows > 0) { $edit = $res3->fetch_assoc(); }
}
$conn->close();

function goalSelect($name, $goals, $selected = 0) {
    echo "<select name=\"$name\" style=\"padding:5px;font-size:13px;background:#eee;color:#222;border-radius:4px;\">";
    foreach ($goals as $g) {
        $label = htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']);
        $sel   = ((int)$selected === (int)$g['Index_']) ? ' selected' : '';
        echo "<option value=\"{$g['Index_']}\"$sel>$label</option>";
    }
    echo "</select>";
}
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Munkaállomások kezelése</title>
<style>
input[type=text] { padding:5px 8px; font-size:14px; border-radius:4px; border:1px solid #888; background:#eee; color:#222; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center><br>

<h2 class="page-title">Munkaállomások kezelése</h2>
<p style="color:#666; text-align:center;">IP-cím alapján azonosított állomások – ezekről a "Robot ide" gomb érhető el.</p>

<?php if ($uzenet): ?>
<p style="color:#2e7d32; font-size:16px; font-weight:600;"><?php echo htmlspecialchars($uzenet); ?></p>
<?php endif; ?>

<table class="blueTable">
<thead><tr>
  <th>Név</th><th>IP cím</th><th>"Robot ide" cél</th><th>Közbenső</th><th>"Vissza" cél</th><th>Job lista</th><th>Állapot</th><th>Műveletek</th>
</tr></thead>
<tbody>
<?php if (empty($allomas_list)): ?>
<tr><td colspan="6" style="text-align:center;">Nincs munkaállomás felvéve.</td></tr>
<?php endif; ?>
<?php foreach ($allomas_list as $a): ?>
<tr>
  <td><?php echo htmlspecialchars($a['nev']); ?></td>
  <td><?php echo htmlspecialchars($a['ip']); ?></td>
  <td><?php echo htmlspecialchars($a['cel_megjegyzes'] ?: $a['cel_goal_name']); ?></td>
  <td><?php echo htmlspecialchars($a['kozbenso_megjegyzes'] ?: ($a['kozbenso_goal_name'] ?? '—')); ?></td>
  <td><?php echo htmlspecialchars($a['vissza_megjegyzes'] ?: ($a['vissza_goal_name'] ?? '—')); ?></td>
  <td><?php echo htmlspecialchars($a['job_lathatosag']); ?></td>
  <td><?php echo $a['allapot'] === 'uton' ? 'Robot úton' : 'Szabad'; ?></td>
  <td style="white-space:nowrap;">
    <a href="admin_munkaallomas.php?edit=<?php echo (int)$a['id']; ?>" class="button_mentes" style="font-size:12px;padding:4px 10px;display:block;margin-bottom:4px;">Szerkesztés</a>
    <a href="admin_munkaallomas.php?delete=<?php echo (int)$a['id']; ?>" class="button_delete" style="display:block;" onclick="return confirm('Biztosan törlöd ezt a munkaállomást?')">Törlés</a>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<br>
<h3 style="color:#fff;"><?php echo $edit ? 'Munkaállomás szerkesztése' : 'Új munkaállomás hozzáadása'; ?></h3>
<form action="admin_munkaallomas.php" method="POST">
<input type="hidden" name="mentes" value="1">
<input type="hidden" name="id" value="<?php echo $edit ? (int)$edit['id'] : 0; ?>">
<table class="blueTable">
<tbody>
<tr>
  <td>Név:</td>
  <td><input type="text" name="nev" value="<?php echo $edit ? htmlspecialchars($edit['nev']) : ''; ?>" maxlength="50" size="30" required placeholder="pl. Csomagoló sor"></td>
</tr>
<tr>
  <td>IP cím:</td>
  <td><input type="text" name="ip" value="<?php echo $edit ? htmlspecialchars($edit['ip']) : ''; ?>" maxlength="20" size="20" required placeholder="pl. 192.168.1.100"></td>
</tr>
<tr>
  <td>"Robot ide" cél:</td>
  <td><?php goalSelect('cel_goal_index', $goals, $edit ? (int)$edit['cel_goal_index'] : 0); ?></td>
</tr>
<tr>
  <td>Közbenső cél (opcionális):</td>
  <td>
    <select name="kozbenso_goal_index" style="padding:5px;font-size:13px;background:#eee;color:#222;border-radius:4px;">
      <option value="0">— nincs közbenső —</option>
      <?php foreach ($goals as $g):
          $sel = ($edit && (int)$edit['kozbenso_goal_index'] === (int)$g['Index_']) ? ' selected' : '';
          echo "<option value=\"{$g['Index_']}\"$sel>" . htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']) . "</option>";
      endforeach; ?>
    </select>
  </td>
</tr>
<tr>
  <td>"Vissza" cél:</td>
  <td><?php goalSelect('vissza_goal_index', $goals, $edit ? (int)$edit['vissza_goal_index'] : 0); ?></td>
</tr>
<tr>
  <td>Aktív job lista:</td>
  <td>
    <select name="job_lathatosag" style="padding:5px;font-size:13px;background:#eee;color:#222;border-radius:4px;">
      <?php
      $lat_cur = $edit ? $edit['job_lathatosag'] : 'sajat';
      foreach (['semmi' => 'Semmi', 'sajat' => 'Csak saját (RI)', 'osszes' => 'Összes'] as $val => $label):
          $sel = ($lat_cur === $val) ? ' selected' : '';
          echo "<option value=\"$val\"$sel>$label</option>";
      endforeach; ?>
    </select>
  </td>
</tr>
</tbody>
</table>
<br>
<input type="submit" class="button_mentes" value="<?php echo $edit ? 'Mentés' : 'Hozzáadás'; ?>">
<?php if ($edit): ?>
&nbsp;&nbsp;<a href="admin_munkaallomas.php" class="button_x">Mégse</a>
<?php endif; ?>
</form>
</center>
</div>
</body>
</html>

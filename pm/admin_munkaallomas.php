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
    $conn->query("DELETE FROM munkaallomas_utvonal WHERE allomas_id=$del_id");
    $conn->query("DELETE FROM munkaallomas WHERE id=$del_id");
    $uzenet = "Munkaállomás törölve.";
}

// Mentés (új vagy szerkesztés)
if (isset($_POST["mentes"])) {
    $id  = (int)$_POST["id"];
    $ip  = $conn->real_escape_string(trim($_POST["ip"]));
    $nev = $conn->real_escape_string(trim($_POST["nev"]));
    $lathatosag = in_array($_POST["job_lathatosag"], ['semmi','sajat','osszes'])
                  ? $_POST["job_lathatosag"] : 'sajat';

    // Útvonal pontok: rendezett tömb goal_index értékekkel
    $pont_indexes = isset($_POST["pont_goal_index"]) && is_array($_POST["pont_goal_index"])
                    ? $_POST["pont_goal_index"] : [];

    if ($id === 0) {
        $conn->query("INSERT INTO munkaallomas(ip, nev, cel_goal_index, vissza_goal_index, allapot, job_lathatosag)
                      VALUES('$ip', '$nev', 0, 0, 'szabad', '$lathatosag')");
        $id = (int)$conn->insert_id;
        $uzenet = "Új munkaállomás hozzáadva.";
    } else {
        $conn->query("UPDATE munkaallomas SET ip='$ip', nev='$nev', job_lathatosag='$lathatosag' WHERE id=$id");
        $uzenet = "Módosítás mentve.";
    }

    // Útvonal mentése
    $conn->query("DELETE FROM munkaallomas_utvonal WHERE allomas_id=$id");
    $sorrend = 10;
    foreach ($pont_indexes as $gi) {
        $gi = (int)$gi;
        if ($gi > 0) {
            $conn->query("INSERT INTO munkaallomas_utvonal(allomas_id, sorrend, goal_index) VALUES($id, $sorrend, $gi)");
            $sorrend += 10;
        }
    }
}

// Lista
$allomas_list = [];
$res = $conn->query("SELECT * FROM munkaallomas ORDER BY nev");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        // Útvonal lekérdezése
        $aid  = (int)$row['id'];
        $rres = $conn->query(
            "SELECT g.Megjegyzes, g.Goal_name
             FROM munkaallomas_utvonal u
             JOIN Goals g ON u.goal_index = g.Index_
             WHERE u.allomas_id = $aid ORDER BY u.sorrend"
        );
        $row['utvonal'] = [];
        if ($rres) {
            while ($rrow = $rres->fetch_assoc()) {
                $row['utvonal'][] = $rrow['Megjegyzes'] ?: $rrow['Goal_name'];
            }
        }
        $allomas_list[] = $row;
    }
}

// Aktív goal-ok a selectekhez
$goals = [];
$res2 = $conn->query("SELECT Index_, Goal_name, Megjegyzes FROM Goals WHERE Active='Y' ORDER BY Megjegyzes");
while ($row = $res2->fetch_assoc()) { $goals[] = $row; }

// Szerkesztett rekord
$edit = null;
$edit_utvonal = [];
if (isset($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];
    $res3 = $conn->query("SELECT * FROM munkaallomas WHERE id=$edit_id LIMIT 1");
    if ($res3 && $res3->num_rows > 0) { $edit = $res3->fetch_assoc(); }
    if ($edit) {
        $rres = $conn->query(
            "SELECT u.goal_index, g.Goal_name, g.Megjegyzes
             FROM munkaallomas_utvonal u
             JOIN Goals g ON u.goal_index = g.Index_
             WHERE u.allomas_id = $edit_id ORDER BY u.sorrend"
        );
        if ($rres) {
            while ($rrow = $rres->fetch_assoc()) { $edit_utvonal[] = $rrow; }
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Munkaállomások kezelése</title>
<style>
input[type=text] { padding:5px 8px; font-size:14px; border-radius:4px; border:1px solid #888; background:#eee; color:#222; }
.pont-sor { display:flex; align-items:center; gap:6px; margin:4px 0; }
.pont-cimke { flex:1; padding:5px 10px; background:#2a3a4a; border-radius:4px; color:#eee; font-size:13px; }
.pont-btn { padding:3px 10px; border:none; border-radius:3px; cursor:pointer; font-size:13px; }
.pont-mozgat { background:#444; color:#eee; }
.pont-mozgat:disabled { opacity:0.3; cursor:default; }
.pont-torol { background:#c62828; color:#fff; }
.utvonal-cell { font-size:12px; color:#aaa; }
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
  <th>Név</th><th>IP cím</th><th>Útvonal</th><th>Job lista</th><th>Állapot</th><th>Műveletek</th>
</tr></thead>
<tbody>
<?php if (empty($allomas_list)): ?>
<tr><td colspan="6" style="text-align:center;">Nincs munkaállomás felvéve.</td></tr>
<?php endif; ?>
<?php foreach ($allomas_list as $a): ?>
<tr>
  <td><?php echo htmlspecialchars($a['nev']); ?></td>
  <td><?php echo htmlspecialchars($a['ip']); ?></td>
  <td class="utvonal-cell"><?php echo htmlspecialchars(implode(' → ', $a['utvonal'])) ?: '—'; ?></td>
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
<h3 style="color:#EE3124; font-size:14px; margin:18px 0 8px;"><?php echo $edit ? 'Munkaállomás szerkesztése' : 'Új munkaállomás hozzáadása'; ?></h3>
<form action="admin_munkaallomas.php" method="POST" id="allomas-form">
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
  <td style="vertical-align:top; padding-top:10px;">Útvonal pontjai:</td>
  <td>
    <div id="pontok-lista" style="min-width:300px; margin-bottom:8px;"></div>
    <div style="display:flex; gap:6px; align-items:center;">
      <select id="uj_pont_goal" style="padding:5px; font-size:13px; background:#eee; color:#222; border-radius:4px;">
        <?php foreach ($goals as $g): ?>
        <option value="<?php echo (int)$g['Index_']; ?>"><?php echo htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" onclick="addPont()" class="button_mentes" style="padding:5px 14px; font-size:13px;">+ Hozzáadás</button>
    </div>
  </td>
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

<script>
var pontok = <?php
    $js_pontok = [];
    foreach ($edit_utvonal as $p) {
        $js_pontok[] = [
            'goal_index' => (int)$p['goal_index'],
            'label'      => $p['Megjegyzes'] ?: $p['Goal_name'],
        ];
    }
    echo json_encode($js_pontok, JSON_UNESCAPED_UNICODE);
?>;

function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

function renderPontok(){
    var c=document.getElementById('pontok-lista');
    if(pontok.length===0){
        c.innerHTML='<p style="color:#888;font-size:13px;margin:4px 0;">Nincs pont felvéve – legalább 1 pont szükséges.</p>';
        return;
    }
    var h='';
    pontok.forEach(function(p,i){
        h+='<div class="pont-sor">'
          +'<input type="hidden" name="pont_goal_index[]" value="'+p.goal_index+'">'
          +'<span class="pont-cimke">'+esc(p.label)+'</span>'
          +'<button type="button" class="pont-btn pont-mozgat" onclick="movePont('+i+',-1)"'+(i===0?' disabled':'')+'>▲</button>'
          +'<button type="button" class="pont-btn pont-mozgat" onclick="movePont('+i+',1)"'+(i===pontok.length-1?' disabled':'')+'>▼</button>'
          +'<button type="button" class="pont-btn pont-torol" onclick="removePont('+i+')">Törlés</button>'
          +'</div>';
    });
    c.innerHTML=h;
}

function movePont(i,dir){
    var j=i+dir;
    if(j<0||j>=pontok.length)return;
    var tmp=pontok[i];pontok[i]=pontok[j];pontok[j]=tmp;
    renderPontok();
}

function removePont(i){
    pontok.splice(i,1);
    renderPontok();
}

function addPont(){
    var sel=document.getElementById('uj_pont_goal');
    pontok.push({goal_index:parseInt(sel.value),label:sel.options[sel.selectedIndex].text});
    renderPontok();
}

document.getElementById('allomas-form').addEventListener('submit',function(e){
    if(pontok.length===0){
        e.preventDefault();
        alert('Legalább 1 útvonalpontot meg kell adni!');
    }
});

renderPontok();
</script>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>

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

$sablon_id = 1;
$uzenet    = "";

if (isset($_POST["mentes"])) {
    // Pont-pont sablon pontok mentése (mindhárom szekció)
    $conn->query("DELETE FROM pp_utvonal_sablon_pont WHERE sablon_id=$sablon_id");

    foreach (['elotte', 'kozben', 'utana'] as $szekc) {
        $indexes = isset($_POST["{$szekc}_goal_index"]) && is_array($_POST["{$szekc}_goal_index"])
                   ? $_POST["{$szekc}_goal_index"] : [];
        $sorrend = 10;
        foreach ($indexes as $gi) {
            $gi = (int)$gi;
            if ($gi > 0) {
                $s = $conn->real_escape_string($szekc);
                $conn->query("INSERT INTO pp_utvonal_sablon_pont(sablon_id, szekcio, sorrend, goal_index)
                              VALUES($sablon_id, '$s', $sorrend, $gi)");
                $sorrend += 10;
            }
        }
    }

    // Job láthatóság mentése
    $val = in_array($_POST["pp_job_lathatosag"], ['semmi','sajat','osszes'])
           ? $_POST["pp_job_lathatosag"] : 'sajat';
    $v = $conn->real_escape_string($val);
    $conn->query("INSERT INTO pm_konfig(kulcs, ertek) VALUES('pp_job_lathatosag','$v')
                  ON DUPLICATE KEY UPDATE ertek='$v'");
    $uzenet = "Mentve!";
}

// Aktív goalok a selectekhez
$goals = [];
$result = $conn->query("SELECT Index_, Goal_name, Megjegyzes FROM Goals WHERE Active='Y' ORDER BY Megjegyzes");
while ($row = $result->fetch_assoc()) { $goals[] = $row; }

// Meglévő sablon pontok betöltése szekciónként
function loadSzekcio($conn, $sablon_id, $szekc) {
    $s   = $conn->real_escape_string($szekc);
    $sid = (int)$sablon_id;
    $res = $conn->query(
        "SELECT p.goal_index, g.Goal_name, g.Megjegyzes
         FROM pp_utvonal_sablon_pont p
         JOIN Goals g ON p.goal_index = g.Index_
         WHERE p.sablon_id = $sid AND p.szekcio = '$s'
         ORDER BY p.sorrend"
    );
    $rows = [];
    if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
    return $rows;
}

$elotte_pontok = loadSzekcio($conn, $sablon_id, 'elotte');
$kozben_pontok = loadSzekcio($conn, $sablon_id, 'kozben');
$utana_pontok  = loadSzekcio($conn, $sablon_id, 'utana');

$res_kfg = $conn->query("SELECT ertek FROM pm_konfig WHERE kulcs='pp_job_lathatosag' LIMIT 1");
$pp_lathatosag = ($res_kfg && $res_kfg->num_rows > 0) ? $res_kfg->fetch_assoc()['ertek'] : 'sajat';

$conn->close();

function jsRows($rows) {
    $out = [];
    foreach ($rows as $r) {
        $label = addslashes($r['Megjegyzes'] ?: $r['Goal_name']);
        $out[] = '{"goal_index":' . (int)$r['goal_index'] . ',"label":"' . $label . '"}';
    }
    return '[' . implode(',', $out) . ']';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Pont-pont beállítások</title>
<style>
select { padding: 6px 10px; font-size: 14px; border-radius: 5px; border: 1px solid #888; background: #eee; color: #222; }
.szekc-box { border: 1px solid #444; border-radius: 6px; padding: 12px 16px; margin: 10px 0 18px; background: #1a2a3a; }
.szekc-cim { font-size: 13px; font-weight: bold; color: #aad4ff; margin-bottom: 8px; }
.szekc-info { font-size: 11px; color: #888; margin-bottom: 8px; }
.pont-sor { display:flex; align-items:center; gap:6px; margin:4px 0; }
.pont-cimke { flex:1; padding:5px 10px; background:#2a3a4a; border-radius:4px; color:#eee; font-size:13px; }
.pont-btn { padding:3px 10px; border:none; border-radius:3px; cursor:pointer; font-size:13px; }
.pont-mozgat { background:#444; color:#eee; }
.pont-mozgat:disabled { opacity:0.3; cursor:default; }
.pont-torol { background:#c62828; color:#fff; }
.pont-add-row { display:flex; gap:8px; align-items:center; margin-top:8px; flex-wrap:wrap; }
.utvonal-preview { font-size: 12px; color: #aaa; background: #111; border-radius: 4px; padding: 8px 12px; margin: 12px 0 0; word-break: break-all; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center><br>

<h2 class="page-title">Pont-pont beállítások</h2>
<p style="color:#666; max-width:600px; text-align:center;">
  Az itt beállított pontok automatikusan bekerülnek <em>minden</em> pont-pont útvonalba, a felhasználó által választott induló és cél célpont köré.
</p>

<?php if ($uzenet): ?>
<p style="color:#2e7d32; font-size:16px; font-weight:600;"><?php echo $uzenet; ?></p>
<?php endif; ?>

<form action="admin_kozbenso_goal.php" method="POST" style="max-width:640px; text-align:left;">
<input type="hidden" name="mentes" value="1">

<!-- Induló előtt -->
<div class="szekc-box">
  <div class="szekc-cim">&#9312; Induló célpont ELŐTT</div>
  <div class="szekc-info">Ezek a pontok kerülnek az induló célpont elé (pl. kiindulási alapállás).</div>
  <div id="elotte-lista"></div>
  <div class="pont-add-row">
    <select id="elotte-sel"><?php foreach ($goals as $g): ?>
      <option value="<?php echo (int)$g['Index_']; ?>"><?php echo htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']); ?></option>
    <?php endforeach; ?></select>
    <button type="button" onclick="addPont('elotte')" class="button_mentes" style="padding:5px 14px;font-size:13px;">+ Hozzáadás</button>
  </div>
</div>

<!-- Induló és cél között -->
<div class="szekc-box">
  <div class="szekc-cim">&#9313; Induló és cél célpont KÖZÖTT</div>
  <div class="szekc-info">Ezek a pontok kerülnek az induló és a cél közé (pl. ellenőrzőpont, dokkoló).</div>
  <div id="kozben-lista"></div>
  <div class="pont-add-row">
    <select id="kozben-sel"><?php foreach ($goals as $g): ?>
      <option value="<?php echo (int)$g['Index_']; ?>"><?php echo htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']); ?></option>
    <?php endforeach; ?></select>
    <button type="button" onclick="addPont('kozben')" class="button_mentes" style="padding:5px 14px;font-size:13px;">+ Hozzáadás</button>
  </div>
</div>

<!-- Cél után -->
<div class="szekc-box">
  <div class="szekc-cim">&#9314; Cél célpont UTÁN</div>
  <div class="szekc-info">Ezek a pontok kerülnek a cél célpont után (pl. visszatérési pont, alapállás).</div>
  <div id="utana-lista"></div>
  <div class="pont-add-row">
    <select id="utana-sel"><?php foreach ($goals as $g): ?>
      <option value="<?php echo (int)$g['Index_']; ?>"><?php echo htmlspecialchars($g['Megjegyzes'] ?: $g['Goal_name']); ?></option>
    <?php endforeach; ?></select>
    <button type="button" onclick="addPont('utana')" class="button_mentes" style="padding:5px 14px;font-size:13px;">+ Hozzáadás</button>
  </div>
</div>

<!-- Útvonal előnézet -->
<div class="utvonal-preview" id="utvonal-preview"></div>

<!-- Job láthatóság -->
<table class="blueTable" style="margin-top:18px;">
<thead><tr><th colspan="2">Egyéb beállítások</th></tr></thead>
<tbody>
<tr>
  <td style="padding:10px;">Aktív job lista</td>
  <td style="padding:10px;">
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

<script>
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

var szekcio = {
    elotte: <?php echo jsRows($elotte_pontok); ?>,
    kozben: <?php echo jsRows($kozben_pontok); ?>,
    utana:  <?php echo jsRows($utana_pontok); ?>
};

function renderSzekcio(sz) {
    var c   = document.getElementById(sz + '-lista');
    var pts = szekcio[sz];
    if (pts.length === 0) {
        c.innerHTML = '<p style="color:#555;font-size:12px;margin:4px 0;">Nincs pont felvéve.</p>';
        updatePreview();
        return;
    }
    var h = '';
    pts.forEach(function(p, i) {
        h += '<div class="pont-sor">'
           + '<input type="hidden" name="' + sz + '_goal_index[]" value="' + p.goal_index + '">'
           + '<span class="pont-cimke">' + esc(p.label) + '</span>'
           + '<button type="button" class="pont-btn pont-mozgat" onclick="movePont(\'' + sz + '\',' + i + ',-1)"' + (i===0?' disabled':'') + '>▲</button>'
           + '<button type="button" class="pont-btn pont-mozgat" onclick="movePont(\'' + sz + '\',' + i + ',1)"'  + (i===pts.length-1?' disabled':'') + '>▼</button>'
           + '<button type="button" class="pont-btn pont-torol"  onclick="removePont(\'' + sz + '\',' + i + ')">✕</button>'
           + '</div>';
    });
    c.innerHTML = h;
    updatePreview();
}

function movePont(sz, i, dir) {
    var j = i + dir;
    var pts = szekcio[sz];
    if (j < 0 || j >= pts.length) return;
    var tmp = pts[i]; pts[i] = pts[j]; pts[j] = tmp;
    renderSzekcio(sz);
}

function removePont(sz, i) {
    szekcio[sz].splice(i, 1);
    renderSzekcio(sz);
}

function addPont(sz) {
    var sel = document.getElementById(sz + '-sel');
    szekcio[sz].push({goal_index: parseInt(sel.value), label: sel.options[sel.selectedIndex].text});
    renderSzekcio(sz);
}

function updatePreview() {
    var parts = [];
    szekcio.elotte.forEach(function(p){ parts.push(esc(p.label)); });
    parts.push('<strong style="color:#7fc">[Induló]</strong>');
    szekcio.kozben.forEach(function(p){ parts.push(esc(p.label)); });
    parts.push('<strong style="color:#7fc">[Cél]</strong>');
    szekcio.utana.forEach(function(p){ parts.push(esc(p.label)); });
    document.getElementById('utvonal-preview').innerHTML =
        '<span style="color:#666;font-size:11px;">Útvonal előnézet: </span>' + parts.join(' → ');
}

renderSzekcio('elotte');
renderSzekcio('kozben');
renderSzekcio('utana');
</script>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>

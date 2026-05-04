<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php"); exit;
}

$servername = "localhost"; $username = "robot"; $password = "abrakadabra"; $dbname = "Robot";

// Goals
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query("SELECT Index_, Goal_name, Megjegyzes FROM Goals WHERE Active='Y' ORDER BY Megjegyzes");
$goals_number = 0; $goal_name_megjegyzes = []; $goal_Index_ = []; $goal_real_name = [];
while ($row = $result->fetch_assoc()) {
    $goal_name_megjegyzes[$goals_number] = $row["Megjegyzes"];
    $goal_Index_[$goals_number]          = $row["Index_"];
    $goal_real_name[$goals_number]       = $row["Goal_name"];
    $goals_number++;
}
$conn->close();

// Eleje szekvencia
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query("SELECT * FROM Felhasznalo_goal_eleje WHERE Felhasznalo_index = '".(int)$_SESSION["user_id"]."'");
$eleje_Goal_index = []; $eleje_Akcio = []; $eleje_number = 0;
while ($row = $result->fetch_assoc()) {
    $eleje_Goal_index[$eleje_number] = $row["Goal_index"];
    $eleje_Akcio[$eleje_number]      = $row["Akcio"];
    $eleje_number++;
}
$conn->close();

// Vége szekvencia
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query("SELECT * FROM Felhasznalo_goal_vege WHERE Felhasznalo_index = '".(int)$_SESSION["user_id"]."'");
$vege_Goal_index = []; $vege_Akcio = []; $vege_number = 0;
while ($row = $result->fetch_assoc()) {
    $vege_Goal_index[$vege_number] = $row["Goal_index"];
    $vege_Akcio[$vege_number]      = $row["Akcio"];
    $vege_number++;
}
$conn->close();

// Route state: existing goals + newly added goal merged
$goals = isset($_POST['mytext2']) ? $_POST['mytext2'] : [];
if (isset($_POST["new"]) && $_POST["new"] !== '') {
    $goals[] = $_POST["new"];
}
if (isset($_POST["delete"])) {
    array_splice($goals, (int)$_POST["delete"], 1);
}

// Goal index → Megjegyzes név feloldása
function resolve_goal_name($goal_index, $goal_Index_, $goal_name_megjegyzes, $goals_number) {
    for ($i = 0; $i < $goals_number; $i++) {
        if ($goal_index == $goal_Index_[$i]) return $goal_name_megjegyzes[$i];
    }
    return '?';
}

// Hidden inputs helper
function hidden_goals($goals) {
    $out = '';
    foreach ($goals as $i => $v) {
        $out .= '<input type="hidden" name="mytext2['.$i.']" value="'.htmlspecialchars($v, ENT_QUOTES).'">';
    }
    return $out;
}
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
    padding: 10px 16px;
    margin-bottom: 12px;
    text-align: left;
}
.seq-section.vege { border-left-color: #666; }
.seq-section h3 {
    margin: 0 0 8px;
    font-size: 11px;
    font-weight: 700;
    color: #EE3124;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.seq-section.vege h3 { color: #666; }
.seq-pills { display: flex; flex-wrap: wrap; gap: 6px; }
.seq-pill {
    background: #007BC2;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    padding: 3px 12px;
    border-radius: 20px;
    white-space: nowrap;
}
.seq-section.vege .seq-pill { background: #666; }

.route-section {
    background: #fff;
    border: 2px solid #EE3124;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 12px;
    min-height: 60px;
}
.route-section h3 {
    margin: 0 0 10px;
    font-size: 11px;
    font-weight: 700;
    color: #EE3124;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.route-buttons { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.route-btn {
    background: #444;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    border: none;
    border-radius: 8px;
    padding: 7px 14px;
    cursor: pointer;
    transition: background 0.15s;
}
.route-btn:hover { background: #c62828; color: #fff; }
.route-empty { color: #aaa; font-size: 13px; font-style: italic; }

.exec-bar {
    display: flex;
    align-items: center;
    gap: 16px;
    background: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 12px 18px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.exec-bar label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    color: #444;
}
.exec-bar input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; }

.goals-section { margin-top: 4px; }
.goals-section h3 {
    margin: 0 0 10px;
    font-size: 11px;
    font-weight: 700;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.goals-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.goal-add-btn {
    background: #007BC2;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    border: none;
    border-radius: 8px;
    padding: 7px 16px;
    cursor: pointer;
    transition: background 0.15s;
}
.goal-add-btn:hover { background: #005f99; color: #fff; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">

<h2 class="page-title">Küldetés tervezés</h2>

<?php if ($eleje_number > 0): ?>
<div class="seq-section">
  <h3>&#9654; Eleje szekvencia</h3>
  <div class="seq-pills">
    <?php for ($ie = 0; $ie < $eleje_number; $ie++):
        $gname = resolve_goal_name($eleje_Goal_index[$ie], $goal_Index_, $goal_name_megjegyzes, $goals_number);
        $ap = $eleje_Akcio[$ie] === 'pickup' ? '/P' : '/D';
    ?>
    <span class="seq-pill"><?php echo htmlspecialchars($gname); ?> <?php echo $ap; ?></span>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>

<div class="route-section">
  <h3>Jelenlegi útvonal</h3>
  <div class="route-buttons">
    <?php if (empty($goals)): ?>
      <span class="route-empty">Nincs kiválasztott célpont – kattints az elérhető célok egyikére.</span>
    <?php else: ?>
      <?php foreach ($goals as $j => $val): ?>
      <form action="goals2.php" method="post" style="margin:0">
        <input type="hidden" name="delete" value="<?php echo $j; ?>">
        <?php echo hidden_goals($goals); ?>
        <button type="submit" class="route-btn"><?php echo htmlspecialchars($val); ?> &nbsp;✕</button>
      </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($vege_number > 0): ?>
<div class="seq-section vege">
  <h3>&#9654; Vége szekvencia</h3>
  <div class="seq-pills">
    <?php for ($ie = 0; $ie < $vege_number; $ie++):
        $gname = resolve_goal_name($vege_Goal_index[$ie], $goal_Index_, $goal_name_megjegyzes, $goals_number);
        $ap = $vege_Akcio[$ie] === 'pickup' ? '/P' : '/D';
    ?>
    <span class="seq-pill"><?php echo htmlspecialchars($gname); ?> <?php echo $ap; ?></span>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>

<form action="button_go2.php" method="post">
  <div class="exec-bar">
    <?php if ($eleje_number > 0): ?>
    <label><input type="checkbox" name="eleje_check"> Eleje szekvencia</label>
    <?php endif; ?>
    <?php if ($vege_number > 0): ?>
    <label><input type="checkbox" name="vege_check"> Vége szekvencia</label>
    <?php endif; ?>
    <button type="submit" class="button_mentes">&#9658;&nbsp; Végrehajtás</button>
    <?php echo hidden_goals($goals); ?>
  </div>
</form>

<div class="goals-section">
  <h3>Elérhető célok</h3>
  <div class="goals-grid">
    <?php for ($i = 0; $i < $goals_number; $i++):
        if (substr($goal_name_megjegyzes[$i], 0, 1) === '*') continue;
    ?>
    <form action="goals2.php" method="post" style="margin:0">
      <input type="hidden" name="new" value="<?php echo htmlspecialchars($goal_name_megjegyzes[$i], ENT_QUOTES); ?>">
      <?php echo hidden_goals($goals); ?>
      <button type="submit" class="goal-add-btn"><?php echo htmlspecialchars($goal_name_megjegyzes[$i]); ?></button>
    </form>
    <?php endfor; ?>
  </div>
</div>

</div>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>

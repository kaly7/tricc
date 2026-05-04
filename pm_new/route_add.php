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

// Route state: existing goals + newly added goal merged
$goals = isset($_POST['mytext2']) ? $_POST['mytext2'] : [];
if (isset($_POST["new"]) && $_POST["new"] !== '') {
    $goals[] = $_POST["new"];
}
if (isset($_POST["delete"])) {
    array_splice($goals, (int)$_POST["delete"], 1);
}

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

.save-bar {
    display: flex;
    align-items: center;
    gap: 14px;
    background: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 12px 18px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.save-bar label { font-size: 13px; font-weight: 600; color: #444; }
.save-bar input[type=text] { flex: 1; min-width: 160px; }

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

<h2 class="page-title">Útvonalak felvitele</h2>

<div class="route-section">
  <h3>Jelenlegi útvonal</h3>
  <div class="route-buttons">
    <?php if (empty($goals)): ?>
      <span class="route-empty">Nincs kiválasztott célpont – kattints az elérhető célok egyikére.</span>
    <?php else: ?>
      <?php foreach ($goals as $j => $val): ?>
      <form action="route_add.php" method="post" style="margin:0">
        <input type="hidden" name="delete" value="<?php echo $j; ?>">
        <?php echo hidden_goals($goals); ?>
        <button type="submit" class="route-btn"><?php echo htmlspecialchars($val); ?> &nbsp;✕</button>
      </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<form action="route_add_save.php" method="post">
  <div class="save-bar">
    <label for="route_name">Megnevezés:</label>
    <input type="text" id="route_name" name="route_name" placeholder="Útvonal neve..." required>
    <button type="submit" class="button_mentes">Mentés</button>
    <?php echo hidden_goals($goals); ?>
  </div>
</form>

<div class="goals-section">
  <h3>Elérhető célok</h3>
  <div class="goals-grid">
    <?php for ($i = 0; $i < $goals_number; $i++):
        if (substr($goal_name_megjegyzes[$i], 0, 1) === '*') continue;
    ?>
    <form action="route_add.php" method="post" style="margin:0">
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

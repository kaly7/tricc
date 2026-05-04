<?php
$servername = "localhost"; $username = "robot"; $password = "abrakadabra"; $dbname = "Robot";
session_start();

if (isset($_POST["login_name"])) {
    $login_name   = $_POST["login_name"];
    $login_passwd = $_POST["login_passwd"];
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
    $result = $conn->query("SELECT * FROM Felhasznalok WHERE nev=\"$login_name\" AND jelszo=\"$login_passwd\"");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_SESSION["loggedin"]  = true;
        $_SESSION["username"]  = $row["nev"];
        $_SESSION["admin"]     = $row["admin"];
        $_SESSION["logintime"] = time();
        $_SESSION["user_id"]   = $row["Index_"];
        $_SESSION["jogok"]     = $row["jogok"];
    } else {
        header("location: login.php?x=1"); exit;
    }
    $conn->close();
} else {
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        header("location: login.php"); exit;
    }
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
.live-panel {
    margin-top: 18px;
    border-top: 1px solid #e0e0e0;
    padding-top: 16px;
}
.live-panel-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    font-size: 11px;
    font-weight: 700;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.live-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: #2e7d32;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.3; }
}
#jobs-panel { margin-top: 12px; }
.job-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    margin-bottom: 8px;
    padding: 8px 10px;
    background: #f8f8f8;
    border-radius: 8px;
    border-left: 3px solid #EE3124;
}
.job-goal-pill {
    background: #007BC2;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 20px;
    white-space: nowrap;
}
.no-jobs { color: #bbb; font-size: 13px; font-style: italic; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text" style="text-align:center;">

<div style="display:flex; flex-direction:column; gap:10px; align-items:center; margin-bottom:16px;">
  <a href="goals2.php"   class="mybutton_vh"  style="width:280px;">Küldetés tervezés</a>
  <a href="pont_pont.php" class="mybutton_vh" style="width:280px;">Pont-pont útvonal</a>
  <a href="robot_ide.php" class="mybutton_vh" style="width:280px;">Robot ide / Vissza</a>
</div>

<?php if ($_SESSION["jogok"] == "on"): ?>
<hr style="margin:8px 0 14px;">
<div style="display:flex; flex-direction:column; gap:10px; align-items:center; margin-bottom:16px;">
  <a href="admin_user_goal.php" class="mybutton_vh2" style="width:280px;">Fix célpontok felvitele</a>
  <a href="route_add.php"       class="mybutton_vh2" style="width:280px;">Útvonalak felvitele</a>
  <a href="schedule_add.php"    class="mybutton_vh2" style="width:280px;">Útvonalak időzítése</a>
</div>
<?php endif; ?>

<?php if ($_SESSION["admin"] == "on"): ?>
<hr style="margin:8px 0 14px;">
<div style="display:flex; flex-direction:column; gap:10px; align-items:center; margin-bottom:16px;">
  <a href="admin_user.php"         class="mybutton_vh" style="width:280px;">Felhasználók</a>
  <a href="admin_goal.php"         class="mybutton_vh" style="width:280px;">Célpontok</a>
  <a href="admin_kozbenso_goal.php" class="mybutton_vh" style="width:280px;">Pont-pont beállítások</a>
  <a href="admin_munkaallomas.php" class="mybutton_vh" style="width:280px;">Munkaállomások (Robot ide)</a>
  <a href="time.php"               class="mybutton_vh" style="width:280px;">Szerver dátum / idő beállítás</a>
  <a href="napok.php"              class="mybutton_vh" style="width:280px;">Munkanap / Ünnepnap beállítás</a>
  <a href="admin_migrate.php"      class="mybutton_vh2" style="width:280px;">Adatbázis migráció</a>
</div>
<?php endif; ?>

<hr style="margin:8px 0 14px;">

<div class="live-panel" style="text-align:left;">
  <div class="live-panel-header">
    <span class="live-dot"></span>
    <span>Robot státusz &amp; aktív jobok</span>
  </div>
  <div id="status-panel"><em style="color:#bbb;font-size:13px;">Betöltés...</em></div>
  <div id="jobs-panel"></div>
</div>

<div style="margin-top:16px; text-align:center;">
  <a href="logout.php" class="mybutton_vh">Kilépés</a>
</div>

</div>

<script>
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderStatus(data) {
    // Robots tábla
    var rHtml = '<table class="blueTable" style="width:100%;margin-bottom:12px;"><thead><tr><th>Robot</th><th>Státusz</th></tr></thead><tbody>';
    data.robots.forEach(function(r) {
        rHtml += '<tr><td>' + esc(r.name) + '</td><td>' + esc(r.status) + '</td></tr>';
    });
    rHtml += '</tbody></table>';
    document.getElementById('status-panel').innerHTML = rHtml;

    // Aktív jobok
    var jDiv = document.getElementById('jobs-panel');
    if (data.jobs.length === 0) {
        jDiv.innerHTML = '<p class="no-jobs">Nincs aktív job.</p>';
        return;
    }
    var jHtml = '<div style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Aktív jobok</div>';
    data.jobs.forEach(function(job) {
        jHtml += '<div class="job-row">'
            + '<button class="button_delete" style="font-size:12px;padding:4px 10px;" onclick="location.href=\'job_del.php?id=' + esc(job.id) + '\'">'
            + esc(job.id) + ' &ndash; Törlés</button>';
        job.goals.forEach(function(g) {
            jHtml += '<span class="job-goal-pill">' + esc(g) + '</span>';
        });
        jHtml += '</div>';
    });
    jDiv.innerHTML = jHtml;
}

function poll() {
    fetch('status_api.php')
        .then(function(r) { return r.json(); })
        .then(renderStatus)
        .catch(function() {});
}

poll();
setInterval(poll, 5000);
</script>

<?php include __DIR__ . '/footer_inc.php'; ?>
</body>
</html>

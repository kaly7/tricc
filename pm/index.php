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
#jobs-panel { margin-top: 12px; }
.menu-section {
    display: flex;
    flex-direction: row;
    width: 420px;
    margin: 0 auto 12px;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #ddd;
    background: rgba(255,255,255,0.6);
}
.menu-side {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    min-width: 36px;
    writing-mode: vertical-rl;
    text-orientation: mixed;
    transform: rotate(180deg);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .15em;
    color: #fff;
    padding: 16px 0;
    flex-shrink: 0;
}
.menu-side-red  { background: #EE3124; }
.menu-side-blue { background: #007BC2; }
.menu-side-dark { background: #37474F; }
.menu-buttons {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px 12px;
}
#status-panel table.blueTable,
#status-panel table.blueTable td,
#status-panel table.blueTable th {
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
}
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text" style="text-align:center;">

<div class="menu-section">
  <div class="menu-side menu-side-red">Robot vezérlés</div>
  <div class="menu-buttons">
    <a href="goals2.php"    class="mybutton_vh">Küldetés tervezés</a>
    <a href="pont_pont.php" class="mybutton_vh">Pont-pont útvonal</a>
    <a href="robot_ide.php" class="mybutton_vh">Robot hívás</a>
  </div>
</div>

<?php if ($_SESSION["jogok"] == "on"): ?>
<div class="menu-section">
  <div class="menu-side menu-side-blue">Útvonalak</div>
  <div class="menu-buttons">
    <a href="admin_user_goal.php" class="mybutton_vh2">Fix célpontok felvitele</a>
    <a href="route_add.php"       class="mybutton_vh2">Útvonalak felvitele</a>
    <a href="schedule_add.php"    class="mybutton_vh2">Útvonalak időzítése</a>
  </div>
</div>
<?php endif; ?>

<?php if ($_SESSION["admin"] == "on"): ?>
<div class="menu-section">
  <div class="menu-side menu-side-dark">Setup</div>
  <div class="menu-buttons">
    <a href="admin_user.php"          class="mybutton_vh">Felhasználók</a>
    <a href="admin_goal.php"          class="mybutton_vh">Célpontok</a>
    <a href="admin_kozbenso_goal.php" class="mybutton_vh">Pont-pont beállítások</a>
    <a href="admin_munkaallomas.php"  class="mybutton_vh">Munkaállomások (Robot hívás)</a>
    <a href="time.php"                class="mybutton_vh">Szerver dátum / idő beállítás</a>
    <a href="napok.php"               class="mybutton_vh">Munkanap / Ünnepnap beállítás</a>
    <a href="admin_migrate.php"       class="mybutton_vh2">Adatbázis migráció</a>
    <a href="admin_update.php"        class="mybutton_vh2">Rendszer frissítés (ZIP)</a>
  </div>
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

// Menü gombok: href helyett data-href, hogy a státuszsáv ne mutassa az URL-t
document.querySelectorAll('.menu-buttons a, .menu-section a').forEach(function(a) {
    a.setAttribute('data-href', a.getAttribute('href'));
    a.removeAttribute('href');
    a.style.cursor = 'pointer';
    a.addEventListener('click', function() { window.location = this.dataset.href; });
});
</script>

<?php include __DIR__ . '/footer_inc.php'; ?>
</body>
</html>

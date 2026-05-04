<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$client_ip = $_SERVER['REMOTE_ADDR'];
$ip_safe   = $conn->real_escape_string($client_ip);

$res = $conn->query(
    "SELECT m.*, gc.Goal_name as cel_goal_name, gc.Megjegyzes as cel_megjegyzes,
            gk.Goal_name as kozbenso_goal_name, gk.Megjegyzes as kozbenso_megjegyzes
     FROM munkaallomas m
     JOIN Goals gc ON m.cel_goal_index = gc.Index_
     LEFT JOIN Goals gk ON m.kozbenso_goal_index = gk.Index_
     WHERE m.ip = '$ip_safe' LIMIT 1"
);
$allomas = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;

// Ha robot úton van, ellenőrizzük a job státuszát.
// Addig "úton", amíg legalább egy aktív (nem deleted) sor létezik a job_id-hoz.
$robot_vegzett = false;
if ($allomas && $allomas['allapot'] === 'uton' && !empty($allomas['aktiv_job_id'])) {
    $jid  = $conn->real_escape_string($allomas['aktiv_job_id']);
    $jres = $conn->query(
        "SELECT COUNT(*) as cnt FROM Button_Goals WHERE Megjegyzes='$jid' AND akcio != 'deleted'"
    );
    $jrow = $jres ? $jres->fetch_assoc() : null;
    if (!$jrow || (int)$jrow['cnt'] === 0) {
        $robot_vegzett = true;
    }
    if ($robot_vegzett) {
        $conn->query("UPDATE munkaallomas SET allapot='szabad', aktiv_job_id=NULL WHERE ip='$ip_safe'");
        $allomas['allapot']       = 'szabad';
        $allomas['aktiv_job_id']  = null;
    }
}

$job_lathatosag_ri = $allomas ? $allomas['job_lathatosag'] : 'semmi';
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot ide</title>
<?php if ($allomas && $allomas['allapot'] === 'uton'): ?>
<meta http-equiv="Refresh" content="5">
<?php endif; ?>
<style>
.nagy_gomb {
    font-size: 36px;
    padding: 40px 100px;
    border-radius: 14px;
    border: 3px solid #000;
    cursor: pointer;
    font-weight: bold;
    margin-top: 50px;
    display: block;
    width: 80%;
    margin-left: auto;
    margin-right: auto;
}
.gomb_ide    { background-color: #2e7d32; color: #fff; }
.gomb_ide:hover    { background-color: #1b5e20; }
.gomb_uton   { background-color: #f57f17; color: #fff; cursor: default; opacity: 0.85; }
.allapot_info { color: #ccc; font-size: 15px; margin-top: 15px; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center><br>
<hr>

<?php if (!$allomas): ?>
  <h2 class="page-title" style="color:#c62828;">Ismeretlen munkaállomás</h2>
  <p style="color:#666;">Ez az IP cím (<?php echo htmlspecialchars($client_ip); ?>) nincs konfigurálva.</p>
  <?php if (isset($_SESSION["admin"]) && $_SESSION["admin"] == "on"): ?>
  <a href="admin_munkaallomas.php" class="button_mentes">Munkaállomás beállítás</a>
  <?php endif; ?>
<?php else: ?>
  <h2 class="page-title"><?php echo htmlspecialchars($allomas['nev']); ?></h2>

  <?php if ($allomas['allapot'] === 'uton'): ?>
    <button type="button" class="nagy_gomb gomb_uton" disabled>Robot úton...</button>
    <p class="allapot_info">Cél: <?php echo htmlspecialchars($allomas['cel_megjegyzes'] ?: $allomas['cel_goal_name']); ?></p>
    <?php if ($allomas['kozbenso_goal_index'] > 0): ?>
    <p class="allapot_info" style="font-size:12px;">Közbenső: <?php echo htmlspecialchars($allomas['kozbenso_megjegyzes'] ?: $allomas['kozbenso_goal_name']); ?></p>
    <?php endif; ?>
    <p class="allapot_info" style="font-size:13px;">Az oldal automatikusan frissül, amint a robot megérkezett.</p>
  <?php else: ?>
    <form action="robot_ide_go.php" method="POST">
      <input type="hidden" name="allomas_id" value="<?php echo (int)$allomas['id']; ?>">
      <button type="submit" class="nagy_gomb gomb_ide">Robot ide</button>
      <p class="allapot_info">Cél: <?php echo htmlspecialchars($allomas['cel_megjegyzes'] ?: $allomas['cel_goal_name']); ?></p>
      <?php if ($allomas['kozbenso_goal_index'] > 0): ?>
      <p class="allapot_info" style="font-size:12px;">Közbenső: <?php echo htmlspecialchars($allomas['kozbenso_megjegyzes'] ?: $allomas['kozbenso_goal_name']); ?></p>
      <?php endif; ?>
    </form>
  <?php endif; ?>
  <?php if ($job_lathatosag_ri !== 'semmi'): ?>
  <div class="live-panel">
    <div class="live-panel-header"><span class="live-dot"></span><span>Aktív jobok</span></div>
    <div id="jobs-panel"><em class="no-jobs">Betöltés...</em></div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</center>
</div>
<?php if ($job_lathatosag_ri !== 'semmi'): ?>
<script>
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function renderJobs(jobs){
    var p=document.getElementById('jobs-panel');
    if(!jobs||jobs.length===0){p.innerHTML='<p class="no-jobs">Nincs aktív job.</p>';return;}
    var h='';
    jobs.forEach(function(j){
        h+='<div class="job-row">'
          +'<button class="button_delete" style="font-size:12px;padding:4px 10px;" onclick="location.href=\'job_del.php?id='+esc(j.id)+'\'">'+esc(j.id)+' &ndash; Törlés</button>';
        j.goals.forEach(function(g){h+='<span class="job-goal-pill">'+esc(g)+'</span>';});
        h+='</div>';
    });
    p.innerHTML=h;
}
function pollJobs(){
    fetch('jobok_api.php?tipus=RI&lathatosag=<?php echo urlencode($job_lathatosag_ri); ?>')
        .then(function(r){return r.json();})
        .then(function(d){renderJobs(d.jobs);})
        .catch(function(){});
}
pollJobs();
setInterval(pollJobs,5000);
</script>
<?php endif; ?>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>

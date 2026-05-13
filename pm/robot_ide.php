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

$res = $conn->query("SELECT * FROM munkaallomas WHERE ip = '$ip_safe' LIMIT 1");
$allomas = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;

// Robot végzett ellenőrzés
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
        $allomas['allapot']      = 'szabad';
        $allomas['aktiv_job_id'] = null;
    }
}

// Útvonal pontok
$route_labels = [];
if ($allomas) {
    $rres = $conn->query(
        "SELECT g.Megjegyzes, g.Goal_name
         FROM munkaallomas_utvonal u
         JOIN Goals g ON u.goal_index = g.Index_
         WHERE u.allomas_id = " . (int)$allomas['id'] . "
         ORDER BY u.sorrend"
    );
    if ($rres) {
        while ($rrow = $rres->fetch_assoc()) {
            $route_labels[] = $rrow['Megjegyzes'] ?: $rrow['Goal_name'];
        }
    }
}

$route_str = implode(' → ', array_map('htmlspecialchars', $route_labels));

$job_lathatosag_ri = $allomas ? $allomas['job_lathatosag'] : 'semmi';
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot hívás</title>
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
.allapot_info { color: #666; font-size: 15px; margin-top: 15px; }
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

  <div id="allomas-panel">
  <?php if ($allomas['allapot'] === 'uton'): ?>
    <button type="button" class="nagy_gomb gomb_uton" disabled>Robot úton...</button>
    <?php if ($route_str): ?>
    <p class="allapot_info">Útvonal: <?php echo $route_str; ?></p>
    <?php endif; ?>
  <?php else: ?>
    <form action="robot_ide_go.php" method="POST">
      <input type="hidden" name="allomas_id" value="<?php echo (int)$allomas['id']; ?>">
      <button type="submit" class="nagy_gomb gomb_ide">Robot hívás</button>
      <?php if ($route_str): ?>
      <p class="allapot_info">Útvonal: <?php echo $route_str; ?></p>
      <?php endif; ?>
    </form>
  <?php endif; ?>
  </div>
  <?php if ($job_lathatosag_ri !== 'semmi'): ?>
  <div class="live-panel">
    <div class="live-panel-header"><span class="live-dot"></span><span>Aktív jobok</span></div>
    <div id="jobs-panel"><em class="no-jobs">Betöltés...</em></div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</center>
</div>
<script>
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

function renderAllomas(data){
    if(!data||!data.found) return;
    var panel=document.getElementById('allomas-panel');
    var route='';
    if(data.route_labels&&data.route_labels.length>0){
        route='<p class="allapot_info">Útvonal: '+data.route_labels.map(esc).join(' → ')+'</p>';
    }
    var h='';
    if(data.allapot==='uton'){
        h='<button type="button" class="nagy_gomb gomb_uton" disabled>Robot úton...</button>'+route;
    } else {
        h='<form action="robot_ide_go.php" method="POST">'
         +'<input type="hidden" name="allomas_id" value="'+esc(data.id)+'">'
         +'<button type="submit" class="nagy_gomb gomb_ide">Robot hívás</button>'
         +route
         +'</form>';
    }
    panel.innerHTML=h;
}
function pollAllomas(){
    fetch('allomas_api.php')
        .then(function(r){return r.json();})
        .then(renderAllomas)
        .catch(function(){});
}
pollAllomas();
setInterval(pollAllomas,3000);

<?php if ($job_lathatosag_ri !== 'semmi'): ?>
function goalBadge(s){
    if(!s)return'secondary';
    s=s.toLowerCase();
    if(s==='inprogress')return'primary';
    if(s==='completed')return'success';
    if(s==='failed'||s==='interrupted')return'danger';
    if(s==='cancelled')return'warning';
    return'secondary';
}
function renderJobs(jobs){
    var p=document.getElementById('jobs-panel');
    if(!jobs||jobs.length===0){p.innerHTML='<p class="no-jobs">Nincs aktív job.</p>';return;}
    var h='';
    jobs.forEach(function(j){
        h+='<div class="job-row">'
          +'<span style="font-size:11px;color:#888;white-space:nowrap;">'+esc(j.id)+'</span>';
        j.goals.forEach(function(g){
            var name=typeof g==='object'?g.name:g;
            var cls='job-goal-pill badge bg-'+goalBadge(typeof g==='object'?g.status:null);
            h+='<span class="'+cls+'">'+esc(name)+'</span>';
        });
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
<?php endif; ?>
function pollJobStatus(){
    fetch('job_status_poll.php')
        .then(function(r){return r.json();})
        .then(function(d){
            if(d.completed&&d.completed.length>0){
                pollAllomas();
                <?php if ($job_lathatosag_ri !== 'semmi'): ?>
                pollJobs();
                <?php endif; ?>
            }
        })
        .catch(function(){});
}
pollJobStatus();
setInterval(pollJobStatus,8000);
</script>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>

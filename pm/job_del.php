<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php"); exit;
}

$job_id = $_GET['id'] ?? '';
if ($job_id === '') {
    header("location: index.php"); exit;
}

$conn = new mysqli('localhost', 'robot', 'abrakadabra', 'Robot');
if ($conn->connect_error) { die("DB error"); }

$jid_safe = $conn->real_escape_string($job_id);

// Létezik-e, törlhető-e?
$res = $conn->query("SELECT COUNT(*) as cnt FROM Button_Goals WHERE Megjegyzes='$jid_safe' AND akcio != 'deleted'");
$row = $res ? $res->fetch_assoc() : null;
$letezik = ($row && (int)$row['cnt'] > 0);

$sikeres = false;
if ($letezik) {
    // Fleet Manager cancel parancs
    $parancs = "queueCancel jobId $job_id";
    $myfile = fopen("/var/www/html/pm/tmp/newfile.txt", "w");
    if ($myfile) { fwrite($myfile, $parancs); fclose($myfile); }
    exec("/var/www/html/pm/go.pl");

    // DB-ben töröl
    $conn->query("UPDATE Button_Goals SET akcio='deleted' WHERE Megjegyzes='$jid_safe'");
    $sikeres = true;
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($sikeres): ?>
<meta http-equiv="Refresh" content="3; url=index.php">
<?php endif; ?>
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Job törlés – Robot Fleet Manager</title>
<style>
.del-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 32px 36px;
    max-width: 440px;
    margin: 28px auto;
    text-align: center;
}
.del-icon { font-size: 48px; margin-bottom: 14px; }
.del-title {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 10px;
}
.del-title.ok  { color: #2e7d32; }
.del-title.err { color: #c62828; }
.del-jobid {
    display: inline-block;
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 5px 14px;
    font-family: monospace;
    font-size: 13px;
    color: #444;
    margin: 8px 0 18px;
    word-break: break-all;
}
.del-info {
    font-size: 13px;
    color: #999;
    margin-top: 6px;
}
.btn-back {
    display: inline-block;
    margin-top: 18px;
    background: #007BC2;
    color: #fff;
    padding: 10px 28px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 14px;
    text-decoration: none;
}
.btn-back:hover { background: #005f99; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center><br><hr>
<h2 class="page-title">Job törlés</h2>

<div class="del-card">
<?php if ($sikeres): ?>
  <div class="del-icon">&#10003;</div>
  <div class="del-title ok">Job törölve</div>
  <div class="del-jobid"><?php echo htmlspecialchars($job_id); ?></div>
  <div class="del-info">A Fleet Manager értesítve, az oldal visszatér a főmenübe...</div>
  <a href="index.php" class="btn-back">Főmenü</a>
<?php elseif (!$letezik): ?>
  <div class="del-icon">&#8505;</div>
  <div class="del-title err">Job nem található</div>
  <div class="del-jobid"><?php echo htmlspecialchars($job_id); ?></div>
  <div class="del-info">Ez a job már nem aktív, vagy nem létezik.</div>
  <a href="index.php" class="btn-back">Főmenü</a>
<?php else: ?>
  <div class="del-icon">&#10007;</div>
  <div class="del-title err">Törlés sikertelen</div>
  <div class="del-jobid"><?php echo htmlspecialchars($job_id); ?></div>
  <a href="index.php" class="btn-back">Főmenü</a>
<?php endif; ?>
</div>

</center>
</div>
<?php include __DIR__ . '/footer_inc.php'; ?>
</body>
</html>

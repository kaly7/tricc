<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php"); exit;
}
date_default_timezone_set('Europe/Budapest');

$set_result = null;
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $date     = $_POST["date"] ?? '';
    $time_val = $_POST["time_val"] ?? '';
    $datetime = $date . " " . $time_val;
    $command  = "sudo /bin/date -s \"$datetime\"";
    $output   = shell_exec($command);
    $set_result = ($output === null) ? false : $datetime;
}
$currentDateTime = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager</title>
<style>
.info-card {
    background: #f8f8f8;
    border: 1px solid #e0e0e0;
    border-left: 4px solid #EE3124;
    border-radius: 8px;
    padding: 18px 24px;
    max-width: 500px;
    margin: 0 auto 18px;
    text-align: left;
}
.info-card h3 {
    margin: 0 0 12px;
    font-size: 11px;
    font-weight: 700;
    color: #EE3124;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.clock-display {
    font-size: 34px;
    font-weight: 700;
    color: #222;
    letter-spacing: 0.06em;
    text-align: center;
    font-variant-numeric: tabular-nums;
}
.form-row {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.form-row .field { display: flex; flex-direction: column; gap: 5px; }
.form-row label  { font-size: 12px; font-weight: 600; color: #666; }
.set-result {
    margin-top: 14px;
    padding: 10px 14px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    text-align: center;
}
.set-result.ok  { background: #e8f5e9; color: #2e7d32; }
.set-result.err { background: #ffebee; color: #c62828; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text" style="max-width:620px;">

<h2 class="page-title">Szerver dátum / idő beállítás</h2>

<div class="info-card">
  <h3>&#128336; Jelenlegi szerver idő</h3>
  <div class="clock-display" id="current-time"><?php echo $currentDateTime; ?></div>
</div>

<div class="info-card">
  <h3>&#9998; Dátum és idő megadása</h3>
  <form method="post" action="time.php">
    <div class="form-row">
      <div class="field">
        <label for="f_date">Dátum</label>
        <input type="date" id="f_date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
      </div>
      <div class="field">
        <label for="f_time">Idő</label>
        <input type="time" id="f_time" name="time_val" value="<?php echo date('H:i'); ?>" required>
      </div>
      <button type="submit" class="button_mentes" style="padding:8px 24px;">Beállítás</button>
    </div>
  </form>
  <?php if ($set_result === false): ?>
  <div class="set-result err">&#10007; Hiba történt az idő beállítása közben.</div>
  <?php elseif ($set_result !== null): ?>
  <div class="set-result ok">&#10003; Idő sikeresen beállítva: <?php echo htmlspecialchars($set_result); ?></div>
  <?php endif; ?>
</div>

</div>

<script>
(function() {
    var d = new Date('<?php echo $currentDateTime; ?>');
    setInterval(function() {
        d.setSeconds(d.getSeconds() + 1);
        var p = function(n) { return n < 10 ? '0' + n : '' + n; };
        document.getElementById('current-time').textContent =
            d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate()) + ' ' +
            p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }, 1000);
})();
</script>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>

<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["admin"] != "on") {
    header("location: index.php"); exit;
}

$version    = file_exists(__DIR__.'/version.txt')    ? trim(file_get_contents(__DIR__.'/version.txt'))    : 'ismeretlen';
$db_version = file_exists(__DIR__.'/db_version.txt') ? trim(file_get_contents(__DIR__.'/db_version.txt')) : '?';

$log_file = "/var/www/html/pm/tmp/update_log.txt";
$log_lines = [];
if (file_exists($log_file)) {
    $all = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log_lines = array_slice(array_reverse($all), 0, 20);
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Rendszer frissítés</title>
<style>
.update-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 24px 28px;
    max-width: 560px;
    margin: 20px auto;
    text-align: left;
}
.update-card h3 {
    margin: 0 0 16px;
    font-size: 16px;
    color: #444;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    border-bottom: 1px solid #eee;
    padding-bottom: 8px;
}
.ver-row {
    display: flex;
    gap: 32px;
    margin-bottom: 18px;
}
.ver-box {
    flex: 1;
    background: #f5f5f5;
    border-radius: 6px;
    padding: 10px 14px;
}
.ver-box .label { font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: .05em; }
.ver-box .value { font-size: 22px; font-weight: 700; color: #222; margin-top: 2px; }
.ver-box .sub   { font-size: 12px; color: #888; margin-top: 2px; }
.confirm-row {
    margin: 18px 0 8px;
}
.confirm-row label { font-size: 13px; color: #555; display: block; margin-bottom: 6px; }
.confirm-row input[type=text] {
    width: 100%;
    padding: 8px 12px;
    font-size: 15px;
    border: 1px solid #aaa;
    border-radius: 5px;
    background: #fafafa;
    box-sizing: border-box;
    font-family: monospace;
    letter-spacing: .1em;
}
.confirm-row input[type=text]:focus { border-color: #EE3124; outline: none; }
.file-row { margin: 14px 0; }
.file-row label { font-size: 13px; color: #555; display: block; margin-bottom: 6px; }
.btn-update {
    background: #EE3124;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 11px 28px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    width: 100%;
    margin-top: 10px;
}
.btn-update:hover { background: #c0251a; }
.btn-update:disabled { background: #ccc; cursor: not-allowed; }
.info-box {
    background: #e8f5e9;
    border: 1px solid #a5d6a7;
    border-radius: 6px;
    padding: 10px 14px;
    font-size: 13px;
    color: #2e7d32;
    margin-bottom: 14px;
}
.log-box {
    background: #1a1a1a;
    color: #ccc;
    font-family: monospace;
    font-size: 12px;
    border-radius: 6px;
    padding: 12px 14px;
    max-height: 200px;
    overflow-y: auto;
    line-height: 1.7;
}
.log-box .log-ok      { color: #81c784; }
.log-box .log-migrate { color: #ffb74d; }
.log-box .log-err     { color: #ef9a9a; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center>
<br><hr>
<h2 class="page-title">Rendszer frissítés</h2>

<div class="update-card">
  <h3>Jelenlegi verzió</h3>
  <div class="ver-row">
    <div class="ver-box">
      <div class="label">Alkalmazás</div>
      <div class="value">v<?php echo htmlspecialchars($version); ?></div>
    </div>
    <div class="ver-box">
      <div class="label">DB séma</div>
      <div class="value"><?php echo htmlspecialchars($db_version); ?></div>
      <div class="sub">adatbázis verzió</div>
    </div>
  </div>

  <?php if (!empty($_GET['hiba'])): ?>
  <div style="background:#fdecea;border:1px solid #ef9a9a;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#c62828;">
    <strong>Hiba:</strong> <?php echo htmlspecialchars($_GET['hiba']); ?>
  </div>
  <?php endif; ?>

  <div class="info-box">
    A ZIP fájl a <strong>pm_new/</strong> mappa tartalmát csomagolja, fájlok közvetlenül (alkönyvtár nélkül).<br>
    A <code>version.txt</code> és <code>db_version.txt</code> fájlok legyenek benne.
  </div>

  <form action="admin_update_go.php" method="POST" enctype="multipart/form-data" id="upd-form">
    <div class="file-row">
      <label>Frissítő ZIP fájl:</label>
      <input type="file" name="zip_file" accept=".zip" required style="font-size:14px;">
    </div>
    <div class="confirm-row">
      <label>Megerősítés – írd be pontosan: <strong>FRISSITES</strong></label>
      <input type="text" name="megerosites" id="megerosites" autocomplete="off"
             placeholder="FRISSITES" oninput="checkForm()">
    </div>
    <button type="submit" class="btn-update" id="upd-btn" disabled>
      Frissítés indítása
    </button>
  </form>
</div>

<?php if ($log_lines): ?>
<div class="update-card" style="margin-top:10px;">
  <h3>Frissítési napló (utolsó 20 bejegyzés)</h3>
  <div class="log-box">
    <?php foreach ($log_lines as $line): ?>
      <?php
        $cls = 'log-ok';
        if (strpos($line, 'HIBA') !== false)    $cls = 'log-err';
        elseif (strpos($line, 'MIGRÁC') !== false) $cls = 'log-migrate';
      ?>
      <div class="<?php echo $cls; ?>"><?php echo htmlspecialchars($line); ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</center>
</div>
<script>
function checkForm() {
    var val = document.getElementById('megerosites').value;
    document.getElementById('upd-btn').disabled = (val !== 'FRISSITES');
}
</script>
<?php include __DIR__ . '/footer_inc.php'; ?>
</body>
</html>

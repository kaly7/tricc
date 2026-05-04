<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["admin"] != "on") {
    header("location: index.php"); exit;
}

$v_old     = htmlspecialchars($_GET['v_old']     ?? '?');
$v_new     = htmlspecialchars($_GET['v_new']     ?? '?');
$files     = (int)($_GET['files']     ?? 0);
$migration = (int)($_GET['migration'] ?? 0);
$db_old    = htmlspecialchars($_GET['db_old']    ?? '?');
$db_new    = htmlspecialchars($_GET['db_new']    ?? '?');
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Frissítés kész</title>
<style>
.result-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 28px 32px;
    max-width: 480px;
    margin: 24px auto;
    text-align: center;
}
.result-icon { font-size: 52px; margin-bottom: 12px; }
.result-title { font-size: 20px; font-weight: 700; color: #2e7d32; margin-bottom: 18px; }
.result-row {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    padding: 7px 0;
    border-bottom: 1px solid #f0f0f0;
    color: #444;
}
.result-row:last-of-type { border-bottom: none; }
.result-row .lbl { color: #888; }
.migration-warn {
    background: #fff3e0;
    border: 2px solid #ff9800;
    border-radius: 8px;
    padding: 16px 18px;
    margin-top: 20px;
    text-align: left;
}
.migration-warn .warn-title {
    font-weight: 700;
    color: #e65100;
    font-size: 15px;
    margin-bottom: 6px;
}
.migration-warn p { font-size: 13px; color: #555; margin: 4px 0; }
.btn-back {
    display: inline-block;
    margin-top: 22px;
    background: #007BC2;
    color: #fff;
    padding: 10px 28px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 14px;
    text-decoration: none;
}
.btn-back:hover { background: #005f99; }
.btn-migrate {
    display: inline-block;
    margin-top: 22px;
    background: #e65100;
    color: #fff;
    padding: 10px 28px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 14px;
    text-decoration: none;
    margin-left: 10px;
}
.btn-migrate:hover { background: #bf360c; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center>
<br><hr>
<h2 class="page-title">Rendszer frissítés</h2>

<div class="result-card">
  <div class="result-icon">&#10003;</div>
  <div class="result-title">Frissítés sikeres!</div>

  <div class="result-row">
    <span class="lbl">Verzió</span>
    <span>v<?php echo $v_old; ?> &rarr; <strong>v<?php echo $v_new; ?></strong></span>
  </div>
  <div class="result-row">
    <span class="lbl">Másolt fájlok</span>
    <span><?php echo $files; ?> db</span>
  </div>
  <div class="result-row">
    <span class="lbl">DB séma</span>
    <span>
      <?php if ($migration): ?>
        <?php echo $db_old; ?> &rarr; <strong style="color:#e65100;"><?php echo $db_new; ?></strong>
      <?php else: ?>
        <?php echo $db_old; ?> (változatlan)
      <?php endif; ?>
    </span>
  </div>

  <?php if ($migration): ?>
  <div class="migration-warn">
    <div class="warn-title">&#9888; Adatbázis migráció szükséges!</div>
    <p>A frissítés változtatott az adatbázis sémán (<?php echo $db_old; ?> &rarr; <?php echo $db_new; ?>).</p>
    <p>Futtasd le az <strong>Adatbázis migráció</strong> menüpontot a folytatás előtt.</p>
  </div>
  <?php endif; ?>

  <div>
    <a href="index.php" class="btn-back">Főmenü</a>
    <?php if ($migration): ?>
    <a href="admin_migrate.php" class="btn-migrate">Migráció futtatása</a>
    <?php endif; ?>
  </div>
</div>

</center>
</div>
<?php include __DIR__ . '/footer_inc.php'; ?>
</body>
</html>

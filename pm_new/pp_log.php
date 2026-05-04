<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$logfile = "/var/www/html/pm/tmp/pp_log.txt";
$sorok = [];
if (file_exists($logfile)) {
    $sorok = array_reverse(array_filter(explode("\n", file_get_contents($logfile))));
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Pont-pont napló</title>
<style>
.log-tabla { width:100%; border-collapse:collapse; font-size:13px; }
.log-tabla th { background:#1a3a5c; color:#fff; padding:7px 10px; text-align:left; }
.log-tabla td { padding:6px 10px; border-bottom:1px solid #334; color:#ddd; vertical-align:top; }
.log-tabla tr:nth-child(even) td { background:rgba(255,255,255,0.04); }
.parancs { color:#ffdd88; font-family:monospace; word-break:break-all; }
.badge-azonnali { background:#1b5e20; color:#fff; padding:2px 7px; border-radius:4px; font-size:11px; }
.badge-idozitett { background:#4a148c; color:#fff; padding:2px 7px; border-radius:4px; font-size:11px; }
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-image"></div>
<div class="bg-text">
Felhasználó: <?php echo htmlspecialchars($_SESSION["username"]); ?>
<center><br>

<h2 class="page-title">Pont-pont parancsnapló</h2>
</center>

<?php if (empty($sorok)): ?>
<p style="color:#aaa; text-align:center;">Még nincs napló bejegyzés.</p>
<?php else: ?>
<table class="log-tabla">
<thead><tr><th>Időpont</th><th>Típus</th><th>Felhasználó</th><th>Parancs / részlet</th></tr></thead>
<tbody>
<?php foreach ($sorok as $sor):
    if (trim($sor) === '') continue;
    // Formátum: [2026-04-27 13:15:00] AZONNALI | user | parancs
    if (!preg_match('/^\[(.+?)\]\s+(AZONNALI|IDOZITETT)\s*\|\s*(.+?)\s*\|\s*(.+)$/', $sor, $m)) continue;
    $ts   = $m[1];
    $tipus = $m[2];
    $user  = $m[3];
    $reszlet = $m[4];
    $badge = $tipus === 'AZONNALI'
        ? '<span class="badge-azonnali">Azonnali</span>'
        : '<span class="badge-idozitett">Időzített</span>';
?>
<tr>
  <td style="white-space:nowrap;"><?php echo htmlspecialchars($ts); ?></td>
  <td><?php echo $badge; ?></td>
  <td><?php echo htmlspecialchars($user); ?></td>
  <td class="parancs"><?php echo htmlspecialchars($reszlet); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

</div>
</body>
</html>

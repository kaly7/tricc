<?php
$servername = "localhost";
$username_db = "robot";
$password_db = "abrakadabra";
$dbname = "Robot";

session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["admin"] != "on") {
    header("location: index.php");
    exit;
}

$sql_file = __DIR__ . '/migrate_robot_db.sql';

function parse_sql_statements($file) {
    $content = file_get_contents($file);
    // Megjegyzések eltávolítása (-- és /* */ típusú)
    $content = preg_replace('/\/\*.*?\*\//s', '', $content);
    $lines = explode("\n", $content);
    $statements = [];
    $current = '';
    foreach ($lines as $line) {
        $line = rtrim($line);
        // Sor eleji -- megjegyzés kihagyása
        if (preg_match('/^\s*--/', $line)) continue;
        $current .= ' ' . $line;
        if (substr(rtrim($current), -1) === ';') {
            $stmt = trim($current);
            if ($stmt !== ';' && strlen($stmt) > 1) {
                $statements[] = $stmt;
            }
            $current = '';
        }
    }
    return $statements;
}

$results = [];
$ran = false;

if (isset($_POST["run_migration"])) {
    $ran = true;
    $conn = new mysqli($servername, $username_db, $password_db, $dbname);
    if ($conn->connect_error) {
        die("DB kapcsolat sikertelen: " . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');

    $statements = parse_sql_statements($sql_file);
    foreach ($statements as $stmt) {
        // SET utasítások és UPDATE-ek sima futtatása
        $ok = $conn->query($stmt);
        $short = strlen($stmt) > 120 ? substr($stmt, 0, 117) . '...' : $stmt;
        $results[] = [
            'sql'     => $short,
            'ok'      => ($ok !== false),
            'error'   => $ok === false ? $conn->error : '',
            'affected'=> ($ok !== false && $conn->affected_rows >= 0) ? $conn->affected_rows : null,
        ];
    }
    $conn->close();
}

$statements_preview = parse_sql_statements($sql_file);
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Adatbázis migráció</title>
<style>
.result-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:16px; }
.result-table th { background:#1C6EA4; color:#fff; padding:6px 8px; text-align:left; }
.result-table td { padding:5px 8px; border-bottom:1px solid #444; vertical-align:top; }
.result-table tr.ok   td { background:#1a3a1a; }
.result-table tr.err  td { background:#3a1a1a; }
.badge-ok  { color:#7fff7f; font-weight:bold; }
.badge-err { color:#ff7f7f; font-weight:bold; }
.sql-cell  { font-family:monospace; color:#ddd; word-break:break-all; }
.err-cell  { color:#ffaaaa; font-family:monospace; }
.preview-list { text-align:left; font-family:monospace; font-size:11px; color:#bbb;
                background:#111; padding:10px; border-radius:6px; max-height:200px;
                overflow-y:auto; margin:10px 0 20px; }
.preview-list div { padding:2px 0; border-bottom:1px solid #222; }
.summary-ok  { color:#7fff7f; font-size:16px; }
.summary-err { color:#ff7f7f; font-size:16px; }
</style>
</head>
<body>
<div class="bg-image"></div>
<div class="bg-text">
Felhasználó: <?php echo htmlspecialchars($_SESSION["username"]); ?>
<center><br>
<a href="index.php" class="button_x">Főmenü</a><br><br><hr>
<h2 style="color:#fff;">Adatbázis migráció</h2>
<p style="color:#ccc;">Futtatja a <code>migrate_robot_db.sql</code> tartalmát.<br>
Biztonságos: csak <code>CREATE IF NOT EXISTS</code> és <code>ADD COLUMN IF NOT EXISTS</code> utasítások – meglévő adatokat nem töröl.</p>

<?php if (!$ran): ?>

<div style="color:#aaa; font-size:13px; margin-bottom:6px;">Végrehajtandó SQL utasítások (<?php echo count($statements_preview); ?> db):</div>
<div class="preview-list">
  <?php foreach ($statements_preview as $s): ?>
  <div><?php echo htmlspecialchars(strlen($s) > 100 ? substr($s, 0, 97).'...' : $s); ?></div>
  <?php endforeach; ?>
</div>

<form action="admin_migrate.php" method="POST"
      onsubmit="return confirm('Biztosan futtatod a migrációt?')">
  <input type="hidden" name="run_migration" value="1">
  <input type="submit" class="button_mentes" value="Migráció futtatása">
</form>

<?php else: ?>

<?php
$ok_count  = count(array_filter($results, fn($r) => $r['ok']));
$err_count = count($results) - $ok_count;
?>
<p class="<?php echo $err_count === 0 ? 'summary-ok' : 'summary-err'; ?>">
  <?php echo $err_count === 0
      ? "Minden utasítás sikeresen lefutott ($ok_count / $ok_count)"
      : "Hibák: $err_count – Sikeres: $ok_count / " . count($results); ?>
</p>

<table class="result-table">
<thead><tr><th>#</th><th>SQL</th><th>Eredmény</th><th>Hiba</th></tr></thead>
<tbody>
<?php foreach ($results as $i => $r): ?>
<tr class="<?php echo $r['ok'] ? 'ok' : 'err'; ?>">
  <td><?php echo $i + 1; ?></td>
  <td class="sql-cell"><?php echo htmlspecialchars($r['sql']); ?></td>
  <td><?php if ($r['ok']): ?>
    <span class="badge-ok">OK</span>
    <?php if ($r['affected'] !== null): ?>
      <span style="color:#aaa; font-size:11px;">(<?php echo $r['affected']; ?> sor)</span>
    <?php endif; ?>
  <?php else: ?>
    <span class="badge-err">HIBA</span>
  <?php endif; ?></td>
  <td class="err-cell"><?php echo htmlspecialchars($r['error']); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<br>
<a href="admin_migrate.php" class="button_x">Vissza</a>

<?php endif; ?>

</center>
</div>
</body>
</html>

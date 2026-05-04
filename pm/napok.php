<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php"); exit;
}
include 'db.php';

$error = '';
$siker = '';

// Törlés előbb, hogy ne fusson le az insert-ellenőrzés törléskor
if (isset($_POST['delete'])) {
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM nap_tipusok WHERE id = $id");
    $siker = "Bejegyzés törölve.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datum = $_POST['datum'] ?? '';
    $tipus = $_POST['tipus'] ?? '';
    $check = $conn->prepare("SELECT id FROM nap_tipusok WHERE datum = ?");
    $check->bind_param("s", $datum);
    $check->execute();
    $check->get_result()->num_rows > 0
        ? $error = "Ez a dátum már létezik!"
        : (function() use ($conn, $datum, $tipus, &$siker) {
            $stmt = $conn->prepare("INSERT INTO nap_tipusok (datum, tipus) VALUES (?, ?)");
            $stmt->bind_param("ss", $datum, $tipus);
            $stmt->execute();
            $stmt->close();
            $siker = "Dátum sikeresen hozzáadva.";
        })();
    $check->close();
}

$result = $conn->query("SELECT * FROM nap_tipusok ORDER BY datum ASC");

$tipus_stilus = [
    'Munkanap'          => 'background:#e8f5e9; color:#2e7d32;',
    'Ünnepnap'          => 'background:#ffebee; color:#c62828;',
    'Munkaszüneti nap'  => 'background:#fff3e0; color:#e65100;',
];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager</title>
<style>
.add-card {
    background: #f8f8f8;
    border: 1px solid #e0e0e0;
    border-left: 4px solid #EE3124;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 20px;
    text-align: left;
}
.add-card h3 {
    margin: 0 0 12px;
    font-size: 11px;
    font-weight: 700;
    color: #EE3124;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.add-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.tipus-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
</style>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">

<h2 class="page-title">Munkanap / Ünnepnap beállítás</h2>

<div class="add-card">
  <h3>&#43; Új bejegyzés</h3>
  <form method="POST" action="napok.php">
    <div class="add-row">
      <input type="date" name="datum" required>
      <select name="tipus" required>
        <option value="Munkanap">Munkanap</option>
        <option value="Ünnepnap">Ünnepnap</option>
        <option value="Munkaszüneti nap">Munkaszüneti nap</option>
      </select>
      <button type="submit" class="button_mentes" style="padding:8px 22px;">Hozzáadás</button>
    </div>
  </form>
  <?php if ($error): ?>
  <p style="color:#c62828; font-weight:600; margin:10px 0 0;">&#10007; <?php echo htmlspecialchars($error); ?></p>
  <?php endif; ?>
</div>

<?php if ($siker): ?>
<p style="color:#2e7d32; font-weight:600; text-align:center; margin-bottom:14px;">&#10003; <?php echo htmlspecialchars($siker); ?></p>
<?php endif; ?>

<table class="blueTable" style="width:100%; max-width:600px; margin:0 auto;">
  <thead>
    <tr>
      <th>Dátum</th>
      <th>Típus</th>
      <th style="width:80px;">Törlés</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($result->num_rows === 0): ?>
    <tr><td colspan="3" style="text-align:center; color:#aaa; font-style:italic;">Nincs bejegyzés.</td></tr>
    <?php else: ?>
    <?php while ($row = $result->fetch_assoc()):
        $st = $tipus_stilus[$row['tipus']] ?? 'background:#eee; color:#333;';
    ?>
    <tr>
      <td style="font-weight:600;"><?php echo htmlspecialchars($row['datum']); ?></td>
      <td><span class="tipus-badge" style="<?php echo $st; ?>"><?php echo htmlspecialchars($row['tipus']); ?></span></td>
      <td>
        <form method="POST" action="napok.php" style="margin:0;">
          <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
          <button type="submit" name="delete" value="1" class="button_delete">Törlés</button>
        </form>
      </td>
    </tr>
    <?php endwhile; ?>
    <?php endif; ?>
  </tbody>
</table>

</div>
<?php include __DIR__ . "/footer_inc.php"; ?>
</body>
</html>

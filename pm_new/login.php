<?php
$ip = isset($_SERVER['HTTP_CLIENT_IP'])
    ? $_SERVER['HTTP_CLIENT_IP']
    : (isset($_SERVER['HTTP_X_FORWARDED_FOR'])
        ? $_SERVER['HTTP_X_FORWARDED_FOR']
        : $_SERVER['REMOTE_ADDR']);

if (!isset($_GET["z"])) {
    $servername = "localhost"; $username = "robot"; $password = "abrakadabra"; $dbname = "Robot";
    session_start();
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
    $result = $conn->query("SELECT * FROM Felhasznalok WHERE ip = '" . $conn->real_escape_string($ip) . "'");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_SESSION["loggedin"]  = true;
        $_SESSION["username"]  = $row["nev"];
        $_SESSION["admin"]     = $row["admin"];
        $_SESSION["logintime"] = time();
        $_SESSION["user_id"]   = $row["Index_"];
        $_SESSION["jogok"]     = $row["jogok"];
        header("Location: index.php"); exit;
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager – Belépés</title>
</head>
<body class="login-page">

<div class="login-brand">
  <img src="img/honeywell_logo.svg" class="logo-hw" alt="Honeywell">
  <img src="img/omron_logo.svg"     class="logo-om" alt="Omron">
  <h1>Fleet <span>Manager</span></h1>
</div>

<div class="login-card">
  <?php if (isset($_GET["x"]) && $_GET["x"] == 1): ?>
  <p class="login-error">&#10007; Hibás felhasználónév vagy jelszó</p>
  <?php endif; ?>

  <form action="index.php" method="post">
    <input type="hidden" name="login" value="login.php">
    <div class="login-fields">
      <div>
        <label for="login_name">Felhasználói név</label>
        <input type="text" id="login_name" name="login_name" autocomplete="username" autofocus>
      </div>
      <div>
        <label for="login_passwd">Jelszó</label>
        <input type="password" id="login_passwd" name="login_passwd" autocomplete="current-password">
      </div>
    </div>
    <button type="submit" class="button_mentes" style="width:100%;">Belépés</button>
  </form>

  <hr class="login-divider">
  <a href="login.php" class="mybutton_vh" style="width:100%;box-sizing:border-box;text-align:center;display:block;">&#128273; IP alapú belépés</a>
</div>

<?php include __DIR__ . '/footer_inc.php'; ?>
</body>
</html>

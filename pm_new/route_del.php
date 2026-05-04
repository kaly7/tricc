<?php
$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";

    session_start();
    if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
	header("location: login.php");
	exit;
    }

    $felhasznalo = $_SESSION["username"];
    $job_id = date("Y_m_d_H_i_s");
    $job_id = $job_id."_".$felhasznalo;



//exit(0);
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager</title>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center>
<table class="blueTable">
<thead><tr><td align="center">Útvonal törlése...</td></tr></thead>
</table>
<br>
<?php


$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

    $route_Index_ = $_GET["id"];




$sql = "DELETE FROM Route where Index_ = ".$route_Index_;
	$conn = new mysqli($servername, $username, $password, $dbname);
	if ($conn->connect_error) {
	      die("Connection failed: " . $conn->connect_error);
	} 
	if ($conn->query($sql) === TRUE) {
	    //  echo "New record created successfully";
	} else {
	     echo "Error: grrrrr " . $sql . "<br>" . $conn->error;
	}

	$conn->close();

$sql = "DELETE FROM Route_adatok  where Route_index = ".$route_Index_;
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query($sql);
$conn->close();






?>
<a href="schedule_add.php" class="button_x">Vissza</a>
</center>
</div>
</body>
</html>

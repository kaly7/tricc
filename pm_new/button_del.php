<html>
<meta http-equiv="Refresh" content="3; url='index.php'" />
<body>
Button Go
<br>
<?php

$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$button_id = $_POST["id"];
    $sql = "delete FROM Button_Goals where Button_index = '".$button_id."'";
    if ($conn->query($sql) === TRUE) {
	 echo "Törölve.";
    } else {
	 echo "Error deleting record: " . $conn->error;
    }

    $conn->close();

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$button_id = $_POST["id"];
    $sql = "delete FROM Buttons where Index_ = '".$button_id."'";
    if ($conn->query($sql) === TRUE) {
	 echo "";
    } else {
	 echo "Error deleting record: " . $conn->error;
    }

    $conn->close();




?>
</body>
</html>

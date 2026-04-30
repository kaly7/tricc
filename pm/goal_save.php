<html>
#<meta http-equiv="Refresh" content="3; url='index.php'" />
<body>
<?php
$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";


#var_dump( $_POST);

#foreach ($_POST as $key => $value)
#        echo $key.'='.$value.'<br />';

$gomb_name = $_POST['button_name'];

$goals = $_POST['mytext2'];
$prioritas = $_POST['prioritas'];
$akcio = $_POST['akcio'];

#echo "GOMB: " . $gomb_name."<hr>";
#foreach ($goals as &$value) {
#    echo $value."<hr>";
#}

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
} 


$sql = "INSERT INTO Buttons (Button_name) VALUES ('".$gomb_name."')";

if ($conn->query($sql) === TRUE) {
 $last_id = $conn->insert_id;
  echo "New record created successfully -> BUTTONS <br>";
} else {
  echo "Error: " . $sql . "<br>" . $conn->error;
}

$conn->close();
$i=0;
$conn = new mysqli($servername, $username, $password, $dbname);
foreach ($goals as &$value) {
    $sql = "INSERT INTO Button_Goals (Button_index, Goal_name, prioritas, akcio) VALUES ('".$last_id."', '".$value."','".$prioritas[$i]."','".$akcio[$i]."')";
#echo "<br>".$sql;
    $i++;
    if ($conn->query($sql) === TRUE) {
     echo "New record created successfully -> GOALS <br>";
    } else {
	echo "Error: " . $sql . "<br>" . $conn->error;
    }
}
$conn->close();

?>

</body>
</html>


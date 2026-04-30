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
    $sql = "SELECT * FROM Button_Goals where Button_index = '".$button_id."'";

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    } 

    $result = $conn->query($sql);
    $step=1;
    $x = "pickup";
    if ($result->num_rows > 0) {
	while($row = $result->fetch_assoc()) {
	    $step++;
	    if ($step > 1) { $x ="dropoff";}
	    echo $row["Goal_name"]."<br>";
	    $parancs2 = $parancs2 ." ".substr($row["Goal_name"],1,strlen($row["Goal_name"]))." ".$row['akcio']." ".$row['prioritas'];
	    
        }
    } else {
	echo "0 results";
    }
    $conn->close();


#send "queuemulti 4 2 START pickup 10 Goal6 dropoff 20 Goal7 dropoff 20 START dropoff 20 12346\r"

    $parancs = "queuemulti ".($step-1)." 2 ".$parancs2." 123";
    echo $parancs;
    $myfile = fopen("/var/www/html/pm/tmp/newfile.txt", "w") or die("Unable to open file!");
    fwrite($myfile, $parancs);
    fclose($myfile);
    $ret_val = system("/var/www/html/pm/go.pl",$retval);



?>
</body>
</html>


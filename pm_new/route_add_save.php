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
<thead><tr><td align="center">Útvonal mentése...</td></tr></thead>
</table>
<br>
<?php


$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$sql = "select * from Goals";
$result = $conn->query($sql);
$goals_number = 0;
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
    //echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
    $goal_name[$goals_number] = $row["Goal_name"];
    $goal_name_megjegyzes[$goals_number] = $row["Megjegyzes"];
    $goal_Index_[$goals_number] = $row["Index_"];
    $goals_number++;
  }
} else {
  echo "0 results";
}
$conn->close();

$elemszam = 0;
$parancs = "";


    $parancs_drop_or_pick = $funkcio[0];
    $funkcio_all = "pickup"; 
//    if ($funkcio[0] == "Dropoff" ) {
//	$funkcio_all = "pickup";
//    }
//    for ($k=0;$k<$goals_number;$k++) {
//	if ($goal_name_[0] == $goal_Index_[$k]) {
//	    $goal_0 = $goal_name[$k];
//	}
//    }

    $new = $_POST["new"];
    $goals = $_POST['mytext2'];
    $parancs2 = "";
    $route_name = $_POST["route_name"];





$sql = "INSERT INTO Route(Megnevezes) VALUES(\"".$route_name."\")";
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

$sql = "SELECT Index_ from Route ORDER BY Index_ desc limit 1";
$conn = new mysqli($servername, $username, $password, $dbname);
$result = $conn->query($sql);
$route_index = 0;
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
    //echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
	$route_index = $row["Index_"];
  }
} else {
  echo "0 results";
}
$conn->close();




$goals_szamlalo = 0;
if (isset($goals)) {
    foreach($goals as &$value) {

	for ($k=0;$k<$goals_number;$k++) {
	    if ($goal_name_megjegyzes[$k] == $value) {
		$goal_index_save  = $goal_Index_[$k];
		$value_fleet_mgr = $goal_name[$k];
#		print "v_f_mgr:".$value_fleet_ngr."<br>";
	    }
	}

	// egyelore a kozepen nincs pickup / dropoff ... csak pickup
        $parancs = $parancs . $value_fleet_mgr." ". $funkcio_all." 10 ";
	$value1 = $value;
        $j1++;
	$sql = "INSERT INTO Route_adatok(Goal_index,Route_index) values(\"".$goal_index_save."\", \"".$route_index."\")";
	$conn = new mysqli($servername, $username, $password, $dbname);
	if ($conn->connect_error) {
	      die("Connection failed: " . $conn->connect_error);
	} 
	if ($conn->query($sql) === TRUE) {
//	      echo "New record created successfully";
	} else {
	     echo "Error: " . $sql . "<br>" . $conn->error;
	}

	$conn->close();
	$elemszam++;
    }
}





?>
<a href="schedule_add.php" class="button_x">Vissza</a>
</center>
</div>
</body>
</html>

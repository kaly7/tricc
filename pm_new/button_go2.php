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

//print " ELEJE: ";
//print $_POST["eleje_check"];

//print " VEGE: ";
//print $_POST["vege_check"];



$conn =  new mysqli($servername, $username, $password, $dbname);
$sql= "select * from Felhasznalo_goal_eleje where Felhasznalo_index = \"".$_SESSION["user_id"]."\"";
$result = $conn->query($sql);
$number = 0;
//print "3";
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
//    echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_index"]. "<br>";
    $eleje_Goal_index[$number] = $row["Goal_index"];
    $eleje_Akcio[$number] = $row["Akcio"];
    $number++;
  }
} else {
  //echo "0 results";
}
$eleje_number = $number;
//print $eleje_number;
$conn->close();
//print $eleje_number;
$conn =  new mysqli($servername, $username, $password, $dbname);
$sql= "select * from Felhasznalo_goal_vege where Felhasznalo_index = \"".$_SESSION["user_id"]."\"";
$result = $conn->query($sql);
$number = 0;
//print "3";
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
//    echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_index"]. "<br>";
    $vege_Goal_index[$number] = $row["Goal_index"];
    $vege_Akcio[$number] = $row["Akcio"];
    $number++;
  }
} else {
  //echo "0 results";
}
$vege_number = $number;

$conn->close();






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
<thead><tr><td align="center">Parancs küldése...</td></tr></thead>
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


//hozzaadjuk az eleje szekvenciat

$elemszam = 0;
$parancs = "";

if ($_POST["eleje_check"] == "on") {
        for ($i=0;$i<$eleje_number;$i++) {
	    $goal_parancs_name = "";
	    for ($j=0;$j<$goals_number;$j++) {
		if ($goal_Index_[$j] == $eleje_Goal_index[$i]) {
		    $goal_parancs_name = $goal_name[$j];
		    $goal_mm = $goal_name_megjegyzes[$j];
		}
	    }
	    $parancs = $parancs." ".$goal_parancs_name;
	    if ($elemszam == 0) {
		$goal_parancs_akcio = "pickup";
	    } else {
		$goal_parancs_akcio =  $eleje_Akcio[$i];
	    }
	    $parancs = $parancs . " ".$goal_parancs_akcio. " 10 ";
	    $elemszam++;
	    $sql = " INSERT INTO Button_Goals(Goal_name,Megjegyzes,akcio) values(\"".$goal_mm."\" , \"".$job_id."\", \"aktiv\")";
	    $conn = new mysqli($servername, $username, $password, $dbname);
	    if ($conn->connect_error) {
	      die("Connection failed: " . $conn->connect_error);
	    } 
	    if ($conn->query($sql) === TRUE) {
//	      echo "New record created successfully";
	    } else {
	     echo "Error: " . $sql . "<br>" . $conn->error;
	    }



	}
}



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




$goals_szamlalo = 0;
if (isset($goals)) {
    foreach($goals as &$value) {

	for ($k=0;$k<$goals_number;$k++) {
	    if ($goal_name_megjegyzes[$k] == $value) {
		$value_fleet_mgr = $goal_name[$k];
#		print "v_f_mgr:".$value_fleet_ngr."<br>";
	    }
	}

	// egyelore a kozepen nincs pickup / dropoff ... csak pickup
        $parancs = $parancs . $value_fleet_mgr." ". $funkcio_all." 10 ";
	$value1 = $value;
        $j1++;
	$sql = "INSERT INTO Button_Goals(Goal_name,Megjegyzes,akcio) values(\"".$value."\", \"".$job_id."\",\"aktiv\")";
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

// vege szakvencia hozzaadasa
if ($_POST["vege_check"] == "on") {
        for ($i=0;$i<$vege_number;$i++) {
	    $goal_parancs_name = "";
	    for ($j=0;$j<$goals_number;$j++) {
		if ($goal_Index_[$j] == $vege_Goal_index[$i]) {
		    $goal_parancs_name = $goal_name[$j];
		    $goal_mm = $goal_name_megjegyzes[$j];

		}
	    }
	    $parancs = $parancs." ".$goal_parancs_name;
	    if ($elemszam == 0) {
		$goal_parancs_akcio = "pickup";
	    } else {
		$goal_parancs_akcio = $vege_Akcio[$i];
	    }
	    $parancs = $parancs . " ".$goal_parancs_akcio. " 10 ";
	    $elemszam++;
	    $sql = " INSERT INTO Button_Goals(Goal_name,Megjegyzes,akcio) values(\"".$goal_mm."\" , \"".$job_id."\", \"aktiv\")";
	    $conn = new mysqli($servername, $username, $password, $dbname);
	    if ($conn->connect_error) {
	      die("Connection failed: " . $conn->connect_error);
	    } 
	    if ($conn->query($sql) === TRUE) {
//	      echo "New record created successfully";
	    } else {
	     echo "Error: " . $sql . "<br>" . $conn->error;
	    }



	}
}



//echo "<hr>". $comm ."<hr>";

$step = $j1;
// send "queuemulti 4 2 START pickup 10 Goal6 dropoff 20 Goal7 dropoff 20 START dropoff 20 12346\r"
    if ($elemszam >1 ) {
	$parancs = "queuemulti ".($elemszam)." 2 ".$parancs." ".$job_id;
//	print $parancs;
    } else {
	$parancs = "queuepickup \\\"".substr($value_fleet_mgr,1,strlen($value_fleet_mgr))."\\\" 10 ".$job_id;
    }
// echo $parancs."<hr>";
    $myfile = fopen("/var/www/html/pm/tmp/newfile.txt", "w") or die("Unable to open file!");
    fwrite($myfile, $parancs);
    fclose($myfile);
    $ret_val = exec("/var/www/html/pm/go.pl",$retval);



?>
</center>
</div>
</body>
</html>

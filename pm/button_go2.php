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
echo "<html><head>";
echo "<meta http-equiv=\"Refresh\" content=\"3; url=index.php\" >";

?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<body>
<style>
body, html {
  height: 100%;
  margin: 0;
  font-family: Arial, Helvetica, sans-serif;
}

* {
  box-sizing: border-box;
}

.bg-image {
  /* The image used */
  background-image: url("fogaskerek.jpeg");
  
  /* Add the blur effect */
  filter: blur(8px);
  -webkit-filter: blur(8px);
  
  /* Full height */
  height: 100%; 
  
  /* Center and scale the image nicely */
  background-position: center;
  background-repeat: no-repeat;
  background-size: cover;
}

/* Position text in the middle of the page/image */
.bg-text {
  background-color: rgb(0,0,0); /* Fallback color */
  background-color: rgba(0,0,0, 0.4); /* Black w/opacity/see-through */
  color: white;
  font-weight: bold;
  border: 3px solid #f1f1f1;
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 2;
  width: 90%;
  padding: 10px;
  text-align: left;
}



.button {
   border-top: 1px solid #96d1f8;
   background: #bcd665;
   background: -webkit-gradient(linear, left top, left bottom, from(#409c3e), to(#bcd665));
   background: -webkit-linear-gradient(top, #409c3e, #bcd665);
   background: -moz-linear-gradient(top, #409c3e, #bcd665);
   background: -ms-linear-gradient(top, #409c3e, #bcd665);
   background: -o-linear-gradient(top, #409c3e, #bcd665);
   padding: 18px 36px;
   -webkit-border-radius: 8px;
   -moz-border-radius: 8px;
   border-radius: 8px;
   -webkit-box-shadow: rgba(0,0,0,1) 0 1px 0;
   -moz-box-shadow: rgba(0,0,0,1) 0 1px 0;
   box-shadow: rgba(0,0,0,1) 0 1px 0;
   text-shadow: rgba(0,0,0,.4) 0 1px 0;
   color: #000000;
   font-size: 14px;
   font-family: Helvetica, Arial, Sans-Serif;
   text-decoration: none;
   vertical-align: middle;
   }
.button:hover {
   border-top-color: #c94818;
   background: #c94818;
   color: #ccc;
   }
.button:active {
   border-top-color: #1b435e;
   background: #1b435e;
   }


table.blueTable {
  border: 1px solid #1C6EA4;
  background-color: #EEEEEE;
  width: 80%;
  text-align: left;
  border-collapse: collapse;
}
table.blueTable td, table.blueTable th {
  border: 1px solid #AAAAAA;
  padding: 3px 2px;
}
table.blueTable tbody td {
  font-size: 13px;
}
table.blueTable tr:nth-child(even) {
  background: #D0E4F5;
}
table.blueTable thead {
  background: #1C6EA4;
  background: -moz-linear-gradient(top, #5592bb 0%, #327cad 66%, #1C6EA4 100%);
  background: -webkit-linear-gradient(top, #5592bb 0%, #327cad 66%, #1C6EA4 100%);
  background: linear-gradient(to bottom, #5592bb 0%, #327cad 66%, #1C6EA4 100%);
  border-bottom: 2px solid #444444;
}
table.blueTable thead th {
  font-size: 15px;
  font-weight: bold;
  color: #FFFFFF;
  border-left: 2px solid #D0E4F5;
}
table.blueTable thead th:first-child {
  border-left: none;
}

table.blueTable tfoot {
  font-weight: bold;
}


.myButton_vh {
    box-shadow: 0px 1px 0px 0px #f0f7fa;
    background:linear-gradient(to bottom, #33bdef 5%, #019ad2 100%);
    background-color:#33bdef;
    border-radius:6px;
    border:1px solid #057fd0;
    display:inline-block;
    cursor:pointer;
    color:#ffffff;
    font-family:Arial;
    font-size:15px;
    font-weight:bold;
    padding:6px 24px;
    text-decoration:none;
    text-shadow:0px -1px 0px #5b6178;
}
.myButton_vh:hover {
    background:linear-gradient(to bottom, #019ad2 5%, #33bdef 100%);
    background-color:#019ad2;
}
.myButton_vh:active {
    position:relative;
    top:1px;
}



</style>
</head>
<body>

<div class="bg-image"></div>

<div class="bg-text">
<center><br>







<body>
<table class=blueTable>
<thead><tr><td align=center>
Parancs küldése...</td></tr>
</table>
<br>
<script src="jquery.min.js"></script>
<div class="gombok">

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
</div>
</body>
</html>

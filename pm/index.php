<?php
$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";
session_start();
    if ( isset($_POST["login_name"]) ) {
	$login_name=$_POST["login_name"];
	$login_passwd = $_POST["login_passwd"];
	
	$sql="select * from Felhasznalok where nev=\"".$login_name."\" and jelszo=\"".$login_passwd."\"";
	//print $sql;
	$conn =  new mysqli($servername, $username, $password, $dbname);
	// Check connection
	if ($conn->connect_error) {
	      die("Connection failed: " . $conn->connect_error);
	}

	$result = $conn->query($sql);
	$number = 0;
	if ($result->num_rows > 0) {
	      while($row = $result->fetch_assoc()) {
		    $ip[$number] = $row["ip"];
		    $admin[$number] = $row["admin"];
		    $funkcio[$number] = $row["funkcio"];
		    $goal_name[$number] = $row["goal_name"];
		    $jelszo[$number] = $row["jelszo"];
		    $nev[$number] = $row["nev"];
		    $Index[$number] = $row["Index_"];
		    $jogok[$number] = $row["jogok"];
#		    print $ip[$number]."-".$admin[$number]."-".$funkcio[$number]."-".$nev[$number]."-".$jleszo[$number]."-".$Index[0]."<hr>";
		    $number++;
	    }
	    // Van ilyen felhasznalo
	    $_SESSION["loggedin"] = true;
	    $_SESSION["username"] = $nev[0];
	    $_SESSION["admin"] = $admin[0];
	    $_SESSION["logintime"] = time();
	    $_SESSION["user_id"] = $Index[0];
	    $_SESSION["jogok"] = $jogok[0];
	} else {
	     header("location: login.php?x=1");
	}

} else {
    if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
	header("location: login.php");
	exit;
    }

}



?>


<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Refresh" content="10; url='index.php'" >
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
  background-image: url("pictures/16.jpg");
  
  /* Add the blur effect */
  filter: blur(1px);
  -webkit-filter: blur(1px);
  
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
  width: 95%;
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


.myButton_vh2 {
    box-shadow: 0px 1px 0px 0px #f0f7fa;
    background:linear-gradient(to bottom, #33bdef 5%, #019ad2 100%);
    background-color:#ffa500;
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
.myButton_vh2:hover {
    background:linear-gradient(to bottom, #019ad2 5%, #33bdef 100%);
    background-color:#019ad2;
}
.myButton_vh2:active {
    position:relative;
    top:1px;
}



</style>
</head>
<body>

<div class="bg-image"></div>

<div class="bg-text">
Felhasználó: <?php print $_SESSION["username"]; ?>
<center><br>
<!-- a href="button_list.php" class="button" >Gombok listája</a>
<a href="goals.php" class="button" >Gomb létrehozása</a>
<br><br><hr><br -->
<a href="goals2.php" class="mybutton_vh" >Küldetés tervezés</a>
<br><br>
<a href="pont_pont.php" class="mybutton_vh" >Pont-pont útvonal</a>
<br><br>
<a href="robot_ide.php" class="mybutton_vh" >Robot ide / Vissza</a>
<?php 
    if ($_SESSION["jogok"]== "on") {
	print "<br><br><hr><br>";
	print "<a href=\"admin_user_goal.php\" class=\"mybutton_vh2\">Fix Célpontok felvitele</a><br>";
	print "<br><a href=\"route_add.php\" class=\"mybutton_vh2\">Útvonalak felvitele</a><br>";
	print "<br><a href=\"schedule_add.php\" class=\"mybutton_vh2\">Útvonalak időzítése</a><br>";
    }
?>
<br><?php
    if ($_SESSION["admin"]== "on") {
	print "<hr>";
	print "<a href=\"admin_user.php\" class=\"mybutton_vh\">Felhasználók</a><br><br>";
	print "<a href=\"admin_goal.php\" class=\"mybutton_vh\">Célpontok</a><br><br>";
	print "<a href=\"admin_kozbenso_goal.php\" class=\"mybutton_vh\">Pont-pont beállítások</a><br><br>";
	print "<a href=\"admin_munkaallomas.php\" class=\"mybutton_vh\">Munkaállomások (Robot ide)</a><br><br>";
	print "<a href=\"time.php\" class=\"mybutton_vh\">Szerver dátum / idő beállítás</a><br><br>";
	print "<a href=\"napok.php\" class=\"mybutton_vh\">Munkanap/Ünnepnap/Munkaszüneti nap beállítása / idő beállítás</a><br><br>";
    }
    
?>
<hr>


<table class="blueTable">
<thead>
<tr>
<th>&nbsp;</th>
<th>&nbsp;Kiss_Gyuri</th>
<th>&nbsp;Kiss_Marci</th>
</tr>
</thead>
<tbody>
</tr>
<tr>
<td><span style="caret-color: #000000; color: #000000; font-family: -webkit-standard; font-size: medium;">&nbsp;St&aacute;tusz&nbsp;</span></td>
<td>&nbsp; 
<?php 
    $myfile = fopen("/var/www/html/pm/tmp/GYURI", "r") or die("Unable to open file!");
    echo fread($myfile,filesize("/var/www/html/pm/tmp/GYURI"));
    ?>
</td>
<td>&nbsp;
<?php 
    $myfile = fopen("/var/www/html/pm/tmp/MARCI", "r") or die("Unable to open file!");
    echo fread($myfile,filesize("/var/www/html/pm/tmp/MARCI"));
    ?>

</td>
</tr>
</tbody>
</table>
</center>
Aktív jobok:
<?php

$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";


$sql="Select * from Button_Goals where akcio=\"aktiv\" order by Megjegyzes";

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);
// Check connection
if (!$conn) {
  die("Connection failed: " . mysqli_connect_error());
}

$result = mysqli_query($conn, $sql);
$job_id = "---";
if (mysqli_num_rows($result) > 0) {
  // output data of each row
  while($row = mysqli_fetch_assoc($result)) {
	//echo "Goal: " . $row["Goal_name"]. "<br>";
	if ($job_id != $row["Megjegyzes"]) {
	// uj job
	    $job_id = $row["Megjegyzes"];
	    echo "<br><hr>";
	    echo "<input type=button class=mybutton_vh value=\"".$job_id." Törlése\"  onclick=\"location.href='job_del.php?id=".$job_id."'\">" ;
	}
	echo " <input type=button class=mybutton_vh value=\"".$row["Goal_name"]."\">";




  }
} else {
  echo "0 results";
}

mysqli_close($conn);
print "<hr><center><a href=\"logout.php\" class=\"mybutton_vh\">Kilépés</a><br><br>";

?>
</div>
</html>

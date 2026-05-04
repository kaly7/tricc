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


<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Refresh" content="10; url='index.php'" >
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager</title>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
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
	print "<a href=\"admin_migrate.php\" class=\"mybutton_vh2\">Adatbázis migráció</a><br><br>";
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

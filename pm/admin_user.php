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
#		    print $ip[$number]."-".$admin[$number]."-".$funkcio[$number]."-".$nev[$number]."-".$jleszo[$number]."-".$Index[0]."<hr>";
		    $number++;
	    }
	    // Van ilyen felhasznalo
	    $_SESSION["loggedin"] = true;
	    $_SESSION["username"] = $nev[0];
	    $_SESSION["admin"] = $admin[0];
	    $_SESSION["logintime"] = time();
	    $_SESSION["user_id"] = $Index[0];
	    
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
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager</title>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">

<h2 class="page-title">Felhasználók</h2>
<center>

<br><br><br>


<?php






$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";


$new=0;
if ( isset($_GET["delete"])) {
    $sql = "delete from Felhasznalok where Index_ = \"".$_GET["delete"]."\"";
//    print $sql."<hr>";    

	$conn =  new mysqli($servername, $username, $password, $dbname);
	// Check connection
	if ($conn->connect_error) {
	     die("Connection failed: " . $conn->connect_error);
	}

	$result = mysqli_query($conn, $sql);
	$conn->close;
	?>
	<script type = "text/javascript">
            window.setTimeout(function(){
                alert("Törölve!");
            }, 500); 
	</script>
	<?php
}

if ( isset($_POST["user_save"]) ){
    // mentsuk egyet
    $counter = $_POST["counter"];
    if ($counter == -1 ) { 
	$counter = 1;
	$new = 1;
    }
    for ($i=0;$i<$counter;$i++) {
	$index_ = $_POST["index_".$i];
	$nev = $_POST["nev_".$i];
	$jelszo = $_POST["jelszo_".$i];
	$goal_name = $_POST["goal_name_".$i];
	$ip = $_POST["ip_".$i];
	$funkcio = $_POST["funkcio_".$i];
	$admin = $_POST["admin_".$i];
	$jogok = $_POST["jogok_".$i];
	if ($nev =="Admin") { $admin = "on";}
	if ( $new ==  0) {
	    $sql = "update Felhasznalok set admin=\"".$admin."\", funkcio=\"".$funkcio."\", ip=\"".$ip."\", nev=\"".$nev."\", jelszo=\"".$jelszo."\", jogok=\"".$jogok."\" where Index_=".$index_;
	}
	else {
	    $sql = "insert into Felhasznalok(admin,funkcio,ip,nev,jelszo,jogok,goal_name) values(\"".$admin."\",\"".$funkcio."\",\"".$ip."\",\"".$nev."\",\"".$jelszo."\",\"".$jogok."\",0)";
	}

//	print $sql."<hr>";

	$conn =  new mysqli($servername, $username, $password, $dbname);
	// Check connection
	if ($conn->connect_error) {
	      die("Connection failed: " . $conn->connect_error);
	}

    
	$result = mysqli_query($conn, $sql);
	$conn->close();



    }
	?>
	<script type = "text/javascript">
            window.setTimeout(function(){
                alert("Adatok mentve!");
            }, 500); 
	</script>
	<?php
}		


$conn =  new mysqli($servername, $username, $password, $dbname);
// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}


$sql = "SELECT Index_,Goal_name,Active, Megjegyzes FROM Goals";
$result = $conn->query($sql);
$goals_number = 0;
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
    //echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
    $goal_active[$goals_number] = $row["Active"];
    $goal_megjegyzes[$goals_number] = $row["Megjegyzes"];
    $goal_Index_[$goals_number] = $row["Index_"];
//    print $goal_name[$goals_name]."\n";
    $goals_number++;
  }
} else {
  echo "0 results";
}

$conn->close();




$conn =  new mysqli($servername, $username, $password, $dbname);
// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}


$sql = "SELECT * FROM Felhasznalok";
$result = $conn->query($sql);
$number = 0;
unset($ip,$admin,$funkcio,$goal_name,$jelszo,$nev,$Index,$jogok);
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
    //echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
    $ip[$number] = $row["ip"];
    $admin[$number] = $row["admin"];
    $funkcio[$number] = $row["funkcio"];
    $goal_name[$number] = $row["goal_name"];
    $jelszo[$number] = $row["jelszo"];
    $nev[$number] = $row["nev"];
    $Index[$number] = $row["Index_"];
    $jogok[$number] = $row["jogok"];
//	print $ip[$number]."-".$admin[$number]."-".$funkcio[$number]."-".$nev[$number]."-".$jleszo[$number]."<hr>";
    $number++;
  }
} else {
  echo "0 results";
}
$conn->close();


print "<form action=admin_user.php method=POST>";
print "<input type=hidden name=\"user_save\" value=\"save\">";
print ("<table class=\"blueTable\">");
print("<thead><tr>");
print ("<td>Felhasznaló név </td><td>Jelszó</td><td>Admin</td><td>Útvonal tervezés</td><td>IP</td><td></td><td></td></tr></thead>");

for ($i = 0;$i<$number;$i++) {
    print "<tr><td>";
    if ($nev[$i] == "Admin") {
	$readonly= "readonly" ;
	$readonly2 = " disabled=\"disabled\" checked=\"checked\" ";
    } else {
	$readonly = "";
	$readonly2 = "";
    }

    print "<input type=text maxlength=30 size=30 name=\"nev_".$i."\" value='".$nev[$i]."' ".$readonly.">";
    print"</td>";

    print "<td>";
    print "<input type=hidden name=index_".$i." value=\"".$Index[$i]."\">";
    print "<input type=text  maxlength=\"20\" size=\"20\" name=\"jelszo_".$i."\" value='".$jelszo[$i]."'>";
    print "</td>"; 
    print "<td>";
    $checked = "";
    if ($admin[$i] == "on" ) { $checked = "checked";}
    print "<input type=checkbox name=\"admin_".$i."\" ".$checked." ".$readonly2.">";
    print "</td>\n";


    print "<td>";
    $checked = "";
    if ($jogok[$i] == "on" ) { $checked = "checked";}
    print "<input type=checkbox name=\"jogok_".$i."\" ".$checked.">";
    print "</td>\n";

/*    print "<td>";
    print "<select name=\"goal_name_".$i."\">";
	for ($j=0;$j<$goals_number;$j++) {
	    $selected="";
	    if ($goal_Index_[$j] == $goal_name[$i] ) { $selected = "selected";}
	    print "<option value=\"".$goal_Index_[$j]."\" ".$selected.">".$goal_megjegyzes[$j]."</option>\n";
	}
    print "</select>";
    print "</td>";
    
    print "<td>";

    print "<select name=\"funkcio_".$i."\">";

    $selected="";
    if ($funkcio[$i] == "Pickup") { $selected = "selected"; };
    print "<option value=\"Pickup\" ".$selected.">Pickup</option>";

    $selected="";
    if ($funkcio[$i] == "Dropoff") { $selected = "selected"; };
    print "<option value=\"Dropoff\" ".$selected.">Dropoff</option>";
    print "</select>";


    print "</td>";
*/
	print "<td>";
	print "<input type=text maxlength=16 size=16 name=\"ip_".$i."\" value=\"".$ip[$i]."\">";
	print"</td>";

	print "<td>";
//	print "<a href=\"admin_user_goal.php?id=".$Index[$i]."\" class=\"mybutton_vh\">Útvonal tervezés</a>";
	print "</td>";

    	print "<td>";
	if ($nev[$i] != "Admin") {
	    print "<a href=\"admin_user.php?delete=".$Index[$i]."\" class=\"mybutton_vh\">Törlés</a>";
	}		
	print "</td>";

    

}

print "</table>";
print "<input type=hidden name=counter value=".$i.">";
print "<input class=button_mentes type=submit value=Mentés>";
print "<form>";
print "<a href=\"admin_user_1.php\" class=\"mybutton_vh\">Új felhasználó</a>"; 




?>
<?php include __DIR__ . '/footer_inc.php'; ?>
</body>
</html>

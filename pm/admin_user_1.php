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

<center><br>

<br><br><br>
<a href=index.php class=button>Főmenü</a><br><br><hr>

<?php






$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
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
    print $goal_name[$goals_name]."\n";
    $goals_number++;
  }
} else {
  echo "0 results";
}


$conn->close();

$i=0;
print "<form action=admin_user.php method=POST>";
print "<input type=hidden name=\"user_save\" value=\"save\">";
print ("<table class=\"blueTable\">");
print("<thead><tr>");
print ("<td>Felhasznaló név </td><td>Jelszó</td><td>Admin</td><td>Útvonal tervezés</td><td>IP</td></tr></thead>");

    print "<tr><td>";
    print "<input type=text maxlength=30 size=30 name=\"nev_".$i."\" value='".$nev[$i]."'>";
    print"</td>";

    print "<td>";
    print "<input type=hidden name=index_".$i." value=\"".$Index_[$i]."\">";
    print "<input type=text  maxlength=\"20\" size=\"20\" name=\"jelszo_".$i."\" value='".$jelszo[$i]."'>";
    print "</td>"; 
    print "<td>";
    $checked = "";
    if ($admin[$i] == "Y" ) { $checked = "checked";}
    print "<input type=checkbox name=\"admin_".$i."\" ".$checked.">";
    print "</td>\n";

    print "<td>";
    $checked = "";
    if ($admin[$i] == "Y" ) { $checked = "checked";}
    print "<input type=checkbox name=\"jogok_".$i."\" ".$checked.">";
    print "</td>\n";


    print "<td>";
    print "<input type=text maxlength=16 size=16 name=\"ip_".$i."\">";
    print"</td>";



print "</table>";
print "<input type=hidden name=counter value=\"-1\">";
print "<input class=mybutton_vh type=submit value=Mentés>";
print "<form>";





?>



</html>

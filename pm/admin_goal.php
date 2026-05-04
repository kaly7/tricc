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

<h2 class="page-title">Célpontok</h2>
<center>

<br><br><br>


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




if ( isset($_POST["goals_save"]) ){
    // mentsuk egyet
    $counter = $_POST["counter"];
    for ($i=0;$i<$counter;$i++) {
	$index_ = $_POST["index_".$i];
	$megjegyzes = $_POST["megjegyzes_".$i];
	$check = $_POST["check_".$i];
	$delete_check = $_POST["delete_check_".$i];
	//print $index_." - ".$megjegyzes."- ".$check."\n";
	if ($check == "on" ) { $check="Y";}
	$sql = "update Goals set Megjegyzes=\"".$megjegyzes."\",Active=\"".$check."\" where Index_ = ".$index_;
//	print $sql."<hr>";
    
	$result = mysqli_query($conn, $sql);

	if ($delete_check == "on" ) { 
	    $sql = "delete from Goals where Index_ =".$index_.";";
//	    print $sql."<hr>";
    
	    $result = mysqli_query($conn, $sql);
	}


    }
	?>
	<script type = "text/javascript">
            window.setTimeout(function(){
                alert("Adatok mentve!");
            }, 500); 
	</script>
	<?php

}		








$sql = "SELECT Index_,Goal_name,Active, Megjegyzes FROM Goals";
$result = $conn->query($sql);
$goals_number = 0;
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
    //echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
    $goal_name[$goals_number] = $row["Goal_name"];
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


print "<form action=admin_goal.php method=POST>";
print "<input type=hidden name=\"goals_save\" value=\"save\">";
print ("<table class=\"blueTable\">");
print("<thead><tr>");
print ("<td>Célpont neve (Fleet mgr) </td><td>Célpont megjelenített neve</td><td>Aktív</td><td>Törlés</td></tr></thead>");

for ($i = 0;$i<$goals_number;$i++) {
    print "<tr><td>";
    print $goal_name[$i];
    print"</td>";
    print "<td>";
    print "<input type=hidden name=index_".$i." value=\"".$goal_Index_[$i]."\">";
    print "<input type=text  maxlength=\"20\" size=\"20\" name=\"megjegyzes_".$i."\" value='".$goal_megjegyzes[$i]."'>";
    print "</td><td>";
    $checked = "";
    if ($goal_active[$i] == "Y" ) { $checked = "checked";}
    print "<input type=checkbox name=\"check_".$i."\" ".$checked.">";
    print "</td><td>";
    print "<input type=checkbox name=\"delete_check_".$i."\" >";


    print "</td></tr>\n";
}

print "</table>";
print "<input type=hidden name=counter value=".$i.">";
print "<input class=\"button_mentes\" type=submit value=Mentés>";
print "</form>";

?>
    <script>
        function confirmDeletion2(url) {
            if (confirm("Biztosan törölni szeretnéd ezt az elemet?")) {
                window.location.href = url;
            }
        }
        function redirectToPage2(baseUrl, queryString) {
            const fullUrl = `${baseUrl}?${queryString}`;
            window.location.href = fullUrl;
        }
    </script>
<?php include __DIR__ . '/footer_inc.php'; ?>
</body>
</html>

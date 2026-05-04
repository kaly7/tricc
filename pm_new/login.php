<?php
	$ip = isset($_SERVER['HTTP_CLIENT_IP']) 
	? $_SERVER['HTTP_CLIENT_IP'] 
	: (isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
         ? $_SERVER['HTTP_X_FORWARDED_FOR'] 
         : $_SERVER['REMOTE_ADDR']);
#print "IP:".$ip."<hr>";
            


    if ( !isset($_GET["z"])) {



$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";
session_start();
	
        $sql = "select * from Felhasznalok where ip = \"".$ip."\"";
	
	$conn =  new mysqli($servername, $username, $password, $dbname);
	// Check connection
	if ($conn->connect_error) {
	      die("Connection failed: " . $conn->connect_error);
	}

	$result = $conn->query($sql);
	$number = 0;
	if ($result->num_rows > 0) {
	//print "VAN!";
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
	    header("Location: index.php");
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
<center>
<?php
    if (isset($_GET["x"])){
	if ($_GET["x"] == 1 ) {
	    print "Hibás felhasználónév / jelszó";
	}
    }

?>
<br>
<form action=index.php method=post>
<inőut type=hidden name=login value=login.php>
<table class=blueTable>
<thead><tr><td colspan=2>Belépés</td></tr></thead>
<tr>
<td>Felhasználói név:</td><td><input type=text name=login_name></td>
</tr>
<tr>
<td>Jelszó:</td><td><input type=password name=login_passwd></td>
</tr>
</table>
<input type=submit class=mybutton_vh value="Belépés">
</form>
<a href="login.php" class=mybutton_vh>IP alapú belépés</a>

</div>
</html>

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
<html>
<body>
<?php


#var_dump( $_POST);

#foreach ($_POST as $key => $value)
#        echo $key.'='.$value.'<br />';


$goals = $_POST['mytext2'];
$akcio = $_POST['akcio'];

$goals2 = $_POST['mytext22'];
$akcio2 = $_POST['akcio22'];
$conn = new mysqli($servername, $username, $password, $dbname);

// USER ID BEALLITASA

$user_id_ = $_SESSION["user_id"];
if (isset($_POST["id"]) ) { $user_id_ = $_POST["id"];}

if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
} 

$sql= "delete from Felhasznalo_goal_eleje where Felhasznalo_index = \"".$user_id_."\"";
	$result = mysqli_query($conn, $sql);
	$conn->close;

$sql= "delete from Felhasznalo_goal_vege where Felhasznalo_index = \"".$user_id_."\"";
	$result = mysqli_query($conn, $sql);
	$conn->close;


$i=0;

$conn = new mysqli($servername, $username, $password, $dbname);
foreach ($goals as &$value) {
    $sql = "INSERT INTO Felhasznalo_goal_eleje (Felhasznalo_index,Goal_index,Akcio) VALUES ('".$user_id_."', '".$value."','".$akcio[$i]."')";
#echo "<br>".$sql;
    $i++;
    if ($conn->query($sql) === TRUE) {
     //echo "New record created successfully -> GOALS <br>";
    } else {
	echo "Error: " . $sql . "<br>" . $conn->error;
    }
}
$conn->close();
$i=0;

$conn = new mysqli($servername, $username, $password, $dbname);
foreach ($goals2 as &$value) {
    $sql = "INSERT INTO Felhasznalo_goal_vege (Felhasznalo_index,Goal_index,Akcio) VALUES ('".$user_id_."', '".$value."','".$akcio2[$i]."')";
#echo "<br>".$sql;
    $i++;
    if ($conn->query($sql) === TRUE) {
     //echo "New record created successfully -> GOALS <br>";
    } else {
	echo "Error: " . $sql . "<br>" . $conn->error;
    }
}
$conn->close();
header("Location: index.php");
?>


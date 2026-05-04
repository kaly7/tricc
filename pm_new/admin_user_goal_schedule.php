<?php
//print "1";
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

if (isset($_GET["id"]) ) {


    $sql = "SELECT * from Felhasznalok where Index_ = '".$_GET["id"]."'";
//    print $sql;

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
    $result = $conn->query($sql);
    $goals_number = 0;
    if ($result->num_rows > 0) {
     // output data of each row
        while($row = $result->fetch_assoc()) {
	    //echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
	    $_felhasznalo = $row["nev"];
	}
    } else {
	echo "0 results";
    }

$conn->close();
}
if (isset($_GET["id"]) ) { $user_id_ = $_GET["id"]; } else { $user_id_ = $_SESSION["user_id"]; }



$conn =  new mysqli($servername, $username, $password, $dbname);
$sql= "select * from Felhasznalo_goal_eleje where Felhasznalo_index = \"".$user_id_."\"";
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

$conn->close();
//print $eleje_number;
$conn =  new mysqli($servername, $username, $password, $dbname);
$sql= "select * from Felhasznalo_goal_vege where Felhasznalo_index = \"".$user_id_."\"";
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
<?php



if (isset($_GET["id"])) {
    print " / ".$_felhasznalo;

}

?>
<center><br>

<br><br><br>


<?php



// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}





$sql = "SELECT Index_,Goal_name,Active, Megjegyzes FROM Goals where Active= \"Y\"";
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


?>
<script src="jquery.min.js"></script>


<script type="text/javascript">
$(document).ready(function() {
    var max_fields      = 30; //maximum input boxes allowed
    var wrapper   		= $(".input_fields_wrap"); //Fields wrapper
    var wrapper2   		= $(".input_fields_wrap2"); //Fields wrapper
    var add_button      = $(".add_field_button"); //Add button ID
    var add_button2      = $(".add_field_button2"); //Add button ID

    var x = 0; //initlal text box count
    var x2 = 0; //initlal text box count
    
    $(add_button).click(function(e){ //on add input button click
	e.preventDefault();
	if(x < max_fields){ //max input box allowed
	    x++; //text box increment
	    //$(wrapper).append('<div><input type="text" name="mytext[]"/><a href="#" class="remove_field">Törlés</a></div>'); //add input box
	     <?php
		echo("$(wrapper).append('<div><select name=\"mytext2[]\">");
		for ($i=0;$i<$goals_number;$i++) {
    				echo "<option value=\"" . $goal_Index_[$i]. "\">". $goal_megjegyzes[$i]."</option>";
		}
		echo("</select>");
	    //	echo("<select name=\"prioritas[]\">");
	//	    for($i=1;$i<31;$i++) {
	//		    echo "    <option value=\"".$i."\">".$i."</option>";
	//	    }
	//	echo("</select>");
		echo("<select name=\"akcio[]\">");
		echo("<option value=\"pickup\">pickup</option>");
		echo("<option value=\"dropoff\">dropoff</option>");
		echo("</select>");


		echo("<a href=\"#\" class=\"remove_field\">Törlés</a></div>');" );
	    ?>
	}
    });

    $(add_button2).click(function(e){ //on add input button click
	e.preventDefault();
	if(x2 < max_fields){ //max input box allowed
	    x2++; //text box increment
	    //$(wrapper).append('<div><input type="text" name="mytext[]"/><a href="#" class="remove_field">Törlés</a></div>'); //add input box
	     <?php
		echo("$(wrapper2).append('<div><select name=\"mytext22[]\">");
		for ($i=0;$i<$goals_number;$i++) {
    				echo "<option value=\"" . $goal_Index_[$i]. "\">". $goal_megjegyzes[$i]."</option>";
		}
		echo("</select>");
	//    	echo("<select name=\"prioritas[]\">");
	//	    for($i=1;$i<31;$i++) {
	//		    echo "    <option value=\"".$i."\">".$i."</option>";
	//	    }
	//	echo("</select>");
		echo("<select name=\"akcio22[]\">");
		echo("<option value=\"pickup\">pickup</option>");
		echo("<option value=\"dropoff\">dropoff</option>");
		echo("</select>");


		echo("<a href=\"#\" class=\"remove_field2\" >Törlés</a></div>');" );
	    ?>
	}
    });

    $(wrapper).on("click",".remove_field", function(e){ //user click on remove text
	e.preventDefault(); $(this).parent('div').remove(); x--;
    })

    $(wrapper2).on("click",".remove_field2", function(e){ //user click on remove text
	e.preventDefault(); $(this).parent('div').remove(); x--;
    })
});

</script>
<form action="admin_user_goal_schedule_save.php" method="post">
<?php
    if (isset($_GET["id"]) ) {
	print "<input type=hidden name=\"id\" valeu=\"".$_GET["id"]."\">";
    }
?>
<div class="input_fields_wrap">
    <button class="add_field_button"  style="box-shadow: 0px 1px 0px 0px #f0f7fa;
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
    text-shadow:0px -1px 0px #5b6178;">Új lépés hozzáadása az elejéhez </button>
<?php
	for ($ie=0;$ie<$eleje_number;$ie++) {
//	    print $eleje_Goal_index[$ie]."--";
	    print "<div><select name=\"mytext2[]\">";
		for ($i=0;$i<$goals_number;$i++) {
				$selected = "";
				if ($goal_Index_[$i] == $eleje_Goal_index[$ie] ) { $selected ="selected";}
    				echo "<option value=\"" . $goal_Index_[$i]. "\" ".$selected.">". $goal_megjegyzes[$i]."</option>";
		}
	    print "</select>";
	    print "<select name=\"akcio[]\">";
	    $selected = "";
	    if ($eleje_Akcio[$ie] == "pickup" ) { $selected = "selected";}
	    print "		<option value=\"pickup\" ".$selected.">pickup</option>";
	    $selected = "";
	    if ($eleje_Akcio[$ie] == "dropoff" ) { $selected = "selected";}
	    print"  	<option value=\"dropoff\"".$selected.">dropoff</option>";
	    print "</select>";
		echo("<a href=\"#\" class=\"remove_field\" >Törlés</a>" );
	    print " </div>";
	}

?>
</div>
<hr>


<div class="input_fields_wrap2">
    <button class="add_field_button2"  style="box-shadow: 0px 1px 0px 0px #f0f7fa;
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
    text-shadow:0px -1px 0px #5b6178;">Új lépés hozzáadása a végéhez </button>

    <div>

<?php 

	for ($ie=0;$ie<$vege_number;$ie++) {
	    print "<div><select name=\"mytext22[]\">";
		for ($i=0;$i<$goals_number;$i++) {
				$selected = "";
				if ($goal_Index_[$i] == $vege_Goal_index[$ie] ) { $selected ="selected";}
    				echo "<option value=\"" . $goal_Index_[$i]. "\" ".$selected.">". $goal_megjegyzes[$i]."</option>";
		}
	    print "</select>";
	    print "<select name=\"akcio22[]\">";
	    $selected = "";
	    if ($vege_Akcio[$ie] == "pickup" ) { $selected = "selected";}
	    print "		<option value=\"pickup\" ".$selected.">pickup</option>";
	    $selected = "";
	    if ($vege_Akcio[$ie] == "dropoff" ) { $selected = "selected";}
	    print"  	<option value=\"dropoff\"".$selected.">dropoff</option>";
	    print "</select>";
		echo("<a href=\"#\" class=\"remove_field2\" >Törlés</a>" );
	    print " </div>";
	}


?>

    </div>
</div>

<?php
    if (isset($_GET["id"])) {
	print "<input type=hidden name=\"id\" value=\"".$_GET["id"]."\">";
    }
?>
<input type="submit"  class="myButton_vh" value="Mentés">


</form>
</html>
</body>





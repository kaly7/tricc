<?php
    session_start();
    if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
	header("location: login.php");
	exit;
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

$sql = "SELECT * FROM Goals where Active = \"Y\"";
$result = $conn->query($sql);
$goals_number = 0;
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
    //echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
    $goal_name_megjegyzes[$goals_number] = $row["Megjegyzes"];
    $goal_Index_[$goals_number] = $row["Index_"];
    $goal_real_name[$goals_number] = $row["Goal_name"];
    $goals_number++;
  }
} else {
  echo "0 results";
}
$conn->close();


$conn = new mysqli($servername, $username, $password, $dbname);
// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
//print "user id: ". $_SESSION["user_id"]."<hr>";
//print "admin: ".$_SESSION['admin']."<hr>";
//print_r ($_SESSION);
$sql = "select * from Felhasznalok where Index_ = \"".$_SESSION["user_id"]."\"";
$result = $conn->query($sql);
$number = 0;
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
    $number++;
  }
} else {
  echo "0 results";
}
$conn->close();




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
/*
// eleje utvonal

if ( $eleje_number >0) {

    $checked = "";
//    print "ELEJE->".$_GET["eleje_check"]." - ".$_POST["eleje_check"]."<hr>";
    if ($_GET["eleje_check"] == "on" ) { $checked="checked" ; }
    if ( isset($_GET["zz"]) ) { $checked="checked"; }

//    print "<input type=checkbox ".$checked." name=eleje_check>";
    print "Eleje :";

    for ($ie=0;$ie<$eleje_number;$ie++) {
	for ($i=0;$i<$goals_number;$i++) {
	    if ($eleje_Goal_index[$ie] == $goal_Index_[$i] ) {
		if ($eleje_Akcio[$ie] == "pickup" ) {$akcio_pd = " /P ";} else { $akcio_pd = " /D";}
		print "<button class=\"mybutton_vh\" >".$goal_name_megjegyzes[$i]." ".$akcio_pd."</button>";
	    }	
	}
    }
echo "<hr>";    
}
*/
?>


<script src="jquery.min.js"></script>
<div class="gombok">
    <?php
    $new = $_POST["new"];
    $goals = $_POST['mytext2'];

    if (isset($_POST["delete"])) {
        //echo "DELETE ";
        $j=0;
        $i=0;
        foreach ($goals as &$value) {
            if ($_POST["delete"] != $j) {
                $goals2[$i] = $value;
                $i++;
            }
            $j++;
        }
        unset($goals);
        $i=0;
        foreach ($goals2 as &$value) {
            $goals[$i]=$value;
            $i++;
        }
    }


    $j=0;
    //echo $new."<hr>";
    $van=0;
    echo "<table border=0 valign=center><tr>";
    $cella = 0;
    $max_cella = 6;
    $cella_percent = 100 / $max_cella;
    if (isset($goals)) {
        foreach ($goals as &$value) {
//            echo "<td style=\"width:".$cella_percent."%\" align=center>";
            echo "<td style=\"text-align: center; vertical-align: middle;width:".$cella_percent."%\">";
            echo "<form action=route_add.php  method=post>";
            echo "<input type=hidden name=delete value=\"".$j."\">";
            echo "<input type=submit class=\"myButton_vh\" name=mytext1[".$j."] value=\"".$value."\">";
            $van=1;
                $j1=0;
                foreach($goals as &$value2) {
                    echo "<input type=hidden name=mytext2[".$j1."] value=\"".$value2."\">";
                    $j1++;
                }
                if (isset($new)) {
                    echo "<input type=hidden name=mytext2[".$j1."] value=\"".$new."\">";
                }
            echo "</form>";
            echo"</td>";
            $cella++;
            if ( ($cella % $max_cella) == 0) {
                echo "</tr><tr>";
            }
            $j++;
        }
    }
    if (isset($new)) {
        $van=1;
        echo "<td style=\"width:".$cella_percent."%\" align=center>";
        echo "<form action=route_add.php method=post>";
        echo "<input type=hidden name=delete value=\"".$j."\">";
        echo "<input type=submit class=\"myButton_vh\"  name=mytext1[".$j."] value=\"".$new."\">";

            $j1=0;
            foreach($goals as &$value2) {
                echo "<input type=hidden name=mytext2[".$j1."] value=\"".$value2."\">";
                $j1++;
            }
            if (isset($new)) {
                echo "<input type=hidden name=mytext2[".$j1."] value=\"".$new."\">";
            }
        echo "</form></td>";




    }
    if ($van == 0) {
        echo"<td>Nincs útvonal.</td>";
    }
    echo "</table>";

// vege utvonal

/*
if ( $vege_number >0) {
//    print "<input type=checkbox checked name=vege_check>";
    print "<hr>Vége :";
    for ($ie=0;$ie<$vege_number;$ie++) {
	for ($i=0;$i<$goals_number;$i++) {
	    if ($vege_Goal_index[$ie] == $goal_Index_[$i] ) {
		if ($vege_Akcio[$ie] == "pickup" ) {$akcio_pd = " /P ";} else { $akcio_pd = " /D";}
		print "<button class=\"mybutton_vh\" >".$goal_name_megjegyzes[$i]." ".$akcio_pd."</button>";
	    }	
	}
    }

}
*/


            echo "<hr>";
            echo "<form action=route_add_save.php  method=post>";
//	    echo " <input type=checkbox  name=eleje_check> Eleje szekvencia ";
	    echo "Megnevezés:<br><input type=text name=route_name><br>";
            echo "<input type=submit class=button_mentes value=\"Mentés\">";
//	    echo " <input type=checkbox  name=vege_check> Vége szekvencia";

            $j1=0;
            foreach($goals as &$value2) {
                echo "<input type=hidden name=mytext2[".$j1."] value=\"".$value2."\">";
                $j1++;
            }
            if (isset($new)) {
                echo "<input type=hidden name=mytext2[".$j1."] value=\"".$new."\">";
            }
            echo"</form>";
            echo "<hr>";

    echo"<!-- GOMBOK LISTAJA --><div>";
    //GOALS KIIRAS
    echo "<br> Elérhető célok:<br>";
    echo "<table><tr>";
    $row_count =0;
    
    for ($i=0;$i<$goals_number;$i++) {
	    
	    if ($row_count>3) {
		echo "</tr><tr>";
		$row_count = 0;
	    }
	    echo "<td>";
	


	    if (substr($goal_name_megjegyzes[$i],0,1) != "*") {
                echo "<form action=route_add.php method=post>";
                echo "<input type=submit class=\"myButton_vh\" type=button value=\"".$goal_name_megjegyzes[$i]. "\">";
                echo "<input type=hidden name=new value=\"".$goal_name_megjegyzes[$i]."\">";
                $j=0;
                if (isset($goals)) {
                    foreach ($goals as &$value) {
                        echo "<input type=hidden name=mytext2[".$j."] value=\"".$value."\">";
                        $j++;
                    }
                }
                if (isset($new)) {
                    echo "<input type=hidden name=mytext2[".$j."] value=\"".$new."\">";
                }
                echo "</form>";
	    }

	    echo "</td>";
	    $row_count++;
    }
    echo "</tr></table>";
    ?>

</div>
</div<
</html>
</body>



</html>

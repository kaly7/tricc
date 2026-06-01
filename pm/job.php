<?php

$job_id = $_GET["id"];


echo "<html>";
echo "<meta http-equiv=\"Refresh\" content=\"3; url='job.php?id=".$job_id."'\">";
?>

<link rel="stylesheet" href="mystyle.css">


<style>
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


</style>



<br><br><br>
<a href=index.php class=button>Főmenü</a><br><br><hr><br>
<?php echo "<a href=\"job_del.php?id=".$job_id."\" class=button>Feladat megszakítása</a><br><br><hr>"; ?>
<br><br><hr>
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

$sql = "SELECT Index_,Goal_name FROM Goals where LEFT(Goal_name,2) != ' *'";
$result = $conn->query($sql);
$goals_number = 0;
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
    //echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
    $goal_name[$goals_number] = $row["Goal_name"];
    $goals_number++;
  }
} else {
  echo "0 results";
}
$conn->close();

echo "Job ID:".$job_id;
echo "<hr>";


$sql="Select * from Button_Goals where Megjegyzes=\"".$job_id."\"";

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);
// Check connection
if (!$conn) {
  die("Connection failed: " . mysqli_connect_error());
}

$result = mysqli_query($conn, $sql);

if (mysqli_num_rows($result) > 0) {
  // output data of each row
  while($row = mysqli_fetch_assoc($result)) {
    //echo "Goal: " . $row["Goal_name"]. "<br>";
    echo " <input type=button class=mybutton_vh value=\"".$row["Goal_name"]."\">";
  }
} else {
  echo "0 results";
}

mysqli_close($conn);


?><hr>
<table class="blueTable">
<thead>
<tr>
<th>&nbsp;</th>
<th>&nbsp;Kiss_Gyuri</th>
<th>&nbsp;Kiss_Marci</th>
</tr>
</thead>
<tbody>
<tr>
<td>&Uacute;tvonalpont</td>
<td>&nbsp;</td>
<td>&nbsp;</td>
</tr>
<tr>
<td><span style="caret-color: #000000; color: #000000; font-family: -webkit-standard; font-size: medium;">&nbsp;St&aacute;tusz&nbsp;</span></td>
<td>&nbsp; 
<?php
    $f = "/var/www/html/pm/tmp/GYURI";
    echo file_exists($f) ? htmlspecialchars(trim(file_get_contents($f))) : '<span style="color:#999">N/A</span>';
    ?>
</td>
<td>&nbsp;
<?php
    $f = "/var/www/html/pm/tmp/MARCI";
    echo file_exists($f) ? htmlspecialchars(trim(file_get_contents($f))) : '<span style="color:#999">N/A</span>';
    ?>

</td>
</tr>
<tr>
<td>&nbsp;Utols&oacute; JobNo.</td>
<td>&nbsp;</td>
<td>&nbsp;</td>
</tr>
</tbody>
</table>



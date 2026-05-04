<html>
<body>
<link rel="stylesheet" href="mystyle.css"><br>
<a href=index.php class=button>Főmenü</a><br><br><hr>
Definiált útvonalak listája
<?php

$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$sql = "SELECT * FROM Buttons";
$result = $conn->query($sql);
$button_number = 0;
if ($result->num_rows > 0) {
  // output data of each row
  while($row = $result->fetch_assoc()) {
    //echo "id: " . $row["Index_"]. " - Name: " . $row["Goal_name"]. "<br>";
    $button_name[$button_number] = $row["Button_name"];
    $button_id[$button_number] = $row["Index_"];
    $button_number++;
  }
} else {
  echo "0 results";
}
$conn->close();



for ($i=0;$i<$button_number;$i++) {
    $sql = "SELECT * FROM Button_Goals where Button_index = '".$button_id[$i]."'";


    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $result = $conn->query($sql);
?>
<table class="tg" style="undefined;table-layout: fixed; width: 317px">
<colgroup>
<col style="width: 146px">
<col style="width: 171px">
</colgroup>
<thead>
<?php
    echo "<tr>";
    echo "<th class=\"tg-baqh\" colspan=\"4\">".$button_name[$i]." </th>";
    echo " </tr>";
    echo "</thead>";
    echo "<tbody>";
    echo "  <tr>";
    echo "    <td class=\"tg-baqh\" >";
    echo "	<form action=\"button_go.php\" method=\"post\"><input type=\"hidden\" name=\"id\" value=\"".$button_id[$i]."\"><input type=submit class=\"myButton_vh\" value=\"Végrehajt\"></form>";
    echo "</td> ";


    echo "<td class=\"tg-baqh\" >";
    echo "	<form action=\"button_del.php\" method=\"post\"><input type=\"hidden\" name=\"id\" value=\"".$button_id[$i]."\"><input type=submit class=\"myButton_del\" value=\"Gomb törlése\"></form>";

    echo "</td>";
    echo " </tr>";

    if ($result->num_rows > 0) {
	while($row = $result->fetch_assoc()) {

	echo "<tr>";
	echo "<td class=\"tg-lqy6\" colspan=\"2\">";
	echo $row["Goal_name"];
	echo "</td>";
	echo "<td class=\"tg-lqy6\" colspan=\"1\">";
	echo $row["prioritas"];
	echo "</td>";
	echo "<td class=\"tg-lqy6\" colspan=\"1\">";
	echo $row["akcio"];
	echo "</td>";
	echo " </tr>";
        }
    } else {
	echo "0 results";
    }
    $conn->close();

echo "</tbody></table><br>";







}




?>
</body>
</html>

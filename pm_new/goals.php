<html>
<link rel="stylesheet" href="mystyle.css"><br><br>
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

$sql = "SELECT Index_,Goal_name FROM Goals";
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

?>


<script src="jquery.min.js"></script>


<script type="text/javascript">
$(document).ready(function() {
    var max_fields      = 30; //maximum input boxes allowed
    var wrapper   		= $(".input_fields_wrap"); //Fields wrapper
    var add_button      = $(".add_field_button"); //Add button ID

    var x = 1; //initlal text box count
    $(add_button).click(function(e){ //on add input button click
	e.preventDefault();
	if(x < max_fields){ //max input box allowed
	    x++; //text box increment
	    //$(wrapper).append('<div><input type="text" name="mytext[]"/><a href="#" class="remove_field">Törlés</a></div>'); //add input box
	     <?php
		echo("$(wrapper).append('<div><select name=\"mytext2[]\">");
		for ($i=0;$i<$goals_number;$i++) {
    				echo "<option value=\"" . $goal_name[$i]. "\">". $goal_name[$i]."</option>";
		}
		echo("</select>");
	    	echo("<select name=\"prioritas[]\">");
		    for($i=1;$i<31;$i++) {
			    echo "    <option value=\"".$i."\">".$i."</option>";
		    }
		echo("</select>");
		echo("<select name=\"akcio[]\">");
		echo("<option value=\"pickup\">pickup</option>");
		echo("<option value=\"dropoff\">dropoff</option>");
		echo("</select>");


		echo("<a href=\"#\" class=\"remove_field\">Törlés</a></div>');" );
	    ?>
	}
    });

    $(wrapper).on("click",".remove_field", function(e){ //user click on remove text
	e.preventDefault(); $(this).parent('div').remove(); x--;
    })
});

</script>
<form action="goal_save.php" method="post">
Gomb neve:<input type=text name=button_name>

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
    text-shadow:0px -1px 0px #5b6178;">Új lépés hozzáadása</button>
    <!-- div><input type="text" name="mytext[]"></div -->

    <div><select name="mytext2[]">
	    <?php
		for ($i=0;$i<$goals_number;$i++) {
    				echo "<option value=\"" . $goal_name[$i]. "\">". $goal_name[$i]."</option>";
		}
	    ?>
	</select>
	<select name="prioritas[]">
	    <?php
		    for($i=1;$i<31;$i++) {
			    echo "    <option value=\"".$i."\">".$i."</option>";
		    }
	    ?>
	    </select>
	    <select name="akcio[]">
		<option value="pickup">pickup</option>
		<option value="dropoff">dropoff</option>
	    </select>
    </div>
</div>
<input type="submit"  class="myButton_vh" value="Mentés">


</form>
</html>
</body>



</html>

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


<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<body>
<style>
body, html {
  height: 100%;
  margin: 0;
  font-family: Arial, Helvetica, sans-serif;
}

* {
  box-sizing: border-box;
}

.bg-image {
  /* The image used */
  background-image: url("pictures/16.jpg");
  
  /* Add the blur effect */
  filter: blur(1px);
  -webkit-filter: blur(1px);
  
  /* Full height */
  height: 100%; 
  
  /* Center and scale the image nicely */
  background-position: center;
  background-repeat: no-repeat;
  background-size: cover;
}

/* Position text in the middle of the page/image */
.bg-text {
  background-color: rgb(0,0,0); /* Fallback color */
  background-color: rgba(0,0,0, 0.4); /* Black w/opacity/see-through */
  color: white;
  font-weight: bold;
  border: 3px solid #f1f1f1;
  position: absolute;
  top: 20%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 2;
  width: 90%;
  padding: 10px;
  text-align: left;
}



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


.myButton_vh {
    box-shadow: 0px 1px 0px 0px #f0f7fa;
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
    text-shadow:0px -1px 0px #5b6178;
}
.myButton_vh:hover {
    background:linear-gradient(to bottom, #019ad2 5%, #33bdef 100%);
    background-color:#019ad2;
}
.myButton_vh:active {
    position:relative;
    top:1px;
}



</style>
</head>
<body>

<div class="bg-image"></div>

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

<?php
    $job_id=$_GET["id"];
    $parancs ="queueCancel jobId ".$job_id;
    $myfile = fopen("/var/www/html/pm/tmp/newfile.txt", "w") or die("Unable to open file!");
    fwrite($myfile, $parancs);
    fclose($myfile);
    $ret_val = exec("/var/www/html/pm/go.pl",$retval);



$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";

$sql="update Button_Goals set akcio=\"deleted\" where Megjegyzes=\"".$job_id."\"";
#$sql="Select * from Button_Goals where akcio=\"aktiv\" order by Megjegyzes";

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);
// Check connection
if (!$conn) {
  die("Connection failed: " . mysqli_connect_error());
}

$result = mysqli_query($conn, $sql);
?>


<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Refresh" content="3; url='index.php'" >
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
  background-image: url("fogaskerek.jpeg");
  
  /* Add the blur effect */
  filter: blur(8px);
  -webkit-filter: blur(8px);
  
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
  top: 50%;
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
<center><br>


<br><hr>
<?php
echo "JobId:".$job_id." törölve";
?>
<hr>
</div>
</html>

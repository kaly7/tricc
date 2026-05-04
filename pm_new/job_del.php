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


<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Refresh" content="3; url='index.php'" >
<link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
<title>Robot Fleet Manager</title>
</head>
<body>
<?php include __DIR__ . '/header_inc.php'; ?>
<div class="bg-text">
<center><br>


<br><hr>
<?php
echo "JobId:".$job_id." törölve";
?>
<hr>
</div>
</html>

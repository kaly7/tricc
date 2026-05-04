<html>
<meta http-equiv="Refresh" content="3; url='log.php'" />
<?php

$myfile = fopen("now.txt", "r") or die("Unable to open file!");
// Output one line until end-of-file
while(!feof($myfile)) {
  echo fgets($myfile) . "<br>";
}
fclose($myfile);
?>

</body>
</html>


<?php
// Adatbázis kapcsolódási adatok
$servername = "localhost";
$username = "robot";
$password = "abrakadabra";
$dbname = "Robot";

$route_Index_ = $_POST["id"];
//echo "Route:".$route_Index_."<hr>";
// Kapcsolódás az adatbázishoz

$sql = "DELETE from records WHERE Route_id=".$route_Index_;
	$conn = new mysqli($servername, $username, $password, $dbname);
	if ($conn->connect_error) {
	      die("Connection failed: " . $conn->connect_error);
	} 
	if ($conn->query($sql) === TRUE) {
//	      echo "New record created successfully";
	} else {
	     echo "Error: " . $sql . "<br>" . $conn->error;
	}

	$conn->close();

$conn = new mysqli($servername, $username, $password, $dbname);

// Kapcsolódás ellenőrzése
if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}

// Adatok feldolgozása és mentése
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $rows = $_POST['rows'] ?? [];

    // Ellenőrizzük, hogy van-e mentendő adat
    if (empty($rows)) {
        die('Nincs adat a mentéshez.');
    }

    // Adatok beillesztése az adatbázisba
    foreach ($rows as $row) {
        $selectedDays = $row['days'] ?? [];
        $time = $row['time'] ?? '';
        $active = isset($row['active']) ? 1 : 0;
        $inactiveUntil = isset($row['inactiveUntil']) ? 1 : 0;
        $inactiveDate = $row['inactiveDate'] ?? null;
        $skipNext = isset($row['skipNext']) ? 1 : 0;
        $onceOnly = isset($row['onceOnly']) ? 1 : 0;

        // A kiválasztott napok összefűzése vesszővel elválasztva
        $days = implode(', ', $selectedDays);
	    
/*	echo $days."<hr>";
	echo $time."<hr>";
	echo $active."<hr>";
	echo $inactiveUntil."<hr>";
	echo $inactiveDate."<hr>";
	echo $skipNext."<hr>";
	echo $onceOnly."<hr>";
*/
	$sql = "INSERT INTO records (Route_id,days,time,active,inactiveUntil,inactiveDate,skipNext,onceOnly) VALUES(".$route_Index_.", \"".$days."\", \"".$time."\", ".$active;
	$sql = $sql .", ".$inactiveUntil. ", \"".$inactiveDate."\", ".$skipNext.", ".$onceOnly.")";

	$conn = new mysqli($servername, $username, $password, $dbname);
	if ($conn->connect_error) {
	      die("Connection failed: " . $conn->connect_error);
	} 
	if ($conn->query($sql) === TRUE) {
//	      echo "New record created successfully";
	} else {
	     echo "Error: " . $sql . "<br>" . $conn->error;
	}

	$conn->close();

    }

    echo "<html><head>";
    echo "<meta http-equiv=\"Refresh\" content=\"3; url=schedule_add.php\" >";
    echo "    <link rel=\"stylesheet\" href=\"styles.css\">";
    echo " <center>";
    echo 'Adatok sikeresen elmentve az adatbázisba.';
}

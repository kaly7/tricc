<?php
require 'config.php';

$sql = "SELECT * FROM days order by date";
$result = $mysqli->query($sql);

if ($result->num_rows > 0) {
    $days = array();
    while ($row = $result->fetch_assoc()) {
        $days[] = $row;
    }
    echo json_encode($days);
} else {
    echo json_encode(array());
}

$mysqli->close();
?>

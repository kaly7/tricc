<?php
require 'config.php';

$id = $_POST['id'];
$date = $_POST['date'];
$day_type = $_POST['day_type'];
$description = $_POST['description'];

$stmt = $mysqli->prepare("UPDATE days SET date = ?, day_type = ?, description = ? WHERE id = ?");
$stmt->bind_param('sssi', $date, $day_type, $description, $id);

if ($stmt->execute()) {
    // Átirányítás az index.php oldalra
    header('Location: index.php');
    exit();
} else {
    // Hiba kezelése JSON válaszként
    echo json_encode(['error' => $stmt->error]);
}

$stmt->close();
$mysqli->close();
?>

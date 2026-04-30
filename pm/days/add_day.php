<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';

$date = $_POST['date'];
$day_type = $_POST['day_type'];
$description = $_POST['description'];

if (empty($date) || empty($day_type)) {
    echo json_encode(['error' => 'Minden mezőt ki kell tölteni!']);
    exit();
}

$stmt = $mysqli->prepare("DELETE FROM days where date=\"".$date."\"";
$stmt->execute;

$stmt = $mysqli->prepare("INSERT INTO days (date, day_type, description) VALUES (?, ?, ?)");
$stmt->bind_param('sss', $date, $day_type, $description);

if ($stmt->execute()) {
    echo json_encode(['success' => 'Új nap sikeresen hozzáadva!']);
    header('Location: index.php');
    exit();
} else {
    echo json_encode(['error' => 'Hiba történt: ' . $stmt->error]);
}

$stmt->close();
$mysqli->close();
?>

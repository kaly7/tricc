<?php
require 'config.php';

$id = $_POST['id'];

$stmt = $mysqli->prepare("DELETE FROM days WHERE id = ?");
$stmt->bind_param('i', $id);

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

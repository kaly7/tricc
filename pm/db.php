<?php
$host = 'localhost';
$db = 'Robot';
$user = 'robot';
$pass = 'abrakadabra';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

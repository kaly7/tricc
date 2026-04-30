<?php
$host = 'localhost';
$dbname = 'Robot';
$username = 'robot';
$password = 'abrakadabra';

$mysqli = new mysqli($host, $username, $password, $dbname);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
?>

<?php
require __DIR__ . '/../bootstrap.php';
Auth::start();

$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$hostNoPort = explode(':', $host)[0];

// Payslip local login is disabled -> Auth Center (after login -> apps.php)
header('Location: http://' . $hostNoPort . ':90/login.php?return=' . urlencode('/apps.php'));
exit;

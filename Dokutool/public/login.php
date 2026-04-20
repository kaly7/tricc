<?php
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$hostNoPort = explode(':', $host)[0];
header('Location: http://' . $hostNoPort . ':90/login.php?return=' . urlencode('/apps.php'));
exit;

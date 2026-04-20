<?php
// ProjectMgr "logout" = back to Auth Center module selector (keep login)
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$hostNoPort = explode(':', $host)[0];
header('Location: http://' . $hostNoPort . ':90/apps.php');
exit;

<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["admin"] != "on") {
    header("location: index.php"); exit;
}

$log_file = "/var/www/html/pm/tmp/query_comm.log";

if (isset($_POST['clear'])) {
    file_put_contents($log_file, "");
    header("location: admin_update.php"); exit;
}

if (!file_exists($log_file) || filesize($log_file) === 0) {
    header("location: admin_update.php?fleet_log_empty=1"); exit;
}

$filename = "fleet_comm_" . date("Y-m-d_H-i-s") . ".log";
header("Content-Type: text/plain; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Content-Length: " . filesize($log_file));
readfile($log_file);

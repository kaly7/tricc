<?php
define('AGV_DB_HOST', 'localhost');
define('AGV_DB_USER', 'robot');
define('AGV_DB_PASS', 'abrakadabra');
define('AGV_DB_NAME', 'agvmgr');

function agv_db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(AGV_DB_HOST, AGV_DB_USER, AGV_DB_PASS, AGV_DB_NAME);
        if ($conn->connect_error) {
            die('DB kapcsolat hiba: ' . $conn->connect_error);
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

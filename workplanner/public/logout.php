<?php
require_once __DIR__ . '/../app/auth.php';
logout(); // csak a _wp_user cache-t törli
$cfg  = _wp_auth_cfg();
$host = explode(':', $_SERVER['HTTP_HOST'])[0];
header('Location: http://' . $host . ':' . $cfg['auth_port'] . '/logout.php');
exit;

<?php
// Belépés az auth_centeren keresztül történik
require_once __DIR__ . '/../app/auth.php';
start_session();
if (current_user()) { redirect('index.php'); }
$cfg    = _wp_auth_cfg();
$host   = explode(':', $_SERVER['HTTP_HOST'])[0];
$return = 'http://' . $_SERVER['HTTP_HOST'] . '/index.php';
header('Location: http://' . $host . ':' . $cfg['auth_port'] . '/login.php?return=' . urlencode($return));
exit;

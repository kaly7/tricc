<?php
require_once __DIR__.'/../src/auth.php';
start_session();
$worker = !empty($_SESSION['worker_mode']);
logout_user();
header('Location: '.($worker ? 'worker_login.php' : 'login.php'));

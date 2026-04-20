<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
logout();
header('Location: ' . base_url('login.php'));
exit;

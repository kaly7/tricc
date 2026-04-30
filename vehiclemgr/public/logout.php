<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';
logout();
redirect('login.php');

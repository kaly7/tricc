<?php
$allowed = ['light','dark','modern','industrial'];
$t = $_POST['theme'] ?? 'dark';
if(!in_array($t, $allowed, true)) $t = 'dark';
setcookie('theme', $t, time()+60*60*24*365, '/');
$ref = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: '.$ref);

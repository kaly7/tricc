<?php
require_once __DIR__.'/../src/auth.php';
start_session();
if (!hash_equals($_SESSION['csrf'], $_POST['_csrf'] ?? '')) { header('Location: login.php?err=1'); exit; }
$email = trim($_POST['email'] ?? '');
$pass  = (string)($_POST['password'] ?? '');
if (!$email || !$pass || !login_user($email, $pass)) { header('Location: login.php?err=1'); exit; }
$u = current_user();
header('Location: '.($u && $u['role']==='worker' ? 'my_om_jobs.php' : 'records.php')); exit;

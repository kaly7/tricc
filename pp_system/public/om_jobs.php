<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
if(!is_admin()){ header('Location: my_om_jobs.php'); exit; }
header('Location: my_om_jobs.php');

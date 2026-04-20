<?php
require __DIR__.'/../app/functions.php';
header('Location: ' . _auth_center_url('/login.php?return=' . urlencode('/apps.php')));
exit;

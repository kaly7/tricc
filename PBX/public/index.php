<?php
require __DIR__.'/../app/auth.php';
require_login();
redirect('pbx_systems.php');

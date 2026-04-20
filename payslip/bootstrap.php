<?php
require __DIR__ . '/app/Config/config.php';

// vendor/autoload.php is still needed for PHPMailer; if you haven't installed yet, run composer install.
require __DIR__ . '/vendor/autoload.php';

require __DIR__ . '/app/Db.php';
require __DIR__ . '/app/Auth.php';

spl_autoload_register(function ($class) {
    $base = __DIR__ . '/app/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require $file;
});
require_once __DIR__ . '/inc/taxid_helper.php';
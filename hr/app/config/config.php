<?php
// Timezone
define('APP_TIMEZONE', 'Europe/Budapest');

// DB
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'hr');
define('DB_USER', 'ppdb');          // állítsd a saját DB user-re
define('DB_PASS', 'abrakadabra');   // állítsd a saját DB jelszóra
define('DB_CHARSET', 'utf8mb4');

// App paths
define('APP_ROOT', realpath(__DIR__ . '/../../'));
define('STORAGE_DIR', APP_ROOT . '/storage');
define('UPLOADS_DIR', STORAGE_DIR . '/uploads');

// Security
define('SESSION_COOKIE', 'HRSESS');
define('SESSION_LIFETIME_HOURS', 24);
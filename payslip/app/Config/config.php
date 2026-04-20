<?php
// DB
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'payslip');
define('DB_USER', 'ppdb');
define('DB_PASS', 'abrakadabra');
define('DB_CHARSET', 'utf8mb4');

// Mail
define('MAIL_ALLOW_DUPLICATE_SENDS', true);
define('SMTP_HOST', 'mail.t-online.hu');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@perfect-phone.hu');
define('SMTP_PASS', 'PPn0R3p1@y-25');
define('SMTP_SECURE', 'tls');
define('SMTP_FROM', 'noreply@perfect-phone.hu');
define('SMTP_FROM_NAME', 'PP rendszer');

// App paths
define('APP_ROOT', realpath(__DIR__ . '/../../'));
define('STORAGE_DIR', APP_ROOT . '/storage');
define('UPLOADS_DIR', STORAGE_DIR . '/uploads');
define('OUTPUT_DIR',  STORAGE_DIR . '/output');
define('TMP_DIR',     STORAGE_DIR . '/tmp');

// Tools
define('BIN_QPDF', '/usr/bin/qpdf');
define('BIN_PDFINFO', '/usr/bin/pdfinfo');
define('BIN_PDFTOTEXT', '/usr/bin/pdftotext');

//define('MAIL_OVERRIDE_TO', 'ide_a_teszt_cimed@valami.hu');
//define('MAIL_DRY_RUN', true);
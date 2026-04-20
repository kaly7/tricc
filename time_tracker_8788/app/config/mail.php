<?php
declare(strict_types=1);

return [
    'mail_from' => 'noreply@perfect-phone.hu',
    'mail_from_name' => 'Perfect-Phone / Munkaidő nyilvántartás',
    'mail_bcc' => 'kalamar.janos@gmail.com',

    'smtp_host' => 'mail.t-online.hu',
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_user' => 'noreply@perfect-phone.hu',
    'smtp_pass' => 'PPn0R3p1@y-25',

    'mail_debug' => false,
    'mail_debug_log' => __DIR__ . '/../../storage/logs/mail.log',
    'smtp_debug_level' => 0,
];

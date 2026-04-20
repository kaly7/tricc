<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * E-mail küldéshez használt beállítások.
 * A külsős átadások értesítő e-mailjei ezt a konfigurációt használják.
 */

return [
    // Feladó cím
    'mail_from' => 'noreply@perfect-phone.hu',
    'mail_from_name' => 'Perfect-Phone / Raktárkezelő',

    // Opcionális másolat
    'mail_bcc' => 'kalamar.janos@gmail.com',

    // Ha smtp_host üres, PHPMailer a helyi mail() transzportot használja.
    'smtp_host' => 'mail.t-online.hu',
    'smtp_port' => 587,
    'smtp_secure' => 'tls', // tls | ssl | ''
    'smtp_user' => 'noreply@perfect-phone.hu',
    'smtp_pass' => 'PPn0R3p1@y-25',

    // Opcionális SMTP extra beállítások
    // 'smtp_options' => [
    //     'ssl' => [
    //         'verify_peer' => false,
    //         'verify_peer_name' => false,
    //         'allow_self_signed' => true,
    //     ],
    // ],

    // Hibakeresési log
    'mail_debug' => false,
    'mail_debug_log' => __DIR__ . '/../../storage/logs/mail.log',
    'smtp_debug_level' => 0,
];

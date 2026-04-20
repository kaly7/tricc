<?php
return [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'pp_system', // <-- cseréld a sajátodra
    'user' => 'ppdb',
    'pass' => 'abrakadabra'
  ]
];
// --- E-mail küldés: engedjük-e, hogy ugyanazt a sablont többször is elküldjük ugyanarra a rekordra?
// teszthez: true, élesben: false
define('MAIL_ALLOW_DUPLICATE_SENDS', ' true');
define('SMTP_HOST', 'mail.t-online.hu');
define('SMTP_PORT',587);
define('SMTP_USER','noreplay@perfect-phone.hu');
define('SMTP_PASS','PPn0R3p1@y-25');
define('SMTP_SECURE', 'tls'); 
define('SMTP_FROM', 'noreplay@perfect-phone.hu');
define('SMTP_FROM_NAME','PP rendszer');
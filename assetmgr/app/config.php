<?php
declare(strict_types=1);

return [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'assetmgr_db',
    'user' => 'ppdb',
    'pass' => 'abrakadabra',
    'charset' => 'utf8mb4',
  ],

  // IMPORTANT: must match the Auth Center's shared session name
  'session_name' => 'ASSETMGR_SESSID',

  // Auth Center base (port)
  'auth_center_port' => 90,

  // Module slug used in Auth Center RBAC
  'module_slug' => 'assetmgr',

  'base_path' => '',
  'app_name' => 'Eszköz nyilvántartó',


'hr' => [
  'host' => '127.0.0.1',
  'name' => 'hr',
  'user' => 'ppdb',
  'pass' => 'abrakadabra', // TODO: állítsd a valós jelszóra, ha eltér
  'charset' => 'utf8mb4',
],
  // Email küldés beállítások (külsős átadás PDF)
  // Fontos: a PHP mail() függvényhez a szerveren működő MTA kell (sendmail/postfix).
  'mail_from' => 'noreply@perfect-phone.hu',
  // Másolat (BCC) erre a címre (opcionális):
  'mail_bcc'  => 'kalamar.janos@gmail.com',
  // SMTP beállítások (PHPMailer). Ha smtp_host üres, PHPMailer mail() módon küld.
  'smtp_host' => 'mail.t-online.hu',
  'smtp_port' => 587,
  // 'tls' vagy 'ssl' vagy ''
  'smtp_secure' => 'tls',
  'smtp_user' => 'noreply@perfect-phone.hu',
  'smtp_pass' => 'PPn0R3p1@y-25',
  // Opcionális: belsős/önaláírt cert esetén
  // 'smtp_options' => [
  //   'ssl' => [
  //     'verify_peer' => false,
  //     'verify_peer_name' => false,
  //     'allow_self_signed' => true,
  //   ]
  // ],

  'mail_from_name' => 'Perfect-Phone',

  // Szerszámkönyv automatikus továbbítása (ha üres, nincs automatikus küldés)
  'toolbook_central_email' => '',

  // Mail debug (PHPMailer) - ha nem jön meg a levél, kapcsold be ideiglenesen.
  // A log alapértelmezésben ide íródik: storage/logs/mail.log
  'mail_debug' => true,
  'mail_debug_log' => __DIR__ . '/../storage/logs/mail.log',
  // 0=off, 1=client messages, 2=client+server, 3=verbose, 4=low-level
  'smtp_debug_level' => 2,

];

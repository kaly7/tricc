<?php
declare(strict_types=1);

return [
  'db' => [
    'host'    => '127.0.0.1',
    'name'    => 'vehiclemgr_db',
    'user'    => 'ppdb',
    'pass'    => 'abrakadabra',
    'charset' => 'utf8mb4',
  ],

  'hr' => [
    'host'    => '127.0.0.1',
    'name'    => 'hr',
    'user'    => 'ppdb',
    'pass'    => 'abrakadabra',
    'charset' => 'utf8mb4',
  ],

  'projectmgr' => [
    'host'    => '127.0.0.1',
    'name'    => 'projectmgr',
    'user'    => 'ppdb',
    'pass'    => 'abrakadabra',
    'charset' => 'utf8mb4',
  ],

  'session_name'     => 'VEHICLEMGR_SESSID',
  'auth_center_port' => 90,
  'module_slug'      => 'vehiclemgr',
  'base_path'        => '',
  'app_name'         => 'Jármű nyilvántartó',

  'mail_from'      => 'noreply@perfect-phone.hu',
  'mail_from_name' => 'Perfect-Phone',
  'mail_bcc'       => '',
  'smtp_host'      => 'mail.t-online.hu',
  'smtp_port'      => 587,
  'smtp_secure'    => 'tls',
  'smtp_user'      => 'noreply@perfect-phone.hu',
  'smtp_pass'      => 'PPn0R3p1@y-25',
  'mail_debug'     => false,
  'mail_debug_log' => __DIR__ . '/../storage/logs/mail.log',
  'smtp_debug_level' => 0,
];

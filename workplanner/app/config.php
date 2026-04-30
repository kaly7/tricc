<?php
declare(strict_types=1);

return [
  'db' => [
    'dsn'     => 'mysql:host=127.0.0.1;dbname=workplanner_db;charset=utf8mb4',
    'user'    => 'ppdb',
    'pass'    => 'abrakadabra',
  ],
  'hr' => [
    'dsn'     => 'mysql:host=127.0.0.1;dbname=hr;charset=utf8mb4',
    'user'    => 'ppdb',
    'pass'    => 'abrakadabra',
  ],

  'session_name'     => 'FEJLESZTES_SESSID',
  'auth_center_port' => 90,
  'module_slug'      => 'workplanner',
  'app_name'         => 'Napiterv',
  'base_path'        => '',

  'mail_from'        => 'noreply@perfect-phone.hu',
  'mail_from_name'   => 'Perfect-Phone Napiterv',
  'smtp_host'        => 'mail.t-online.hu',
  'smtp_port'        => 587,
  'smtp_secure'      => 'tls',
  'smtp_user'        => 'noreply@perfect-phone.hu',
  'smtp_pass'        => 'PPn0R3p1@y-25',
];

<?php
declare(strict_types=1);

return [
  // DB is still used by the app modules (manufacturers, etc.)
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'pbxreg',
    'user' => 'ppdb',
    'pass' => 'abrakadabra',
    'charset' => 'utf8mb4',
  ],

  // IMPORTANT: must match the Auth Center's shared session name
  'session_name' => 'FEJLESZTES_SESSID',

  // Auth Center base (port)
  'auth_center_port' => 90,

  // Module slug used in Auth Center RBAC (keep 'pbx' if that's what you already use there)
  'module_slug' => 'pbx',

  'base_path' => '',
  'app_name' => 'Központok',
];

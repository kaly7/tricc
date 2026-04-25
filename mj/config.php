<?php
return [
  // CentralAuth az 'db' kulcs alatt várja az auth adatbázist
  'db' => [
    'dsn'  => 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4',
    'user' => 'ppdb',
    'pass' => 'abrakadabra',
  ],
  // Alkalmazás saját adatbázisa
  'app_db' => [
    'host' => '127.0.0.1',
    'name' => 'mj_ajanlat',
    'user' => 'ppdb',
    'pass' => 'abrakadabra',
  ],
  'auth_port'    => 90,
  'session_name' => 'FEJLESZTES_SESSID',
  'module_key'   => 'mj',
  'app_name'     => 'MJ-Ajánlat-PKS',
];

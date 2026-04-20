<?php
/**
 * warehousemgr kommentelt forrás
 * Alap alkalmazáskonfiguráció a warehousemgr modulhoz.
 * Itt vannak az adatbázis, auth, session és modul-azonosító beállítások.
 */
return [
    // IMPORTANT: CentralAuth expects the auth DB under the 'db' key
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4',
        'user' => 'ppdb',
        'pass' => 'abrakadabra',
    ],
    'app_db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=warehousemgr;charset=utf8mb4',
        'user' => 'ppdb',
        'pass' => 'abrakadabra',
    ],
    'hr' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=hr;charset=utf8mb4',
        'user' => 'ppdb',
        'pass' => 'abrakadabra',
    ],
    'auth_port'    => 90,
    'session_name' => 'FEJLESZTES_SESSID',
    'module_key'   => 'warehousemgr',
    'app_name'     => 'Raktárkezelő',
];

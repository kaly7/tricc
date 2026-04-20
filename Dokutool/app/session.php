<?php
// app/session.php — startolt, biztonságosabb session beállítások (shared across modules)
if (session_status() !== PHP_SESSION_ACTIVE) {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

  // Shared session name across all modules
  session_name('FEJLESZTES_SESSID');

  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

<?php
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $cfg = require __DIR__.'/config.php';
  $c   = $cfg['app_db'];
  $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['name']);
  $pdo = new PDO($dsn, $c['user'], $c['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

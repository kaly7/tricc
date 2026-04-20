<?php
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $cfg = require __DIR__.'/config.php';
  $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['db']['host'], $cfg['db']['name']);
  $pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

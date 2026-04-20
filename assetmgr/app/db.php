<?php
declare(strict_types=1);

function config(): array {
  static $cfg = null;
  if ($cfg === null) $cfg = require __DIR__.'/config.php';
  return $cfg;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = config()['db'];
  $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";
  $pdo = new PDO($dsn, $c['user'], $c['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}


function db_hr(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $cfg = config();
  if (!isset($cfg['hr'])) {
    throw new RuntimeException('hr config hiányzik (app/config.php)');
  }
  $d = $cfg['hr'];

  $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";
  $pdo = new PDO($dsn, $d['user'], $d['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  return $pdo;
}

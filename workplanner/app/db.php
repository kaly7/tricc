<?php
declare(strict_types=1);

function config(): array {
  static $cfg = null;
  if ($cfg === null) $cfg = require __DIR__ . '/config.php';
  return $cfg;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  $c = config()['db'];
  $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function db_hr(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  $c = config()['hr'];
  $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

<?php
declare(strict_types=1);

function auth_pdo(array $config): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

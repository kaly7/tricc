<?php
class Db
{
  private PDO $pdo;

  public function __construct()
  {
    $dsn = sprintf(
      'mysql:host=%s;dbname=%s;charset=%s',
      DB_HOST,
      DB_NAME,
      DB_CHARSET
    );

    $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }

  public function pdo(): PDO
  {
    return $this->pdo;
  }
}
<?php
namespace App;

use PDO;

class Db {
  private static ?PDO $pdo = null;

  public static function pdo(): PDO {
    if (!self::$pdo) {
      $cfg = require dirname(__DIR__).'/config/config.php';
      self::$pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], $cfg['db']['options']);
    }
    return self::$pdo;
  }
}

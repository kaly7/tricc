<?php
namespace Tricc;

class DB {
    private static ?\PDO $pdo = null;

    public static function get(): \PDO {
        if (self::$pdo === null) {
            $cfg = require __DIR__ . '/../../config.php';
            self::$pdo = new \PDO(
                "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
                $cfg['db_user'], $cfg['db_pass'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                 \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
            );
        }
        return self::$pdo;
    }
}

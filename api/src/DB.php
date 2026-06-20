<?php
namespace Tricc;

class DB {
    private static ?\PDO $pdo = null;
    private static float $lastUsed = 0.0;

    public static function get(): \PDO {
        // Ping if idle 30+ min — guards against MariaDB wait_timeout in long-running daemons
        if (self::$pdo !== null && (microtime(true) - self::$lastUsed) > 1800) {
            try {
                self::$pdo->query('SELECT 1');
            } catch (\PDOException) {
                self::$pdo = null;
            }
        }
        if (self::$pdo === null) {
            $cfg = require __DIR__ . '/../../config.php';
            self::$pdo = new \PDO(
                "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
                $cfg['db_user'], $cfg['db_pass'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                 \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
            );
        }
        self::$lastUsed = microtime(true);
        return self::$pdo;
    }
}

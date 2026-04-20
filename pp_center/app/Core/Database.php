<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = cfg('db.host');
        $port = (int) cfg('db.port', 3306);
        $db   = cfg('db.database');
        $user = cfg('db.username');
        $pass = cfg('db.password');
        $charset = cfg('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, (string) $user, (string) $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $timezone = (string) cfg('app.timezone', 'Europe/Budapest');
            $tz = new \DateTimeZone($timezone);
            $offset = $tz->getOffset(new \DateTimeImmutable('now', $tz));
            $sign = $offset >= 0 ? '+' : '-';
            $offset = abs($offset);
            $hours = str_pad((string) intdiv($offset, 3600), 2, '0', STR_PAD_LEFT);
            $minutes = str_pad((string) intdiv($offset % 3600, 60), 2, '0', STR_PAD_LEFT);
            $mysqlTz = sprintf('%s%s:%s', $sign, $hours, $minutes);
            self::$pdo->exec("SET time_zone = '" . $mysqlTz . "'");
        } catch (PDOException $e) {
            throw new PDOException('Adatbázis kapcsolat sikertelen: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return self::$pdo;
    }
}

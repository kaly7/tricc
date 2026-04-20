<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Adatbázis kapcsolatok.
 * Külön PDO kapcsolat készül az alkalmazás saját és az opcionális HR adatbázishoz.
 */

/**
 * Alkalmazás adatbázis kapcsolat.
 * Statikus cache-t használunk, hogy egy kérésen belül csak egyszer nyissunk PDO-t.
 */
function warehouse_pdo(array $config): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO(
        $config['app_db']['dsn'],
        $config['app_db']['user'],
        $config['app_db']['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    return $pdo;
}

/**
 * Opcionális HR adatbázis kapcsolat.
 * Hiba esetén null-lal térünk vissza, mert ez a kapcsolat nem kötelező a modul működéséhez.
 */
function warehouse_hr_pdo(array $config): ?PDO {
    static $pdo = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($pdo === null) {
        return null;
    }
    try {
        $pdo = new PDO(
            $config['hr']['dsn'],
            $config['hr']['user'],
            $config['hr']['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        return $pdo;
    } catch (Throwable $e) {
        $pdo = null;
        return null;
    }
}

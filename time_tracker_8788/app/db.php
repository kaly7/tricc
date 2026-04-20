<?php
declare(strict_types=1);

function tracker_app_pdo(array $config): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = $config['app_db'] ?? null;
    if (!$cfg || empty($cfg['dsn'])) {
        throw new RuntimeException('Hiányzik az app_db konfiguráció.');
    }
    $pdo = new PDO($cfg['dsn'], $cfg['user'] ?? '', $cfg['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function tracker_auth_pdo(array $config): PDO {
    return auth_pdo($config);
}

function tracker_hr_pdo(array $config): ?PDO {
    static $pdo = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($pdo === false) {
        $cfg = $config['hr'] ?? null;
        if (!$cfg || empty($cfg['dsn'])) {
            $pdo = null;
            return null;
        }
        try {
            $pdo = new PDO($cfg['dsn'], $cfg['user'] ?? '', $cfg['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            $pdo = null;
        }
    }
    return $pdo instanceof PDO ? $pdo : null;
}

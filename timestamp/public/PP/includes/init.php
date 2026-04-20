<?php
declare(strict_types=1);
session_start();
$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo "Adatbázis kapcsolódási hiba.";
    exit;
}
require __DIR__ . '/functions.php';
require __DIR__ . '/audit.php';
$settings = load_settings($pdo);

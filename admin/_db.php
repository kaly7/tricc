<?php
function tricc_db(): PDO {
    static $pdo;
    if (!$pdo) {
        $cfg = require __DIR__ . '/../config.php';
        $pdo = new PDO(
            "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
            $cfg['db_user'], $cfg['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function tricc_auth(): void {
    session_start();
    if (!isset($_SESSION['tricc_admin'])) {
        header('Location: login.php'); exit;
    }
}

function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function get_flash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

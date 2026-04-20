<?php
/**
 * Payslip Auth adapter for Auth Center (central login + RBAC).
 *
 * - Keeps the original Auth API used across the payslip codebase.
 * - Uses the shared central session (FEJLESZTES_SESSID).
 * - Checks module permission: module_key = 'payslip'
 *
 * IMPORTANT:
 * - Set the ppdb password in $cfg['db']['pass'] below (same as auth_center).
 */
class Auth {
    private static array $cfg = [
        'db' => [
            'dsn'  => 'mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4',
            'user' => 'ppdb',
            'pass' => 'abrakadabra',
        ],
        'auth_port'    => 90,
        'session_name' => 'FEJLESZTES_SESSID',
        'module_key'   => 'payslip',
    ];

    private static bool $booted = false;

    private static function boot(): void {
        if (self::$booted) return;

        require_once '/var/www/html/_common/auth/db.php';
        require_once '/var/www/html/_common/auth/url.php';
        require_once '/var/www/html/_common/auth/Auth.php'; // CentralAuth class

        self::$booted = true;
    }

    public static function start(): void {
        self::boot();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name(self::$cfg['session_name']);
            session_start();
        }

        // Compatibility: expose a "user" array like the old payslip auth did
        if (!isset($_SESSION['user']) && CentralAuth::userId() !== null) {
            $_SESSION['user'] = [
                'id' => (int)CentralAuth::userId(),
                // display full name; keep key as 'username' for backward compatibility in templates
                'username' => (string)($_SESSION['full_name'] ?? ''),
                'role' => (string)(CentralAuth::roleForModule(self::$cfg, 'payslip') ?? ''),
            ];
        }
    }

    public static function requireLogin(): void {
        self::start();

        // Not logged in centrally -> go to Auth Center login, then module selector
        if (CentralAuth::userId() === null) {
            header('Location: ' . build_url(self::$cfg['auth_port'], '/login.php?return=' . urlencode('/apps.php')));
            exit;
        }

        // Logged in, but no permission for payslip
        $role = CentralAuth::roleForModule(self::$cfg, 'payslip');
        if ($role === null || $role === '') {
            http_response_code(403);
            echo "403 - Nincs jogosultság a Payslip modulhoz.";
            exit;
        }

        // Refresh compat user role
        $_SESSION['user']['role'] = (string)$role;
    }

    // Payslip local login is disabled (Auth Center handles it)
    public static function login(string $username, string $password): bool {
        self::start();
        return false;
    }

    /**
     * In-module "logout" should NOT destroy the central session.
     * It sends the user back to the Auth Center module selector.
     * Real logout is on Auth Center: :90/logout.php
     */
    public static function logout(): void {
        self::start();
        header('Location: ' . build_url(self::$cfg['auth_port'], '/apps.php'));
        exit;
    }

    public static function isAdmin(): bool {
        self::start();
        return (CentralAuth::roleForModule(self::$cfg, 'payslip') === 'admin');
    }
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_logged_in(): bool { return isset($_SESSION['user']); }
function is_admin(): bool { return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin'; }

function require_login(): void {
    if (!is_logged_in()) { header('Location: /login.php'); exit; }
}
function require_admin(): void {
    if (!is_admin()) { http_response_code(403); echo '<p>Hozzáférés megtagadva.</p>'; exit; }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['csrf_token'] ?? '';
        if (!$t || !hash_equals($_SESSION['csrf_token'] ?? '', $t)) { http_response_code(400); echo '<p>Érvénytelen űrlap (CSRF).</p>'; exit; }
    }
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $path): void { header("Location: $path"); exit; }

function first_admin_exists(): bool {
    $c = (int)db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
    return $c > 0;
}
function is_locked(string $date): bool {
    $stmt = db()->prepare("SELECT COUNT(*) c FROM lock_intervals WHERE :d BETWEEN start_date AND end_date");
    $stmt->execute([':d'=>$date]);
    return (int)$stmt->fetch()['c'] > 0;
}
function day_name(int $w): string {
    $names=[1=>'H',2=>'K',3=>'Sze',4=>'Cs',5=>'P',6=>'Szo',7=>'V']; return $names[$w] ?? (string)$w;
}
function parse_date(string $s): ?string { $ts=strtotime($s); return $ts?date('Y-m-d',$ts):null; }

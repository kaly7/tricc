<?php
declare(strict_types=1);

function base_url(): string {
    $cfg = require __DIR__ . '/../config.php';
    return rtrim($cfg['app']['base_url'] ?? '/', '/') . '/';
}

function redirect(string $path): never {
    header("Location: " . base_url() . ltrim($path, '/'));
    exit;
}

// CSRF
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf" value="'.$t.'">';
}
function csrf_verify(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) {
        http_response_code(400);
        echo "Érvénytelen CSRF token.";
        exit;
    }
}

// Auth
function current_user(PDO $pdo): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user !== null) return $user;
    $stmt = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user && !$user['is_active']) {
        session_destroy();
        return null;
    }
    return $user;
}
function require_login(PDO $pdo): void {
    if (!current_user($pdo)) redirect('login.php');
}
function is_admin(PDO $pdo): bool {
    $u = current_user($pdo);
    return $u && $u['role'] === 'admin';
}

// Settings
function load_settings(PDO $pdo): array {
    $stmt = $pdo->query("SELECT skey, svalue FROM settings");
    $out = [
        'color_overdue' => '#ffdddd',
        'color_due_soon' => '#fff3cd',
        'color_ok' => '#ddffdd',
        'due_soon_days' => '7',
    ];
    foreach ($stmt as $row) { $out[$row['skey']] = $row['svalue']; }
    return $out;
}

// Options
function get_pp_statuses(PDO $pdo): array {
    return $pdo->query("SELECT id, name FROM pp_statuses WHERE is_active = 1 ORDER BY name")->fetchAll();
}
function get_cities(PDO $pdo): array {
    return $pdo->query("SELECT id, name FROM cities WHERE is_active = 1 ORDER BY name")->fetchAll();
}

// Határidő + szín
function effective_deadline(?string $kiadva, ?string $vallalt): ?string {
    if (!$kiadva && !$vallalt) return null;
    $calc = $kiadva ? (new DateTime($kiadva))->modify('+38 day') : null;
    if ($vallalt) {
        $v = new DateTime($vallalt);
        if ($calc) return ($v < $calc ? $v : $calc)->format('Y-m-d');
        return $v->format('Y-m-d');
    }
    return $calc ? $calc->format('Y-m-d') : null;
}
function row_color(array $row, array $settings): string {
    $deadline = effective_deadline($row['kiadva'] ?? null, $row['vallalt_hatarido'] ?? null);
    if (!$deadline) return '';
    $today = new DateTimeImmutable('today');
    $dl = new DateTimeImmutable($deadline);
    $diff = (int)$today->diff($dl)->format('%r%a');
    $dueSoon = (int)($settings['due_soon_days'] ?? 7);
    if ($diff < 0) return $settings['color_overdue'] ?? '#ffdddd';
    if ($diff <= $dueSoon) return $settings['color_due_soon'] ?? '#fff3cd';
    return $settings['color_ok'] ?? '#ddffdd';
}


function get_field_note(PDO $pdo, int $task_id, string $field): ?array {
    $st=$pdo->prepare("SELECT * FROM task_field_notes WHERE task_id=? AND field_name=?");
    $st->execute([$task_id,$field]);
    $r=$st->fetch(); return $r?:null;
}

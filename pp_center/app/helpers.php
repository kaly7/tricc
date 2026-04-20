<?php

function cfg(string $key = null, mixed $default = null): mixed
{
    global $config;

    if ($key === null) {
        return $config;
    }

    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) cfg('app.base_url', ''), '/');
    $path = ltrim($path, '/');

    return $base === '' ? '/' . $path : $base . '/' . $path;
}

function auth_center_url(string $path = '/apps.php'): string
{
    $path = '/' . ltrim($path, '/');

    $base = (string) cfg('auth_center.base_url', '');
    if ($base !== '') {
        return rtrim($base, '/') . $path;
    }

    $port = (int) cfg('auth_center.auth_port', 90);
    $host = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $host = preg_replace('~:\d+$~', '', (string) $host);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return sprintf('%s://%s:%d%s', $scheme, $host, $port, $path);
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function device_status_badge(bool $online): string
{
    return device_online_badge($online);
}


function flash_set(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['pp_center_flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $flash = $_SESSION['pp_center_flash'] ?? null;
    unset($_SESSION['pp_center_flash']);
    return $flash;
}

function current_user_name(): string
{
    return $_SESSION['full_name']
        ?? $_SESSION['username']
        ?? $_SESSION['user']['name']
        ?? $_SESSION['user']['full_name']
        ?? $_SESSION['user']['username']
        ?? $_SESSION['auth_user']['name']
        ?? $_SESSION['auth_user']['username']
        ?? $_SESSION['auth_user']['email']
        ?? '';
}

function device_online_badge(bool $online): string
{
    if ($online) {
        return '<span class="badge-status status-online">Online</span>';
    }
    return '<span class="badge-status status-offline">Offline</span>';
}

function command_status_badge(string $status): string
{
    $status = strtolower(trim($status));
    $class = match ($status) {
        'queued' => 'status-warn',
        'sent' => 'status-info',
        'acked' => 'status-online',
        'failed' => 'status-offline',
        default => 'status-info',
    };
    return '<span class="badge-status ' . $class . '">' . e(ucfirst($status)) . '</span>';
}

function bridge_status_badge(string $status): string
{
    $normalized = strtolower(trim($status));
    $class = match ($normalized) {
        'running' => 'status-online',
        'starting', 'connecting' => 'status-info',
        'error', 'stopped' => 'status-offline',
        default => 'status-warn',
    };
    $label = match ($normalized) {
        'running' => 'Fut',
        'starting' => 'Indul',
        'connecting' => 'Kapcsolódik',
        'error' => 'Hiba',
        'stopped' => 'Leállt',
        default => ucfirst($normalized ?: 'ismeretlen'),
    };
    return '<span class="badge-status ' . $class . '">' . e($label) . '</span>';
}



function time_select_hour_options(): array
{
    return range(0, 23);
}

function time_select_minute_options(): array
{
    return [0, 10, 20, 30, 40, 50];
}

function build_datetime_filter(?string $date, mixed $hour, mixed $minute, bool $endOfBucket = false): ?string
{
    $date = trim((string) ($date ?? ''));
    if ($date === '') {
        return null;
    }

    $hourValue = (int) $hour;
    $minuteValue = (int) $minute;
    if ($hourValue < 0 || $hourValue > 23) {
        $hourValue = 0;
    }
    if (!in_array($minuteValue, time_select_minute_options(), true)) {
        $minuteValue = 0;
    }

    $seconds = $endOfBucket ? 59 : 0;
    return sprintf('%s %02d:%02d:%02d', $date, $hourValue, $minuteValue, $seconds);
}

function per_page_options(): array
{
    return [20, 50, 100];
}

function resolve_per_page(mixed $value, int $default = 20): int
{
    $perPage = (int) $value;
    return in_array($perPage, per_page_options(), true) ? $perPage : $default;
}

function resolve_page(mixed $value): int
{
    $page = (int) $value;
    return $page > 0 ? $page : 1;
}

function pagination_base_query(array $overrides = []): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return http_build_query($query);
}

function render_pagination(int $page, int $perPage, int $total, string $basePath, string $pageParam = 'page', array $extraOverrides = []): string
{
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    if ($totalPages <= 1) {
        return '';
    }

    $prev = $page > 1 ? $page - 1 : 1;
    $next = $page < $totalPages ? $page + 1 : $totalPages;
    $html = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">';
    $html .= '<div class="muted small">Oldal ' . $page . ' / ' . $totalPages . ' · összesen ' . $total . ' tétel</div>';
    $html .= '<div class="d-flex gap-2">';
    if ($page > 1) {
        $query = pagination_base_query(array_merge($extraOverrides, [$pageParam => $prev, 'per_page' => $perPage]));
        $html .= '<a class="btn btn-outline-secondary btn-sm" href="' . e(app_url($basePath . '?' . $query)) . '">Előző</a>';
    }
    if ($page < $totalPages) {
        $query = pagination_base_query(array_merge($extraOverrides, [$pageParam => $next, 'per_page' => $perPage]));
        $html .= '<a class="btn btn-outline-secondary btn-sm" href="' . e(app_url($basePath . '?' . $query)) . '">Következő</a>';
    }
    $html .= '</div></div>';
    return $html;
}

function pretty_json(?string $json): string
{
    if ($json === null || trim($json) === '') {
        return '{}';
    }

    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable) {
        return $json;
    }
}

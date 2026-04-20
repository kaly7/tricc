<?php
declare(strict_types=1);

function current_scheme(): string {
  $https = $_SERVER['HTTPS'] ?? '';
  return (!empty($https) && $https !== 'off') ? 'https' : 'http';
}

function current_host_no_port(): string {
  $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return explode(':', $host)[0];
}

function current_port(): int {
  return (int)($_SERVER['SERVER_PORT'] ?? 80);
}

function current_full_url(): string {
  $scheme = current_scheme();
  $host = current_host_no_port();
  $port = current_port();
  $uri  = $_SERVER['REQUEST_URI'] ?? '/';
  if ($uri === '') $uri = '/';
  if ($uri[0] !== '/') $uri = '/' . $uri;
  return $scheme . '://' . $host . ':' . $port . $uri;
}

function build_url(int $port, string $path = '/'): string {
  $scheme = current_scheme();
  $host = current_host_no_port();
  if ($path === '') $path = '/';
  if ($path[0] !== '/') $path = '/' . $path;
  return $scheme . '://' . $host . ':' . $port . $path;
}

/**
 * Safe return target for login redirect.
 * Allowed:
 *  - absolute URL with same host (any port), e.g. http://192.168.16.22:86/employees
 *  - absolute path starting with /
 */
function safe_return_url(string $value, string $fallback = '/apps.php'): string {
  $value = trim($value);
  if ($value === '') return $fallback;

  // Absolute path (within current host+port)
  if ($value[0] === '/') {
    if (str_starts_with($value, '//')) return $fallback;
    return $value;
  }

  // Absolute URL: allow only same host
  $parts = @parse_url($value);
  if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
    $host = (string)$parts['host'];
    if (strcasecmp($host, current_host_no_port()) !== 0) return $fallback;

    $path = $parts['path'] ?? '/';
    if ($path === '') $path = '/';
    if ($path[0] !== '/') $path = '/' . $path;

    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
    $fragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';

    $port = isset($parts['port']) ? (int)$parts['port'] : null;

    $scheme = (string)$parts['scheme'];
    $base = $scheme . '://' . $host . ($port ? (':' . $port) : '');
    return $base . $path . $query . $fragment;
  }

  return $fallback;
}

function auth_login_url(array $config, string $returnTarget = '/apps.php'): string {
  $authPort = (int)$config['auth_port'];
  $returnTarget = safe_return_url($returnTarget, '/apps.php');
  return build_url($authPort, '/login.php?return=' . urlencode($returnTarget));
}

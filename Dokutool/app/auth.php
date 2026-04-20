<?php
// app/auth.php — jelszókezelés + auth segédek

require_once __DIR__ . '/session.php';

function hash_password(string $plain): string {
  return password_hash($plain, PASSWORD_DEFAULT);
}
function verify_password(string $plain, string $hash): bool {
  return password_verify($plain, $hash);
}

// DB helpers
function find_user_by_username(PDO $pdo, string $username): ?array {
  $st = $pdo->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
  $st->execute([':u'=>$username]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function find_user_by_id(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
  $st->execute([':id'=>$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

// Session helpers
function login_user(array $user): void {
  session_regenerate_id(true);
  $_SESSION['user_id'] = (int)$user['id'];
  $_SESSION['username'] = (string)$user['username'];
  $_SESSION['login_at'] = time();
}
function logout_user(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
}
function current_user(PDO $pdo): ?array {
  if (!empty($_SESSION['user_id'])) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = find_user_by_id($pdo, (int)$_SESSION['user_id']);
    return $cached ?: null;
  }
  return null;
}

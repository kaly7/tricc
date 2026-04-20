<?php
namespace App;

class Csrf {
  public static function token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
  }

  public static function check(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
  }

  public static function field(): string {
    return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(self::token(),ENT_QUOTES).'">';
  }
}

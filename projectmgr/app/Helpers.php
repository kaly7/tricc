<?php
namespace App;

class Helpers {
  public static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES,'UTF-8'); }
  public static function flash(string $key, ?string $val=null): ?string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if ($val !== null) { $_SESSION['_flash'][$key] = $val; return null; }
    $v = $_SESSION['_flash'][$key] ?? null; unset($_SESSION['_flash'][$key]); return $v;
  }
}

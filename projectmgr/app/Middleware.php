<?php
namespace App;

class Middleware {
  public static function requireAuth(): void {
    if (!Auth::check()) {
      header('Location: /login.php');
      exit;
    }
  }
}

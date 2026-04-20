<?php
class Flash
{
  public function set(string $key, string $msg): void
  {
    $_SESSION['_flash'][$key] = $msg;
  }

  public function get(string $key): ?string
  {
    if (!isset($_SESSION['_flash'][$key])) return null;
    $msg = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $msg;
  }
}
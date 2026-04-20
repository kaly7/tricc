<?php
namespace App;

class Logger {
  public static function write(string $msg): void {
    $cfg = require dirname(__DIR__).'/config/config.php';
    $dir = $cfg['log_dir'] ?? (dirname(__DIR__).'/storage/logs');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $line = '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL;
    @file_put_contents($dir.'/app.log', $line, FILE_APPEND);
  }
}

<?php
namespace App;

use App\Db;

class Activity {
  public static function log(int $project_id, ?int $user_id, string $action, $details = null): void {
    try {
      $pdo = Db::pdo();
      $ip = $_SERVER['REMOTE_ADDR'] ?? null;
      $json = is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$details;
      $st = $pdo->prepare('INSERT INTO project_activity (project_id,user_id,action,details,ip) VALUES (?,?,?,?,?)');
      $st->execute([$project_id, $user_id, $action, $json, $ip]);
    } catch (\Throwable $e) {
      // swallow logging errors to not break UX
    }
  }
}

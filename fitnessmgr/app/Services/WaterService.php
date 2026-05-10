<?php
declare(strict_types=1);

class WaterService {

  public function addLog(int $userId, int $amountMl, string $date, ?string $note = null): void {
    $st = db()->prepare("INSERT INTO water_logs (user_id, amount_ml, logged_at, note) VALUES (?, ?, ?, ?)");
    $st->execute([$userId, $amountMl, $date, $note]);
  }

  public function getDayTotal(int $userId, string $date): int {
    $st = db()->prepare("SELECT COALESCE(SUM(amount_ml), 0) FROM water_logs WHERE user_id = ? AND logged_at = ?");
    $st->execute([$userId, $date]);
    return (int)$st->fetchColumn();
  }

  public function getDayLogs(int $userId, string $date): array {
    $st = db()->prepare("SELECT * FROM water_logs WHERE user_id = ? AND logged_at = ? ORDER BY created_at");
    $st->execute([$userId, $date]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  public function getGoal(int $userId): int {
    $st = db()->prepare("SELECT water_goal_ml FROM user_profile WHERE user_id = ?");
    $st->execute([$userId]);
    return (int)($st->fetchColumn() ?: 2500);
  }

  public function getLastLogTime(int $userId): ?string {
    $st = db()->prepare("SELECT MAX(created_at) FROM water_logs WHERE user_id = ?");
    $st->execute([$userId]);
    $val = $st->fetchColumn();
    return $val ?: null;
  }

  public function getDaysSinceLastLog(int $userId): ?int {
    $st = db()->prepare("SELECT MAX(logged_at) FROM water_logs WHERE user_id = ?");
    $st->execute([$userId]);
    $val = $st->fetchColumn();
    if (!$val) return null;
    return (int)((time() - strtotime($val)) / 86400);
  }

  public function getHistory(int $userId, int $days = 7): array {
    $since = date('Y-m-d', strtotime("-{$days} days"));
    $st = db()->prepare("
      SELECT logged_at, SUM(amount_ml) AS total_ml
      FROM water_logs WHERE user_id = ? AND logged_at >= ?
      GROUP BY logged_at ORDER BY logged_at
    ");
    $st->execute([$userId, $since]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

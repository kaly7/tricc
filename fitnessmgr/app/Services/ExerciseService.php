<?php
declare(strict_types=1);

class ExerciseService {

  public function search(string $query, int $limit = 20): array {
    if (trim($query) === '') return [];
    $like = '%' . $query . '%';
    $st = db()->prepare("
      SELECT id, name, category, calories_per_hour, met_value
      FROM exercise_types
      WHERE name LIKE ?
      ORDER BY category, name
      LIMIT ?
    ");
    $st->execute([$like, $limit]);
    return $st->fetchAll();
  }

  public function getAll(): array {
    return db()->query("SELECT * FROM exercise_types ORDER BY category, name")->fetchAll();
  }

  public function getType(int $id): ?array {
    $st = db()->prepare("SELECT * FROM exercise_types WHERE id=? LIMIT 1");
    $st->execute([$id]);
    return $st->fetch() ?: null;
  }

  public function addEntry(int $userId, array $data): int {
    $typeId      = isset($data['exercise_type_id']) ? (int)$data['exercise_type_id'] : null;
    $durationMin = max(1, (int)($data['duration_min'] ?? 30));
    $doneAt      = $data['done_at'] ?? today();
    $notes       = trim($data['notes'] ?? '');
    $customName  = null;
    $burned      = 0;

    if ($typeId) {
      $type = $this->getType($typeId);
      if ($type) {
        $burned = (int)round($type['calories_per_hour'] * $durationMin / 60);
      }
    } else {
      $customName = trim($data['custom_exercise_name'] ?? '');
      $burned     = (int)($data['calories_burned'] ?? 0);
    }

    $st = db()->prepare("
      INSERT INTO exercise_entries
        (user_id, exercise_type_id, custom_exercise_name, duration_min, calories_burned, done_at, notes)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
      $userId, $typeId, $customName ?: null, $durationMin,
      $burned, $doneAt, $notes ?: null,
    ]);
    return (int)db()->lastInsertId();
  }

  public function deleteEntry(int $id, int $userId): bool {
    $st = db()->prepare("DELETE FROM exercise_entries WHERE id=? AND user_id=?");
    $st->execute([$id, $userId]);
    return $st->rowCount() > 0;
  }

  public function getDayEntries(int $userId, string $date): array {
    $st = db()->prepare("
      SELECT ee.*, et.name AS type_name, et.category
      FROM exercise_entries ee
      LEFT JOIN exercise_types et ON et.id = ee.exercise_type_id
      WHERE ee.user_id=? AND ee.done_at=?
      ORDER BY ee.id
    ");
    $st->execute([$userId, $date]);
    return $st->fetchAll();
  }

  public function getDayTotals(int $userId, string $date): array {
    $st = db()->prepare("
      SELECT
        COALESCE(SUM(duration_min), 0)    AS duration_min,
        COALESCE(SUM(calories_burned), 0) AS calories_burned
      FROM exercise_entries
      WHERE user_id=? AND done_at=?
    ");
    $st->execute([$userId, $date]);
    return $st->fetch() ?: ['duration_min' => 0, 'calories_burned' => 0];
  }

  public function getHistory(int $userId, int $days = 14): array {
    $st = db()->prepare("
      SELECT done_at AS date,
             COALESCE(SUM(duration_min), 0)    AS duration_min,
             COALESCE(SUM(calories_burned), 0) AS calories_burned
      FROM exercise_entries
      WHERE user_id=? AND done_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      GROUP BY done_at
      ORDER BY done_at ASC
    ");
    $st->execute([$userId, $days]);
    return $st->fetchAll();
  }
}

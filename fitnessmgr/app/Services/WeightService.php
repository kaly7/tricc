<?php
declare(strict_types=1);

class WeightService {

  public function addLog(int $userId, float $weight, string $date, ?string $notes = null): int {
    $profile = $this->getProfile($userId);
    $bmi = null;
    if ($profile && !empty($profile['height_cm'])) {
      $bmi = calc_bmi($weight, (int)$profile['height_cm']);
    }

    $st = db()->prepare("
      INSERT INTO weight_logs (user_id, weight_kg, bmi, measured_at, notes)
      VALUES (?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE weight_kg=VALUES(weight_kg), bmi=VALUES(bmi), notes=VALUES(notes)
    ");
    $st->execute([$userId, $weight, $bmi, $date, $notes]);

    // Ha nincs today bejegyzés, az ON DUPLICATE KEY nem adott lastInsertId-t – keres vissza
    $st2 = db()->prepare("SELECT id FROM weight_logs WHERE user_id=? AND measured_at=? LIMIT 1");
    $st2->execute([$userId, $date]);
    return (int)($st2->fetchColumn() ?: db()->lastInsertId());
  }

  public function deleteLog(int $id, int $userId): bool {
    $st = db()->prepare("DELETE FROM weight_logs WHERE id=? AND user_id=?");
    $st->execute([$id, $userId]);
    return $st->rowCount() > 0;
  }

  public function getHistory(int $userId, int $days = 90): array {
    $st = db()->prepare("
      SELECT id, weight_kg, bmi, measured_at, notes
      FROM weight_logs
      WHERE user_id=? AND measured_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      ORDER BY measured_at ASC
    ");
    $st->execute([$userId, $days]);
    return $st->fetchAll();
  }

  public function getLatest(int $userId): ?array {
    $st = db()->prepare("
      SELECT weight_kg, bmi, measured_at
      FROM weight_logs
      WHERE user_id=?
      ORDER BY measured_at DESC, id DESC
      LIMIT 1
    ");
    $st->execute([$userId]);
    return $st->fetch() ?: null;
  }

  // Változás az előző méréshez képest
  public function getChange(int $userId): ?float {
    $st = db()->prepare("
      SELECT weight_kg FROM weight_logs WHERE user_id=?
      ORDER BY measured_at DESC, id DESC LIMIT 2
    ");
    $st->execute([$userId]);
    $rows = $st->fetchAll();
    if (count($rows) < 2) return null;
    return round((float)$rows[0]['weight_kg'] - (float)$rows[1]['weight_kg'], 2);
  }

  public function getProfile(int $userId): ?array {
    $st = db()->prepare("SELECT * FROM user_profile WHERE user_id=? LIMIT 1");
    $st->execute([$userId]);
    return $st->fetch() ?: null;
  }

  public function saveProfile(int $userId, array $data): void {
    $st = db()->prepare("
      INSERT INTO user_profile
        (user_id, height_cm, birth_year, gender, activity_level, target_weight_kg,
         daily_calorie_goal, protein_goal_g, carbs_goal_g, fat_goal_g, water_goal_ml,
         exercise_goal_min, mattermost_username)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        height_cm=VALUES(height_cm), birth_year=VALUES(birth_year),
        gender=VALUES(gender), activity_level=VALUES(activity_level),
        target_weight_kg=VALUES(target_weight_kg), daily_calorie_goal=VALUES(daily_calorie_goal),
        protein_goal_g=VALUES(protein_goal_g), carbs_goal_g=VALUES(carbs_goal_g),
        fat_goal_g=VALUES(fat_goal_g), water_goal_ml=VALUES(water_goal_ml),
        exercise_goal_min=VALUES(exercise_goal_min),
        mattermost_username=VALUES(mattermost_username)
    ");
    $st->execute([
      $userId,
      ($data['height_cm'] ?? null) ?: null,
      ($data['birth_year'] ?? null) ?: null,
      $data['gender']           ?? 'férfi',
      $data['activity_level']   ?? 'mérsékelt',
      ($data['target_weight_kg'] ?? null) ?: null,
      (int)($data['daily_calorie_goal'] ?? 2000),
      (int)($data['protein_goal_g']     ?? 120),
      (int)($data['carbs_goal_g']        ?? 200),
      (int)($data['fat_goal_g']          ?? 65),
      (int)($data['water_goal_ml']       ?? 2500),
      (int)($data['exercise_goal_min']   ?? 30),
      ($data['mattermost_username']       ?? null) ?: null,
    ]);
  }

  // Napi ajánlott kalória becslés (Harris-Benedict formula)
  public function recommendedCalories(array $profile, float $currentWeight): int {
    $h   = (int)($profile['height_cm'] ?? 0);
    $by  = (int)($profile['birth_year'] ?? 0);
    $gen = $profile['gender'] ?? 'férfi';
    $act = $profile['activity_level'] ?? 'mérsékelt';
    $age = $by > 0 ? (int)date('Y') - $by : 30;

    if ($h <= 0 || $currentWeight <= 0) return (int)($profile['daily_calorie_goal'] ?? 2000);

    // Harris-Benedict BMR
    if ($gen === 'nő') {
      $bmr = 447.593 + 9.247 * $currentWeight + 3.098 * $h - 4.330 * $age;
    } else {
      $bmr = 88.362 + 13.397 * $currentWeight + 4.799 * $h - 5.677 * $age;
    }

    $factor = match ($act) {
      'ülő'           => 1.2,
      'könnyű'        => 1.375,
      'mérsékelt'     => 1.55,
      'aktív'         => 1.725,
      'nagyon aktív'  => 1.9,
      default         => 1.55,
    };

    return (int)round($bmr * $factor);
  }
}

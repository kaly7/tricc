<?php
declare(strict_types=1);

class FoodService {

  public function search(string $query, int $limit = 20): array {
    if (trim($query) === '') return [];
    $norm = normalize_str($query);
    $like = '%' . $norm . '%';
    $st = db()->prepare("
      SELECT id, name, category, calories_per_100g, protein_g, carbs_g, fat_g, fiber_g, is_custom
      FROM food_items
      WHERE name_normalized LIKE ? OR name LIKE ?
      ORDER BY is_custom ASC, calories_per_100g ASC
      LIMIT ?
    ");
    $st->execute([$like, '%' . $query . '%', $limit]);
    return $st->fetchAll();
  }

  public function getItem(int $id): ?array {
    $st = db()->prepare("SELECT * FROM food_items WHERE id=? LIMIT 1");
    $st->execute([$id]);
    return $st->fetch() ?: null;
  }

  public function addEntry(int $userId, array $data): int {
    $foodItemId = isset($data['food_item_id']) ? (int)$data['food_item_id'] : null;
    $amount     = max(1, (int)($data['amount_g'] ?? 100));
    $eatenAt    = $data['eaten_at'] ?? today();
    $mealType   = $data['meal_type'] ?? 'ebed';
    $notes      = trim($data['notes'] ?? '');

    $calories = $protein = $carbs = $fat = 0.0;
    $customName = null;

    if ($foodItemId) {
      $item = $this->getItem($foodItemId);
      if ($item) {
        $calories = round($item['calories_per_100g'] * $amount / 100);
        $protein  = round($item['protein_g'] * $amount / 100, 1);
        $carbs    = round($item['carbs_g'] * $amount / 100, 1);
        $fat      = round($item['fat_g'] * $amount / 100, 1);
      }
    } else {
      $customName = trim($data['custom_food_name'] ?? '');
      $calories   = (int)($data['calories'] ?? 0);
      $protein    = (float)($data['protein_g'] ?? 0);
      $carbs      = (float)($data['carbs_g'] ?? 0);
      $fat        = (float)($data['fat_g'] ?? 0);
    }

    $st = db()->prepare("
      INSERT INTO food_entries
        (user_id, food_item_id, custom_food_name, amount_g, calories, protein_g, carbs_g, fat_g, meal_type, eaten_at, notes)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
      $userId, $foodItemId, $customName ?: null, $amount,
      (int)$calories, $protein, $carbs, $fat,
      $mealType, $eatenAt, $notes ?: null,
    ]);
    return (int)db()->lastInsertId();
  }

  public function deleteEntry(int $id, int $userId): bool {
    $st = db()->prepare("DELETE FROM food_entries WHERE id=? AND user_id=?");
    $st->execute([$id, $userId]);
    return $st->rowCount() > 0;
  }

  public function getDayEntries(int $userId, string $date): array {
    $st = db()->prepare("
      SELECT fe.*, fi.name AS food_name, fi.category
      FROM food_entries fe
      LEFT JOIN food_items fi ON fi.id = fe.food_item_id
      WHERE fe.user_id=? AND fe.eaten_at=?
      ORDER BY FIELD(fe.meal_type,'reggeli','tizorai','ebed','uzsonna','vacsora'), fe.id
    ");
    $st->execute([$userId, $date]);
    return $st->fetchAll();
  }

  public function getDayTotals(int $userId, string $date): array {
    $st = db()->prepare("
      SELECT
        COALESCE(SUM(calories), 0)  AS calories,
        COALESCE(SUM(protein_g), 0) AS protein_g,
        COALESCE(SUM(carbs_g), 0)   AS carbs_g,
        COALESCE(SUM(fat_g), 0)     AS fat_g
      FROM food_entries
      WHERE user_id=? AND eaten_at=?
    ");
    $st->execute([$userId, $date]);
    return $st->fetch() ?: ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0];
  }

  // Utolsó N nap kalória összesítő
  public function getCalorieHistory(int $userId, int $days = 14): array {
    $st = db()->prepare("
      SELECT eaten_at AS date, COALESCE(SUM(calories), 0) AS calories
      FROM food_entries
      WHERE user_id=? AND eaten_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      GROUP BY eaten_at
      ORDER BY eaten_at ASC
    ");
    $st->execute([$userId, $days]);
    return $st->fetchAll();
  }

  public function addCustomFood(int $userId, array $data): int {
    $name = trim($data['name'] ?? '');
    if ($name === '') throw new InvalidArgumentException('A név megadása kötelező.');
    $st = db()->prepare("
      INSERT INTO food_items (name, name_normalized, category, calories_per_100g, protein_g, carbs_g, fat_g, fiber_g, is_custom, created_by_user_id)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");
    $st->execute([
      $name,
      normalize_str($name),
      $data['category'] ?? 'egyéb',
      (int)($data['calories_per_100g'] ?? 0),
      (float)($data['protein_g'] ?? 0),
      (float)($data['carbs_g'] ?? 0),
      (float)($data['fat_g'] ?? 0),
      (float)($data['fiber_g'] ?? 0),
      $userId,
    ]);
    return (int)db()->lastInsertId();
  }

  // Étkezés csoportosítva étkezési típusonként egy naphoz
  public function getDayByMeal(int $userId, string $date): array {
    $entries = $this->getDayEntries($userId, $date);
    $grouped = [];
    foreach (['reggeli','tizorai','ebed','uzsonna','vacsora'] as $meal) {
      $grouped[$meal] = ['entries' => [], 'calories' => 0];
    }
    foreach ($entries as $e) {
      $m = $e['meal_type'];
      if (!isset($grouped[$m])) $grouped[$m] = ['entries' => [], 'calories' => 0];
      $grouped[$m]['entries'][]  = $e;
      $grouped[$m]['calories']  += (int)$e['calories'];
    }
    return $grouped;
  }
}

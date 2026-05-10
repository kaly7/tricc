<?php
declare(strict_types=1);

require_once __DIR__ . '/FoodService.php';
require_once __DIR__ . '/ExerciseService.php';
require_once __DIR__ . '/WeightService.php';
require_once __DIR__ . '/WaterService.php';

class DailyService {

  public function getDaySummary(int $userId, string $date): array {
    $foodSvc     = new FoodService();
    $exerciseSvc = new ExerciseService();
    $weightSvc   = new WeightService();
    $waterSvc    = new WaterService();

    $foodTotals     = $foodSvc->getDayTotals($userId, $date);
    $exerciseTotals = $exerciseSvc->getDayTotals($userId, $date);
    $profile        = $weightSvc->getProfile($userId);
    $latestWeight   = $weightSvc->getLatest($userId);

    $calorieGoal  = (int)($profile['daily_calorie_goal']  ?? 2000);
    $exerciseGoal = (int)($profile['exercise_goal_min']   ?? 30);
    $proteinGoal  = (int)($profile['protein_goal_g']      ?? 120);
    $waterGoal    = (int)($profile['water_goal_ml']       ?? 2500);

    $caloriesIn  = (int)$foodTotals['calories'];
    $caloriesOut = (int)$exerciseTotals['calories_burned'];
    $netCalories = $caloriesIn - $caloriesOut;
    $exerciseMin = (int)$exerciseTotals['duration_min'];
    $waterMl     = $waterSvc->getDayTotal($userId, $date);

    return [
      'date'            => $date,
      'calories_in'     => $caloriesIn,
      'calories_out'    => $caloriesOut,
      'net_calories'    => $netCalories,
      'calorie_goal'    => $calorieGoal,
      'calorie_pct'     => $calorieGoal > 0 ? round($caloriesIn / $calorieGoal * 100) : 0,
      'protein_g'       => (float)$foodTotals['protein_g'],
      'carbs_g'         => (float)$foodTotals['carbs_g'],
      'fat_g'           => (float)$foodTotals['fat_g'],
      'protein_goal_g'  => $proteinGoal,
      'exercise_min'    => $exerciseMin,
      'exercise_goal'   => $exerciseGoal,
      'exercise_pct'    => $exerciseGoal > 0 ? round($exerciseMin / $exerciseGoal * 100) : 0,
      'current_weight'  => $latestWeight ? (float)$latestWeight['weight_kg'] : null,
      'target_weight'   => ($profile['target_weight_kg'] ?? null) ? (float)$profile['target_weight_kg'] : null,
      'water_ml'        => $waterMl,
      'water_goal'      => $waterGoal,
      'water_pct'       => $waterGoal > 0 ? round($waterMl / $waterGoal * 100) : 0,
    ];
  }

  // Utolsó N nap trend adatok grafikonhoz
  public function getWeekTrend(int $userId, int $days = 7): array {
    $dates = [];
    for ($i = $days - 1; $i >= 0; $i--) {
      $dates[] = date('Y-m-d', strtotime("-{$i} days"));
    }

    $foodSvc     = new FoodService();
    $exerciseSvc = new ExerciseService();

    $foodHist     = $foodSvc->getCalorieHistory($userId, $days);
    $exerciseHist = $exerciseSvc->getHistory($userId, $days);

    $foodMap     = array_column($foodHist, 'calories', 'date');
    $exerciseMap = array_column($exerciseHist, 'duration_min', 'date');

    $result = [];
    foreach ($dates as $d) {
      $result[] = [
        'date'         => $d,
        'calories'     => (int)($foodMap[$d] ?? 0),
        'exercise_min' => (int)($exerciseMap[$d] ?? 0),
      ];
    }
    return $result;
  }

  // Napi összefoglaló szöveges formátumban (Mattermosthoz)
  public function getDaySummaryText(int $userId, string $date): string {
    $s = $this->getDaySummary($userId, $date);
    $dateHu = date('Y. m. d.', strtotime($date));

    $calPct = $s['calorie_pct'];
    $emoji  = $calPct < 50 ? '⚠️' : ($calPct <= 110 ? '✅' : '🔴');

    $lines = [
      "### Napi összefoglaló – {$dateHu}",
      "",
      "**Kalória:** {$s['calories_in']} / {$s['calorie_goal']} kcal ({$calPct}%) {$emoji}",
      "**Makrók:** Fehérje {$s['protein_g']}g | Szénhidrát {$s['carbs_g']}g | Zsír {$s['fat_g']}g",
      "**Mozgás:** {$s['exercise_min']} perc ({$s['calories_out']} kcal égett)",
      "**Nettó kalória:** {$s['net_calories']} kcal",
    ];

    $waterL     = round($s['water_ml'] / 1000, 1);
    $waterGoalL = round($s['water_goal'] / 1000, 1);
    $waterEmoji = $s['water_pct'] >= 100 ? '✅' : ($s['water_pct'] >= 50 ? '💧' : '⚠️');
    $lines[] = "**Víz:** {$waterL}L / {$waterGoalL}L ({$s['water_pct']}%) {$waterEmoji}";

    if ($s['current_weight'] !== null) {
      $lines[] = "**Súly:** {$s['current_weight']} kg";
    }
    if ($s['target_weight'] !== null && $s['current_weight'] !== null) {
      $diff = round($s['current_weight'] - $s['target_weight'], 1);
      $sign = $diff > 0 ? '+' : '';
      $lines[] = "**Célsúlytól:** {$sign}{$diff} kg";
    }

    return implode("\n", $lines);
  }

  // Heti statisztika összefoglaló (Mattermosthoz)
  public function getWeeklySummaryText(int $userId): string {
    $trend = $this->getWeekTrend($userId, 7);
    $weightSvc = new WeightService();
    $profile   = $weightSvc->getProfile($userId);
    $goal      = (int)($profile['daily_calorie_goal'] ?? 2000);

    $totalCal  = array_sum(array_column($trend, 'calories'));
    $totalMin  = array_sum(array_column($trend, 'exercise_min'));
    $activeDays = count(array_filter($trend, fn($d) => $d['calories'] > 0));

    $avgCal = $activeDays > 0 ? (int)round($totalCal / $activeDays) : 0;

    $lines = [
      "### Heti összefoglaló 🏆",
      "",
      "**Aktív napok:** {$activeDays}/7",
      "**Összes kalória:** {$totalCal} kcal (átlag: {$avgCal} kcal/nap, cél: {$goal})",
      "**Összes mozgás:** {$totalMin} perc",
      "",
      "| Nap | Kalória | Mozgás |",
      "|-----|---------|--------|",
    ];

    foreach ($trend as $d) {
      $dayName = mb_substr(['H','K','Sz','Cs','P','Szo','V'][((int)date('N', strtotime($d['date'])) - 1) % 7], 0, 3);
      $lines[] = "| {$dayName} {$d['date']} | {$d['calories']} kcal | {$d['exercise_min']} perc |";
    }

    return implode("\n", $lines);
  }
}

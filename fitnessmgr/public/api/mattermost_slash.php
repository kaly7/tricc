<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/functions.php';
require_once __DIR__ . '/../../app/Services/FoodService.php';
require_once __DIR__ . '/../../app/Services/ExerciseService.php';
require_once __DIR__ . '/../../app/Services/WeightService.php';
require_once __DIR__ . '/../../app/Services/DailyService.php';
require_once __DIR__ . '/../../app/Services/MattermostService.php';
require_once __DIR__ . '/../../app/Services/AIService.php';

header('Content-Type: application/json; charset=utf-8');

$payload = $_POST ?: [];

// Token ellenőrzés
$token    = trim((string)($payload['token'] ?? ''));
$expected = (string)cfg('mattermost.slash_token', '');
if ($expected !== '' && !hash_equals($expected, $token)) {
  http_response_code(403);
  echo json_encode(['response_type'=>'ephemeral','text'=>'Érvénytelen token.'], JSON_UNESCAPED_UNICODE);
  exit;
}

$text    = trim((string)($payload['text'] ?? ''));
$parts   = preg_split('/\s+/', $text, 4);
$cmd     = mb_strtolower($parts[0] ?? '');
$userId  = 1; // személyes app

function reply(string $text): never {
  echo json_encode(['response_type'=>'ephemeral','text'=>$text,'username'=>'FitnessBot','icon_emoji'=>':apple:'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

switch ($cmd) {

  case 'eat':
  case 'étel':
    // /fitness eat reggeli csirkemell 200
    // /fitness eat csirkemell 200
    $mealArg = $parts[1] ?? '';
    $validMeals = ['reggeli','tizorai','tízorai','ebed','ebéd','uzsonna','vacsora'];
    if (in_array(mb_strtolower($mealArg), $validMeals)) {
      $foodArg   = $parts[2] ?? '';
      $amountArg = (int)($parts[3] ?? 100);
      $meal      = match(mb_strtolower($mealArg)) {
        'tízorai' => 'tizorai',
        'ebéd'    => 'ebed',
        default   => mb_strtolower($mealArg),
      };
    } else {
      $foodArg   = $mealArg;
      $amountArg = (int)($parts[2] ?? 100);
      $meal      = 'ebed';
    }

    if ($foodArg === '') reply('❌ Add meg az étel nevét! `/fitness eat <étel> [gramm]`');
    if ($amountArg <= 0) $amountArg = 100;

    // Keresés adatbázisban
    $foodSvc = new FoodService();
    $results = $foodSvc->search($foodArg, 1);

    if ($results) {
      $item = $results[0];
      $entryId = $foodSvc->addEntry($userId, [
        'food_item_id' => $item['id'],
        'amount_g'     => $amountArg,
        'meal_type'    => $meal,
        'eaten_at'     => today(),
      ]);
      $cal = calc_calories((int)$item['calories_per_100g'], $amountArg);
      reply("✅ Rögzítve: **{$item['name']}** {$amountArg}g = **{$cal} kcal** (" . meal_label($meal) . ")");
    } else {
      // Egyéni étel manuálisan
      $ai = new AIService();
      if ($ai->isEnabled()) {
        $est = $ai->estimateCalories($foodArg . ' ' . $amountArg . 'g');
        if (!isset($est['error'])) {
          $cal100 = (int)($est['calories_per_100g'] ?? 0);
          $cal    = calc_calories($cal100, $amountArg);
          $foodSvc->addEntry($userId, [
            'custom_food_name' => $est['name'] ?? $foodArg,
            'amount_g'         => $amountArg,
            'calories'         => $cal,
            'protein_g'        => $est['protein_g'] ?? 0,
            'carbs_g'          => $est['carbs_g'] ?? 0,
            'fat_g'            => $est['fat_g'] ?? 0,
            'meal_type'        => $meal,
            'eaten_at'         => today(),
          ]);
          reply("🤖 AI becslés: **{$foodArg}** {$amountArg}g ≈ **{$cal} kcal** (megbízhatóság: {$est['confidence']}). Rögzítve!");
        }
      }
      $foodSvc->addEntry($userId, [
        'custom_food_name' => $foodArg,
        'amount_g'         => $amountArg,
        'calories'         => 0,
        'meal_type'        => $meal,
        'eaten_at'         => today(),
      ]);
      reply("⚠️ '{$foodArg}' nem található az adatbázisban. Rögzítve 0 kcal-lal. Pontosításhoz használd a webfelületet.");
    }
    break;

  case 'weight':
  case 'súly':
    $kg = (float)str_replace(',', '.', $parts[1] ?? '0');
    if ($kg <= 0) reply('❌ Adj meg súlyt: `/fitness weight 82.5`');
    $weightSvc = new WeightService();
    $weightSvc->addLog($userId, $kg, today());
    $profile = $weightSvc->getProfile($userId);
    $bmiStr  = '';
    if ($profile && $profile['height_cm']) {
      $bmi    = calc_bmi($kg, (int)$profile['height_cm']);
      $bmiStr = " | BMI: {$bmi} (" . bmi_category($bmi) . ")";
    }
    reply("⚖️ Súly rögzítve: **{$kg} kg**{$bmiStr}");
    break;

  case 'summary':
  case 'összefoglaló':
    $dailySvc = new DailyService();
    reply($dailySvc->getDaySummaryText($userId, today()));
    break;

  case 'goal':
  case 'cél':
    $dailySvc = new DailyService();
    reply($dailySvc->getWeeklySummaryText($userId));
    break;

  case 'help':
  case 'segítség':
  default:
    reply(
      "### 🥗 Fitness Bot parancsok\n\n" .
      "| Parancs | Leírás |\n|---------|--------|\n" .
      "| `/fitness eat <étel> [gramm]` | Étkezés rögzítése |\n" .
      "| `/fitness eat reggeli <étel> [gramm]` | Étkezés típussal |\n" .
      "| `/fitness weight <kg>` | Súly rögzítése |\n" .
      "| `/fitness summary` | Napi összefoglaló |\n" .
      "| `/fitness goal` | Heti haladás |\n" .
      "| `/fitness help` | Ez a súgó |"
    );
}

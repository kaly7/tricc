#!/usr/bin/env php
<?php
// Étkezési emlékeztető (dél és este)
// Crontab: 0 12 * * * php /var/www/html/fitnessmgr/cli/meal_reminder.php lunch
//           0 19 * * * php /var/www/html/fitnessmgr/cli/meal_reminder.php dinner

declare(strict_types=1);

define('CLI_MODE', true);
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/FoodService.php';
require_once __DIR__ . '/../app/Services/ExerciseService.php';
require_once __DIR__ . '/../app/Services/WeightService.php';
require_once __DIR__ . '/../app/Services/DailyService.php';
require_once __DIR__ . '/../app/Services/MattermostService.php';

$meal   = $argv[1] ?? 'lunch';
$userId = (int)cfg('user_id', 2);

$mm = new MattermostService();
if (!$mm->isEnabled()) { echo "Mattermost nincs engedélyezve.\n"; exit(0); }

$dailySvc = new DailyService();
$foodSvc  = new FoodService();
$today    = today();

$summary    = $dailySvc->getDaySummary($userId, $today);
$caloriesIn = $summary['calories_in'];
$goal       = $summary['calorie_goal'];
$remaining  = $goal - $caloriesIn;

if ($meal === 'lunch') {
  // Ebéd emlékeztető
  $entries = $foodSvc->getDayByMeal($userId, $today);
  $hadLunch = count($entries['ebed']['entries']) > 0;

  if ($hadLunch) {
    $lunchCal = $entries['ebed']['calories'];
    $msg = "🍽️ Ebéd rögzítve: **{$lunchCal} kcal**. Eddig ma: **{$caloriesIn} kcal** / {$goal} kcal.\nMaradt: **{$remaining} kcal** a napra.";
  } else {
    $msg = "🍽️ **Ebédidő!** Ne felejtsd el rögzíteni az ebédedet!\n";
    $msg .= "Eddig ma: **{$caloriesIn} kcal** / {$goal} kcal.\n";
    $msg .= "Ne felejtsd: `/fitness eat ebed <étel> <gramm>`";
  }

} elseif ($meal === 'dinner') {
  // Vacsora emlékeztető – kalória maradvány
  if ($remaining > 400) {
    $emoji = '🟡';
    $note  = "Még van kalória kereted! Ne éhezz el vacsoráig.";
  } elseif ($remaining > 0) {
    $emoji = '✅';
    $note  = "Jól állsz! Egy könnyű vacsorával zárhatod a napot.";
  } else {
    $emoji = '🔴';
    $over  = abs($remaining);
    $note  = "Ma már {$over} kcal-val túllépted a célt. Vacsorát kihagyhatod, vagy csak könnyűt egyél.";
  }

  $exerciseMin = $summary['exercise_min'];
  $msg = "{$emoji} **Vacsora emlékeztető**\n";
  $msg .= "Elfogyasztva: **{$caloriesIn} kcal** / {$goal} kcal\n";
  $msg .= "Mozgás ma: **{$exerciseMin} perc**\n";
  $msg .= "_{$note}_";
}

$mm->send($msg);
echo "Emlékeztető ({$meal}) elküldve.\n";

try {
  $st = db()->prepare("INSERT INTO mm_interactions (user_id, message_type, content) VALUES (1, 'reminder', ?)");
  $st->execute(["CLI emlékeztető: {$meal}"]);
} catch (Throwable $e) {}

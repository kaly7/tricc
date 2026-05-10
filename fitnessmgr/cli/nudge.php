#!/usr/bin/env php
<?php
// Proaktív „gondoskodó" emlékeztetők
// Crontab:
//   0 10 * * * php /var/www/html/fitnessmgr/cli/nudge.php breakfast_check
//   0 14 * * * php /var/www/html/fitnessmgr/cli/nudge.php lunch_check
//   0 21 * * * php /var/www/html/fitnessmgr/cli/nudge.php evening_check
//   0 9 * * 1 php /var/www/html/fitnessmgr/cli/nudge.php weekly_check

declare(strict_types=1);

define('CLI_MODE', true);
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/FoodService.php';
require_once __DIR__ . '/../app/Services/ExerciseService.php';
require_once __DIR__ . '/../app/Services/WeightService.php';
require_once __DIR__ . '/../app/Services/DailyService.php';
require_once __DIR__ . '/../app/Services/MattermostService.php';

$mode   = $argv[1] ?? 'evening_check';
$userId = (int)cfg('user_id', 2);

$mm = new MattermostService();
if (!$mm->isEnabled()) {
  echo "Mattermost nincs engedélyezve.\n";
  exit(0);
}

$foodSvc  = new FoodService();
$exSvc    = new ExerciseService();
$wSvc     = new WeightService();
$daily    = new DailyService();
$today    = today();
$summary  = $daily->getDaySummary($userId, $today);
$meals    = $foodSvc->getDayByMeal($userId, $today);

// Segéd: napok száma az utolsó rögzítés óta
function days_since(string $dateStr): int {
  return (int)((time() - strtotime($dateStr)) / 86400);
}

// Utolsó edzés napja
function last_exercise_date(int $userId): ?string {
  $st = db()->prepare("SELECT MAX(done_at) FROM exercise_entries WHERE user_id = ?");
  $st->execute([$userId]);
  $v = $st->fetchColumn();
  return $v ?: null;
}

// Utolsó súlymérés napja
function last_weight_date(int $userId): ?string {
  $st = db()->prepare("SELECT MAX(measured_at) FROM weight_logs WHERE user_id = ?");
  $st->execute([$userId]);
  $v = $st->fetchColumn();
  return $v ?: null;
}

$msgs = [];

if ($mode === 'breakfast_check') {
  // 10:00 – reggeli megvolt-e?
  $hadBreakfast = !empty($meals['reggeli']['entries']);
  if (!$hadBreakfast) {
    $msgs[] = "☕ Hé, reggeli megvolt már? Nem látom rögzítve. " .
              "Írd le mit ettél, vagy dobd be a naplóba! 🥚";
  } else {
    echo "Reggeli rögzítve – nincs nudge.\n";
    exit(0);
  }
}

if ($mode === 'lunch_check') {
  // 14:00 – ebéd megvolt-e?
  $hadLunch = !empty($meals['ebed']['entries']);
  if (!$hadLunch) {
    $calSoFar = $summary['calories_in'];
    $goal     = $summary['calorie_goal'];
    $msgs[] = "🍽️ Ebéd? Még nem látom rögzítve. Eddig ma: **{$calSoFar} kcal** / {$goal} kcal.\n" .
              "Ne hagyd ki – az ebéd kihagyása este faláshoz vezet! 😄";
  } else {
    echo "Ebéd rögzítve – nincs nudge.\n";
    exit(0);
  }
}

if ($mode === 'evening_check') {
  // 21:00 – kalória összesítés, ha kevés / semmi
  $calIn  = $summary['calories_in'];
  $goal   = $summary['calorie_goal'];
  $pct    = $summary['calorie_pct'];
  $exMin  = $summary['exercise_min'];

  if ($calIn === 0) {
    $msgs[] = "📋 **Ma még semmit nem rögzítettél!** Tényleg nem ettél ma semmit, " .
              "vagy csak elfelejtettük a naplót? 😅\nPótold be, hogy pontos legyen a statisztika!";
  } elseif ($pct < 50) {
    $msgs[] = "⚠️ Ma csak **{$calIn} kcal** jött össze ({$pct}% a célból). " .
              "Ez nagyon kevés – az extrém kalóriahiány nem egészséges! Ettél még valamit, ami nincs rögzítve?";
  } elseif ($pct > 120) {
    $over = $calIn - $goal;
    $msgs[] = "🔴 Ma **{$over} kcal-val túllépted** a napi célod ({$calIn} kcal). " .
              "Holnap picit visszább, oké? 💪";
  }

  // Mozgás hiánya
  if ($exMin === 0) {
    $lastEx = last_exercise_date($userId);
    if ($lastEx === null) {
      $msgs[] = "🏃 Még egyetlen edzést sem rögzítettél. Mikor lesz az első mozgás? 😊";
    } elseif (days_since($lastEx) >= 3) {
      $d = days_since($lastEx);
      $msgs[] = "🏃 **{$d} napja nem volt mozgás** rögzítve. Holnap egy kis séta? Akár 20 perc is számít!";
    }
  }

  // Súlymérés hiánya
  $lastW = last_weight_date($userId);
  if ($lastW === null) {
    $msgs[] = "⚖️ Még nincs súlymérés rögzítve. Reggel lépj a mérlegre éhgyomorra!";
  } elseif (days_since($lastW) >= 7) {
    $d = days_since($lastW);
    $msgs[] = "⚖️ **{$d} napja nem volt súlymérés.** Holnap reggel mérleg! ⚖️";
  }
}

if ($mode === 'weekly_check') {
  // Hétfő reggel – heti áttekintés, ha baj van
  $lastEx = last_exercise_date($userId);
  $lastW  = last_weight_date($userId);

  if ($lastEx === null || days_since($lastEx) >= 7) {
    $msgs[] = "🏃 **Ezen a héten nem volt rögzített edzés!** Tegyük helyre – " .
              "akár 3×20 perc séta is elég kezdésnek. 💚";
  }

  if ($lastW === null || days_since($lastW) >= 10) {
    $d = $lastW ? days_since($lastW) : null;
    $info = $d ? "{$d} napja" : "még soha";
    $msgs[] = "⚖️ Súlymérés {$info} volt. Hétre jó lenne felvenni a heti rutinba – pl. hétfő reggel!";
  }
}

if (empty($msgs)) {
  echo "Minden rendben – nincs küldendő nudge ({$mode}).\n";
  exit(0);
}

$mm->send(implode("\n\n", $msgs));
echo "Nudge elküldve ({$mode}): " . count($msgs) . " üzenet.\n";

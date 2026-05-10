#!/usr/bin/env php
<?php
// Reggeli check-in és esti összefoglaló
// Crontab: 0 7 * * * php /var/www/html/fitnessmgr/cli/daily_checkin.php morning
//           0 21 * * * php /var/www/html/fitnessmgr/cli/daily_checkin.php evening

declare(strict_types=1);

define('CLI_MODE', true);
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/FoodService.php';
require_once __DIR__ . '/../app/Services/ExerciseService.php';
require_once __DIR__ . '/../app/Services/WeightService.php';
require_once __DIR__ . '/../app/Services/DailyService.php';
require_once __DIR__ . '/../app/Services/MattermostService.php';
require_once __DIR__ . '/../app/Services/AIService.php';

$mode   = $argv[1] ?? 'morning';
$userId = (int)cfg('user_id', 2);

$mm = new MattermostService();
if (!$mm->isEnabled()) {
  echo "Mattermost nincs engedélyezve (config.php). Kilépés.\n";
  exit(0);
}

$dailySvc = new DailyService();
$today    = today();

if ($mode === 'morning') {
  // Reggeli motiváció + tegnapi összefoglaló ha van adat
  $yesterday  = date('Y-m-d', strtotime('-1 day'));
  $yesterdayS = $dailySvc->getDaySummary($userId, $yesterday);

  $greeting = "Jó reggelt! 🌅 Ma is legyél tudatos!\n\n";
  $greeting .= "**A mai nap terve:**\n";
  $greeting .= "🍳 Reggeli rögzítése: `/fitness eat reggeli <étel> <gramm>`\n";
  $greeting .= "⚖️ Súlymérés: `/fitness weight <kg>`\n";
  $greeting .= "📊 Napi összefoglaló: `/fitness summary`\n\n";

  if ($yesterdayS['calories_in'] > 0) {
    $pct   = $yesterdayS['calorie_pct'];
    $emoji = $pct >= 50 && $pct <= 110 ? '✅' : '⚠️';
    $greeting .= "_Tegnap: {$yesterdayS['calories_in']} kcal ({$pct}% a célból) {$emoji}_";
  }

  $mm->send($greeting);
  echo "Reggeli üzenet elküldve.\n";

} elseif ($mode === 'evening') {
  // Esti összefoglaló
  $summary = $dailySvc->getDaySummaryText($userId, $today);

  // AI motiváció ha engedélyezve van
  $ai = new AIService();
  if ($ai->isEnabled()) {
    $weightSvc = new WeightService();
    $history   = $weightSvc->getHistory($userId, 30);
    if (count($history) >= 3) {
      $motivation = $ai->motivate($history);
      $summary .= "\n\n---\n💬 _AI tanács:_ {$motivation}";
    }
  }

  $mm->send($summary);
  echo "Esti összefoglaló elküldve.\n";
}

// Interakció logolás
try {
  $st = db()->prepare("INSERT INTO mm_interactions (user_id, message_type, content) VALUES (1, 'checkin', ?)");
  $st->execute(["CLI check-in: {$mode}"]);
} catch (Throwable $e) {}

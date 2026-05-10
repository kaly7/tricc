#!/usr/bin/env php
<?php
// Heti összefoglaló (vasárnap este)
// Crontab: 0 20 * * 0 php /var/www/html/fitnessmgr/cli/weekly_summary.php

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

$userId = (int)cfg('user_id', 2);

$mm = new MattermostService();
if (!$mm->isEnabled()) { echo "Mattermost nincs engedélyezve.\n"; exit(0); }

$dailySvc  = new DailyService();
$weightSvc = new WeightService();

$summary = $dailySvc->getWeeklySummaryText($userId);

// Súly trend az elmúlt hétre
$latest   = $weightSvc->getLatest($userId);
$change   = $weightSvc->getChange($userId);
$history7 = $weightSvc->getHistory($userId, 7);

if ($latest) {
  $summary .= "\n\n**Súly:** {$latest['weight_kg']} kg";
  if ($change !== null) {
    $sign = $change > 0 ? '+' : '';
    $summary .= " (változás: {$sign}{$change} kg)";
  }
}

// AI javaslat jövő hétre
$ai = new AIService();
if ($ai->isEnabled() && count($history7) >= 2) {
  $plan = $ai->generateExercisePlan($weightSvc->getProfile($userId) ?? []);
  $summary .= "\n\n---\n🤖 **Jövő heti javaslat:**\n{$plan}";
}

$mm->send($summary);
echo "Heti összefoglaló elküldve.\n";

try {
  $st = db()->prepare("INSERT INTO mm_interactions (user_id, message_type, content) VALUES (1, 'summary', 'Heti összefoglaló CLI')");
  $st->execute([]);
} catch (Throwable $e) {}

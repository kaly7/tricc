#!/usr/bin/env php
<?php
// Vízfogyasztás emlékeztető időjárás-figyeléssel
// Crontab:
//   0 9,12,15,18 * * * php /var/www/html/fitnessmgr/cli/water_reminder.php

declare(strict_types=1);

define('CLI_MODE', true);
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/MattermostService.php';
require_once __DIR__ . '/../app/Services/WaterService.php';

$userId = (int)cfg('user_id', 2);

$mm = new MattermostService();
if (!$mm->isEnabled()) {
  echo "Mattermost nincs engedélyezve.\n";
  exit(0);
}

$waterSvc = new WaterService();
$today    = today();
$todayMl  = $waterSvc->getDayTotal($userId, $today);
$goalMl   = $waterSvc->getGoal($userId);
$pct      = $goalMl > 0 ? (int)round($todayMl / $goalMl * 100) : 0;

// Ha már elérte a célt, nem zavarjuk
if ($todayMl >= $goalMl) {
  echo "Napi vízcél elérve ({$todayMl}ml / {$goalMl}ml) – nincs emlékeztető.\n";
  exit(0);
}

// Utolsó vízrögzítés időpontja – ha 2 órán belül volt, nem zavarjuk
$lastLog = $waterSvc->getLastLogTime($userId);
if ($lastLog) {
  $minutesSinceLast = (int)((time() - strtotime($lastLog)) / 60);
  if ($minutesSinceLast < 90) {
    echo "Legutóbbi víz rögzítés {$minutesSinceLast} perce volt – kihagyjuk.\n";
    exit(0);
  }
}

// Időjárás lekérdezés (wttr.in)
function get_temperature(): ?int {
  $location = cfg('weather_location', 'Budapest');
  $url = "https://wttr.in/{$location}?format=%t";
  $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
  $raw = @file_get_contents($url, false, $ctx);
  if (!$raw) return null;
  if (preg_match('/([+-]?\d+)/', trim($raw), $m)) return (int)$m[1];
  return null;
}

$temp    = get_temperature();
$isHot   = $temp !== null && $temp >= 28;
$todayL  = round($todayMl / 1000, 1);
$goalL   = round($goalMl / 1000, 1);
$leftMl  = $goalMl - $todayMl;
$leftL   = round($leftMl / 1000, 1);

// Üzenet összeállítása
$hour = (int)date('H');
$greetings = [
  9  => "Jó reggelt!",
  12 => "Ebédszünet!",
  15 => "Délutáni szünet!",
  18 => "Munkaidő vége!",
];
$greeting = $greetings[$hour] ?? "Hé!";

$msg = "💧 **{$greeting}** Igyál egy pohár vizet!\n";
$msg .= "Ma eddig: **{$todayL}L** / {$goalL}L ({$pct}%) – még **{$leftL}L** hiányzik.\n";

if ($isHot) {
  $msg .= "🌡️ Ma **{$temp}°C** van – meleg időben többet kell inni! Célozzunk inkább **" .
          round(($goalMl * 1.2) / 1000, 1) . "L**-re ma.";
} elseif ($temp !== null) {
  $msg .= "_({$temp}°C, szép idő – ne felejtsd a vizet!)_";
}

$mm->send($msg);
echo "Víz emlékeztető elküldve (ma: {$todayMl}ml / {$goalMl}ml, hőmérséklet: " . ($temp ?? '?') . "°C).\n";

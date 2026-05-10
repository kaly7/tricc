#!/usr/bin/env php
<?php
// Mattermost DM WebSocket listener – valós idejű bot
// Futtatás: php /var/www/html/fitnessmgr/cli/dm_listener.php
// Háttérben: nohup php /var/www/html/fitnessmgr/cli/dm_listener.php >> /var/www/html/fitnessmgr/storage/logs/dm_listener.log 2>&1 &
// Leállítás: kill $(cat /var/www/html/fitnessmgr/storage/tmp/dm_listener.pid)

declare(strict_types=1);

define('CLI_MODE', true);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/FoodService.php';
require_once __DIR__ . '/../app/Services/ExerciseService.php';
require_once __DIR__ . '/../app/Services/WeightService.php';
require_once __DIR__ . '/../app/Services/DailyService.php';
require_once __DIR__ . '/../app/Services/MattermostService.php';
require_once __DIR__ . '/../app/Services/AIService.php';
require_once __DIR__ . '/../app/Services/WaterService.php';

use WebSocket\Client;

// PID fájl
$pidFile = __DIR__ . '/../storage/tmp/dm_listener.pid';
@mkdir(dirname($pidFile), 0755, true);
file_put_contents($pidFile, (string)getmypid());

// Cleanup PID kilépéskor
register_shutdown_function(function() use ($pidFile) {
  @unlink($pidFile);
  log_msg("Listener leállítva.");
});

function log_msg(string $msg): void {
  echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function handle_message(string $text, MattermostService $mm, AIService $ai, DailyService $daily, WeightService $weight, WaterService $water): void {
  $text = trim($text);
  if ($text === '') return;

  log_msg("Üzenet érkezett: {$text}");
  $lower = mb_strtolower($text, 'UTF-8');
  $userId = (int)cfg('user_id', 1);

  // --- Parancs felismerés ---

  // Súly rögzítés: "82.5", "82,5 kg", "súly 82.5"
  if (preg_match('/(?:súly\s*)?(\d{2,3}[.,]\d{1,2})\s*(?:kg)?$/u', $lower, $m)) {
    $kg = (float)str_replace(',', '.', $m[1]);
    if ($kg >= 30 && $kg <= 300) {
      $weight->addLog($userId, $kg, today());
      $profile = $weight->getProfile($userId);
      $bmiStr  = '';
      if ($profile && $profile['height_cm']) {
        $bmi    = calc_bmi($kg, (int)$profile['height_cm']);
        $bmiStr = " | BMI: **{$bmi}** (" . bmi_category($bmi) . ")";
      }
      $diff = '';
      if ($profile && $profile['target_weight_kg']) {
        $d    = round($kg - (float)$profile['target_weight_kg'], 1);
        $sign = $d > 0 ? '+' : '';
        $diff = "\nCélsúlytól: **{$sign}{$d} kg**";
      }
      $mm->send("⚖️ Rögzítve: **{$kg} kg**{$bmiStr}{$diff}");
      return;
    }
  }

  // Napi összefoglaló
  if (str_contains($lower, 'összefoglal') || str_contains($lower, 'ma hogy') || str_contains($lower, 'ma hogyan') || $lower === 'ma') {
    $mm->send($daily->getDaySummaryText($userId, today()));
    return;
  }

  // Heti összefoglaló
  if (str_contains($lower, 'heti') || str_contains($lower, 'ezen a héten') || str_contains($lower, 'e héten')) {
    $mm->send($daily->getWeeklySummaryText($userId));
    return;
  }

  // Víz rögzítés / lekérdezés
  // Minták: "ittam 2 pohár víz", "500ml vizet ittam", "pohár víz", "víz?", "víz", "ittam 3dl"
  $isWaterQuery = ($lower === 'víz' || $lower === 'víz?' || str_contains($lower, 'mennyit ittam'));
  $waterMl = 0;

  if (!$isWaterQuery) {
    // ml / dl / cl / l mennyiség víz kontextusban
    if (preg_match('/(\d+)\s*ml/u', $lower, $m) && str_contains($lower, 'víz')) {
      $waterMl = (int)$m[1];
    } elseif (preg_match('/(\d+)\s*dl/u', $lower, $m) && str_contains($lower, 'víz')) {
      $waterMl = (int)$m[1] * 100;
    } elseif (preg_match('/(\d+(?:[.,]\d+)?)\s*liter/u', $lower, $m)) {
      $waterMl = (int)(str_replace(',', '.', $m[1]) * 1000);
    } elseif (preg_match('/(\d+)\s*pohár/u', $lower, $m)) {
      $waterMl = (int)$m[1] * 250;
    } elseif (preg_match('/pohár\s*víz/u', $lower)) {
      $waterMl = 250;
    } elseif (preg_match('/(\d+)\s*(?:palack|üveg)/u', $lower, $m)) {
      $waterMl = (int)$m[1] * 500;
    } elseif (str_contains($lower, 'palack') || str_contains($lower, 'üveg')) {
      $waterMl = 500;
    }
  }

  if ($waterMl > 0 && $waterMl <= 5000) {
    $water->addLog($userId, $waterMl, today());
    $todayMl   = $water->getDayTotal($userId, today());
    $goalMl    = $water->getGoal($userId);
    $todayL    = round($todayMl / 1000, 1);
    $goalL     = round($goalMl / 1000, 1);
    $pct       = $goalMl > 0 ? (int)round($todayMl / $goalMl * 100) : 0;
    $remaining = max(0, $goalMl - $todayMl);
    $bar       = str_repeat('🔵', min(10, (int)ceil($pct / 10))) . str_repeat('⚪', max(0, 10 - (int)ceil($pct / 10)));
    $msg = "💧 Rögzítve: **" . ($waterMl >= 1000 ? round($waterMl/1000, 1) . "L" : "{$waterMl}ml") . "**\n";
    $msg .= "{$bar}\n";
    $msg .= "Ma összesen: **{$todayL}L** / {$goalL}L ({$pct}%)";
    if ($remaining > 0) $msg .= "\nMég hiányzik: **" . round($remaining / 1000, 1) . "L**";
    else $msg .= "\n✅ Napi vízfogyasztási célt elérted!";
    $mm->send($msg);
    return;
  }

  if ($isWaterQuery) {
    $todayMl = $water->getDayTotal($userId, today());
    $goalMl  = $water->getGoal($userId);
    $todayL  = round($todayMl / 1000, 1);
    $goalL   = round($goalMl / 1000, 1);
    $pct     = $goalMl > 0 ? (int)round($todayMl / $goalMl * 100) : 0;
    $mm->send("💧 Ma eddig: **{$todayL}L** / {$goalL}L ({$pct}%)" . ($pct >= 100 ? " ✅" : ""));
    return;
  }

  // Segítség
  if (str_contains($lower, 'segítség') || str_contains($lower, 'mit tudsz') || str_contains($lower, 'help') || $lower === '?') {
    $mm->send(
      "### 🥗 Fitness Guru – mit tudok?\n\n" .
      "**Rögzítés:**\n" .
      "- Étkezés: csak írd le mit ettél (`csirkemell rizzsel`, `reggeli volt egy tojás`)\n" .
      "- Súly: `82.5 kg` vagy `súly 83.2`\n" .
      "- Víz: `pohár víz`, `500ml víz`, `2 pohár víz`, `1 liter`\n\n" .
      "**Lekérdezés:**\n" .
      "- `ma` – mai összefoglaló\n" .
      "- `heti` – heti statisztika\n" .
      "- `víz` – mai vízfogyasztás\n" .
      "- `recept` – mit egyek ma?\n\n" .
      "**Vagy csak írj** – az AI megérti és válaszol! 🤖"
    );
    return;
  }

  // Recept javaslat
  if (str_contains($lower, 'recept') || str_contains($lower, 'mit egyek') || str_contains($lower, 'mit főzzek')) {
    if ($ai->isEnabled()) {
      $profile  = $weight->getProfile($userId);
      $todayCal = (new FoodService())->getDayTotals($userId, today())['calories'];
      $maxCal   = max(300, (int)($profile['daily_calorie_goal'] ?? 2000) - $todayCal);
      $reply    = $ai->suggestRecipe($maxCal);
      $mm->send("👨‍🍳 " . $reply);
    } else {
      $mm->send("🤖 AI nincs bekapcsolva – töltsd ki a config.php-ban a Claude API kulcsot.");
    }
    return;
  }

  // AI feldolgozás – étel felismerés + általános chat
  $profile = $weight->getProfile($userId);
  $summary = $daily->getDaySummary($userId, today());

  if ($ai->isEnabled()) {
    // Először megpróbálja étkezésként értelmezni
    $parsed = $ai->parseFoodMessage($text, $summary, $profile ?? []);

    if ($parsed['is_food'] && !empty($parsed['items'])) {
      // Étkezés rögzítés
      $foodSvc = new FoodService();
      foreach ($parsed['items'] as $item) {
        try {
          $foodSvc->addEntry($userId, [
            'custom_food_name' => $item['name']      ?? $text,
            'amount_g'         => (int)($item['amount_g']  ?? 100),
            'calories'         => (int)($item['calories']  ?? 0),
            'protein_g'        => (float)($item['protein_g'] ?? 0),
            'carbs_g'          => (float)($item['carbs_g']   ?? 0),
            'fat_g'            => (float)($item['fat_g']     ?? 0),
            'meal_type'        => $item['meal_type']  ?? 'ebed',
            'eaten_at'         => today(),
          ]);
        } catch (Throwable $e) {
          log_msg("Étel mentési hiba: " . $e->getMessage());
        }
      }
      $reply = $parsed['reply'] ?: '✅ Rögzítve!';
      // Friss összesítő hozzáfűzése
      $newSummary = $daily->getDaySummary($userId, today());
      $reply .= "\n\n📊 Ma eddig: **{$newSummary['calories_in']}/{$newSummary['calorie_goal']} kcal**";
      $mm->send($reply);
    } else {
      // Nem étel – általános AI válasz
      $reply = $ai->chat($text, $summary, $profile ?? []);
      $mm->send($reply);
    }
  } else {
    $mm->send("Megkaptam: \"{$text}\"\n\nAI nincs bekapcsolva – súlyt így rögzíthetsz: `82.5 kg`\nÉtkezést a weboldalon tudod felvenni. 🥗");
  }
}

// --- REST API polling ---
$token     = (string)cfg('mattermost.bot_token');
$serverUrl = rtrim((string)cfg('mattermost.server_url'), '/');
$dmChannel = (string)cfg('mattermost.dm_channel_id');
$botId     = (string)cfg('mattermost.bot_id');

$mm     = new MattermostService();
$ai     = new AIService();
$daily  = new DailyService();
$weight = new WeightService();
$water  = new WaterService();

log_msg("Fitness Guru DM listener indul (REST polling)...");
log_msg("Server: {$serverUrl}");
log_msg("DM channel: {$dmChannel}");

$mm->send("🟢 Fitness Guru bekapcsolt – figyelem az üzeneteidet!");

// Induláskor az aktuális szerveridőt kérjük le – csak az ezután érkező üzeneteket dolgozzuk fel
function rest_get_server_time(string $serverUrl, string $token): int {
  $data = rest_get("{$serverUrl}/api/v4/users/me", $token);
  // Ha nincs, fallback: helyi idő milliszekundumban
  return (int)(microtime(true) * 1000);
}

$lastTs = rest_get_server_time($serverUrl, $token);
log_msg("Indulás időbélyeg: {$lastTs}");

// Visszaad: ['posts' => [...időrendi...], 'newest_ts' => 0]
function rest_get_new_posts(string $serverUrl, string $token, string $channelId, int $afterTs): array {
  $url  = "{$serverUrl}/api/v4/channels/{$channelId}/posts?since={$afterTs}&per_page=50";
  $data = rest_get($url, $token);
  if (!$data || empty($data['order'])) return ['posts' => [], 'newest_ts' => $afterTs];
  // order: legújabbtól → megfordítjuk időrendi sorrendbe; dedup az ID alapján
  $seen     = [];
  $posts    = [];
  $newestTs = $afterTs;
  foreach (array_reverse($data['order']) as $id) {
    if (isset($seen[$id])) continue; // Mattermost néha duplán adja thread-aktivitásnál
    $seen[$id] = true;
    $p  = $data['posts'][$id];
    $ts = (int)($p['create_at'] ?? 0);
    if ($ts > $newestTs) $newestTs = $ts;
    $posts[] = $p;
  }
  return ['posts' => $posts, 'newest_ts' => $newestTs];
}

function rest_get(string $url, string $token): ?array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", "Content-Type: application/json"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300 || !$body) return null;
  return json_decode((string)$body, true) ?: null;
}

// Polling ciklus – 3 másodpercenként
while (true) {
  try {
    $result = rest_get_new_posts($serverUrl, $token, $dmChannel, $lastTs);

    // Időbélyeget MINDIG frissítjük a batch eredménye alapján (bot üzenetek is számítanak)
    if ($result['newest_ts'] > $lastTs) {
      $lastTs = $result['newest_ts'] + 1; // +1 ms, hogy ne lássuk újra ugyanazt
    }

    foreach ($result['posts'] as $post) {
      $senderId = $post['user_id'] ?? '';
      $message  = trim($post['message'] ?? '');

      if ($senderId === $botId) continue; // bot saját üzenetei
      if ($message === '') continue;

      log_msg("Üzenet érkezett: {$message}");
      handle_message($message, $mm, $ai, $daily, $weight, $water);
    }
  } catch (Throwable $e) {
    log_msg("Polling hiba: " . $e->getMessage());
  }

  sleep(3);
}

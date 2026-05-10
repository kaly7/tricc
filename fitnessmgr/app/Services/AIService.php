<?php
declare(strict_types=1);

class AIService {

  public function isEnabled(): bool {
    return (bool)cfg('claude.enabled', false)
        && cfg('claude.api_key', '') !== '';
  }

  // Kalória becslés szövegből (pl. "egy tányér gulyás")
  public function estimateCalories(string $foodDescription): array {
    if (!$this->isEnabled()) {
      return ['error' => 'AI nincs engedélyezve. Állítsd be a Claude API kulcsot a config.php-ban.'];
    }

    $prompt = "Te egy táplálkozási szakértő vagy. Becsüld meg a következő étel kalória- és makróértékeit 100 grammra vetítve, ÉS add meg a tipikus adagméretet is.\n\nÉtel: {$foodDescription}\n\nVálaszolj CSAK JSON formátumban, semmi mást:\n{\"name\": \"étel neve\", \"typical_amount_g\": 200, \"calories_per_100g\": 150, \"protein_g\": 10.0, \"carbs_g\": 15.0, \"fat_g\": 6.0, \"confidence\": \"magas|közepes|alacsony\", \"note\": \"rövid megjegyzés\"}";

    $response = $this->call($prompt, 500);
    if (isset($response['error'])) return $response;

    $json = $this->extractJson($response['content'] ?? '');
    if (!$json) return ['error' => 'AI válasz nem értelmezhető JSON.', 'raw' => $response['content'] ?? ''];

    $this->saveSuggestion(1, 'kaloria_becsles', $foodDescription, json_encode($json, JSON_UNESCAPED_UNICODE), $response['tokens'] ?? 0);
    return $json;
  }

  // Napi étrend értékelése
  public function evaluateDailyIntake(array $todayEntries, array $profile): string {
    if (!$this->isEnabled()) return 'AI nincs engedélyezve.';

    $totalCal  = array_sum(array_column($todayEntries, 'calories'));
    $totalProt = array_sum(array_column($todayEntries, 'protein_g'));
    $totalCarb = array_sum(array_column($todayEntries, 'carbs_g'));
    $totalFat  = array_sum(array_column($todayEntries, 'fat_g'));
    $foodList  = implode(', ', array_map(fn($e) => ($e['food_name'] ?? $e['custom_food_name'] ?? 'ismeretlen') . " ({$e['calories']} kcal)", $todayEntries));
    $goal      = $profile['daily_calorie_goal'] ?? 2000;
    $target    = $profile['target_weight_kg']   ?? 'nincs megadva';

    $prompt = "Te egy táplálkozási tanácsadó vagy. Értékeld a napi étkezést és adj rövid, biztató tanácsot.\n\nMai étkezések: {$foodList}\nÖsszesen: {$totalCal} kcal, fehérje: {$totalProt}g, szénhidrát: {$totalCarb}g, zsír: {$totalFat}g\nNapi kalória cél: {$goal} kcal\nCélsúly: {$target} kg\n\nAdj egy 2-3 mondatos értékelést magyarul: mi volt jó, mi lehetne jobb, és mit egyél még ha van hiány, vagy mit hagyj el ha túl sok volt. Légy pozitív és motiváló.";

    $response = $this->call($prompt, 300);
    if (isset($response['error'])) return $response['error'];

    $content = trim($response['content'] ?? '');
    $this->saveSuggestion(1, 'napi_ertekelés', 'napi értékelés', $content, $response['tokens'] ?? 0);
    return $content;
  }

  // Edzésterv javaslat
  public function generateExercisePlan(array $profile): string {
    if (!$this->isEnabled()) return 'AI nincs engedélyezve.';

    $act    = $profile['activity_level'] ?? 'mérsékelt';
    $target = $profile['target_weight_kg'] ?? 'fogyás';
    $goal   = $profile['exercise_goal_min'] ?? 30;

    $prompt = "Te egy személyi edző vagy. Adj egy egyhetes edzéstervet egy {$act} aktivitású személynek, aki fogyni szeretne. Napi edzési cél: {$goal} perc. Célsúly: {$target} kg.\n\nAdj egy kompakt, motiváló heti tervet magyarul, konkrét tevékenységekkel. Max 150 szó.";

    $response = $this->call($prompt, 400);
    if (isset($response['error'])) return $response['error'];

    $content = trim($response['content'] ?? '');
    $this->saveSuggestion(1, 'edzésterv', 'heti edzésterv', $content, $response['tokens'] ?? 0);
    return $content;
  }

  // Motiváció – súly trend alapján
  public function motivate(array $weightHistory): string {
    if (!$this->isEnabled()) return 'AI nincs engedélyezve.';
    if (empty($weightHistory)) return 'Még nincs elegendő adat a motivációhoz.';

    $first = (float)$weightHistory[0]['weight_kg'];
    $last  = (float)end($weightHistory)['weight_kg'];
    $diff  = round($last - $first, 1);
    $sign  = $diff <= 0 ? abs($diff) . ' kg-ot fogytál' : "{$diff} kg-ot hízott";
    $days  = count($weightHistory);

    $prompt = "Te egy motivációs coach vagy. {$days} mérés alatt {$sign} az illető. Jelenlegi súly: {$last} kg. Írj egy rövid, személyes, biztató üzenetet magyarul (max 3 mondat). Légy konkrét és pozitív.";

    $response = $this->call($prompt, 200);
    if (isset($response['error'])) return $response['error'];

    $content = trim($response['content'] ?? '');
    $this->saveSuggestion(1, 'motiváció', "súly trend {$days} mérés", $content, $response['tokens'] ?? 0);
    return $content;
  }

  // Étel felismerés és rögzítési adatok kinyerése üzenetből
  // Visszatér: ['is_food' => bool, 'items' => [['name','meal_type','amount_g','calories','protein_g','carbs_g','fat_g']], 'reply' => string]
  public function parseFoodMessage(string $userMessage, array $todaySummary, array $profile): array {
    if (!$this->isEnabled()) return ['is_food' => false, 'items' => [], 'reply' => 'AI nincs engedélyezve.'];

    $calIn   = $todaySummary['calories_in']  ?? 0;
    $calGoal = $todaySummary['calorie_goal'] ?? 2000;
    $context = "Mai kalória eddig: {$calIn}/{$calGoal} kcal.";

    $hour     = (int)date('H');
    $mealHint = match(true) {
      $hour < 10 => 'reggeli',
      $hour < 12 => 'tizorai',
      $hour < 15 => 'ebed',
      $hour < 18 => 'uzsonna',
      default    => 'vacsora',
    };

    $prompt = "Te egy táplálkozási asszisztens vagy. Az üzenet étkezésre utal-e?\n\n" .
      "Üzenet: \"{$userMessage}\"\n" .
      "Kontextus: {$context} Jelenlegi időszak: {$mealHint}.\n\n" .
      "Ha étkezésre utal, azonosítsd az ételeket. Összetett étkezésnél (pl. szendvics hozzávalókkal) " .
      "az egész ételt egybe vedd, ne bonts fel minden hozzávalóra külön itemre.\n" .
      "Add meg:\n" .
      "- is_food: true\n" .
      "- items: tömb, minden fogáshoz 1 item: name, meal_type (reggeli/tizorai/ebed/uzsonna/vacsora), " .
      "amount_g (becsült gramm), calories (becsült kcal), protein_g, carbs_g, fat_g\n" .
      "- reply: 1 mondatos baráti visszajelzés magyarul (emoji ok), összesített kalóriával\n\n" .
      "Ha NEM étkezés: {\"is_food\": false, \"items\": [], \"reply\": \"\"}\n\n" .
      "CSAK valid JSON, semmi más:";

    $response = $this->call($prompt, 800);
    if (isset($response['error'])) return ['is_food' => false, 'items' => [], 'reply' => $response['error']];

    $json = $this->extractJson($response['content'] ?? '');
    if (!$json || !isset($json['is_food'])) {
      return ['is_food' => false, 'items' => [], 'reply' => ''];
    }

    return $json;
  }

  // Általános chat – nem étel jellegű kérdések, motiváció, tanácsok
  public function chat(string $userMessage, array $todaySummary, array $profile): string {
    if (!$this->isEnabled()) return 'AI nincs engedélyezve.';

    $calIn   = $todaySummary['calories_in']  ?? 0;
    $calGoal = $todaySummary['calorie_goal'] ?? 2000;
    $exMin   = $todaySummary['exercise_min'] ?? 0;
    $weight  = $todaySummary['current_weight'] ?? null;
    $target  = $profile['target_weight_kg'] ?? null;

    $context = "Mai kalória: {$calIn}/{$calGoal} kcal. Mozgás: {$exMin} perc.";
    if ($weight) $context .= " Súly: {$weight} kg.";
    if ($target) $context .= " Célsúly: {$target} kg.";

    $prompt = "Te egy barátságos személyi fitness asszisztens vagy. " .
      "Rövid, természetes stílusban válaszolj magyarul (max 3-4 mondat). Emoji megengedett.\n\n" .
      "Felhasználó mai állapota: {$context}\n\n" .
      "Felhasználó üzenete: {$userMessage}";

    $response = $this->call($prompt, 300);
    if (isset($response['error'])) return $response['error'];

    return trim($response['content'] ?? '');
  }

  // Recept javaslat
  public function suggestRecipe(int $maxCalories): string {
    if (!$this->isEnabled()) return 'AI nincs engedélyezve.';

    $prompt = "Javasolj egy egyszerű, egészséges ebéd receptet, ami maximum {$maxCalories} kalória. Magyar ételek preferáltak. Adj meg: étel neve, összetevők (kb. 4-6 dolog), elkészítési idő, kalória becslés. Max 150 szó.";

    $response = $this->call($prompt, 350);
    if (isset($response['error'])) return $response['error'];

    $content = trim($response['content'] ?? '');
    $this->saveSuggestion(1, 'recept', "max {$maxCalories} kcal recept", $content, $response['tokens'] ?? 0);
    return $content;
  }

  private function call(string $prompt, int $maxTokens = 512): array {
    $apiKey = (string)cfg('claude.api_key', '');
    $model  = (string)cfg('claude.model', 'claude-sonnet-4-6');

    $payload = [
      'model'      => $model,
      'max_tokens' => $maxTokens,
      'messages'   => [['role' => 'user', 'content' => $prompt]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
      CURLOPT_POST          => true,
      CURLOPT_HTTPHEADER    => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
      ],
      CURLOPT_POSTFIELDS    => json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT       => 30,
    ]);

    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
      $err = json_decode((string)$raw, true);
      return ['error' => 'Claude API hiba (' . $code . '): ' . ($err['error']['message'] ?? $raw)];
    }

    $data    = json_decode((string)$raw, true);
    $content = $data['content'][0]['text'] ?? '';
    $tokens  = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);

    return ['content' => $content, 'tokens' => $tokens];
  }

  private function extractJson(string $text): ?array {
    // JSON kinyerése a válasz szövegből
    if (preg_match('/\{.*\}/s', $text, $m)) {
      $decoded = json_decode($m[0], true);
      return is_array($decoded) ? $decoded : null;
    }
    return null;
  }

  private function saveSuggestion(int $userId, string $type, string $promptSummary, string $content, int $tokens): void {
    try {
      $st = db()->prepare("
        INSERT INTO ai_suggestions (user_id, suggestion_type, prompt_summary, content, tokens_used)
        VALUES (?, ?, ?, ?, ?)
      ");
      $st->execute([$userId, $type, mb_substr($promptSummary, 0, 300), $content, $tokens]);
    } catch (Throwable $e) {}
  }
}

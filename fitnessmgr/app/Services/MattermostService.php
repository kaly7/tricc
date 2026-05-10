<?php
declare(strict_types=1);

class MattermostService {

  private string $baseUrl;
  private string $token;
  private string $botName;
  private string $botIcon;

  public function __construct() {
    $this->baseUrl = rtrim((string)cfg('mattermost.server_url', ''), '/');
    $this->token   = (string)cfg('mattermost.bot_token', '');
    $this->botName = (string)cfg('mattermost.bot_name', 'FitnessBot');
    $this->botIcon = (string)cfg('mattermost.bot_icon', ':apple:');
  }

  public function isEnabled(): bool {
    return cfg('mattermost.server_url', '') !== ''
        && cfg('mattermost.bot_token', '') !== '';
  }

  // Üzenet küldése – alapból a DM channel-be (ha dm_channel_id be van állítva)
  public function send(string $text, string $channelName = ''): bool {
    if (!$this->isEnabled()) return false;

    // Gyors út: előre konfigurált DM channel
    $dmChannelId = (string)cfg('mattermost.dm_channel_id', '');
    if ($channelName === '' && $dmChannelId !== '') {
      return $this->postMessage($dmChannelId, $text);
    }

    if ($channelName === '') $channelName = (string)cfg('mattermost.channel', 'fitness');
    $channelId = $this->resolveChannelId($channelName);
    if (!$channelId) return false;

    return $this->postMessage($channelId, $text);
  }

  // DM küldése – előre cache-elt channel ID-val vagy username alapján
  public function sendDm(string $username, string $text): bool {
    if (!$this->isEnabled()) return false;

    // Ha a konfigurált target usernek küldünk, használjuk a cache-elt channel ID-t
    $dmChannelId = (string)cfg('mattermost.dm_channel_id', '');
    if ($dmChannelId !== '' && $username === (string)cfg('mattermost.target_user', '')) {
      return $this->postMessage($dmChannelId, $text);
    }

    $botId    = $this->getBotUserId();
    $targetId = $this->getUserId($username);
    if (!$botId || !$targetId) return false;

    $dmChannelId = $this->getOrCreateDmChannel($botId, $targetId);
    if (!$dmChannelId) return false;

    return $this->postMessage($dmChannelId, $text);
  }

  // Slack-attachment stílusú üzenet
  public function sendAttachment(string $title, string $text, string $color = '#22c55e', array $fields = [], string $channelName = ''): bool {
    if (!$this->isEnabled()) return false;
    if ($channelName === '') $channelName = (string)cfg('mattermost.channel', 'fitness');

    $channelId = $this->resolveChannelId($channelName);
    if (!$channelId) return false;

    $payload = [
      'channel_id' => $channelId,
      'message'    => '',
      'props'      => [
        'attachments' => [[
          'title'  => $title,
          'text'   => $text,
          'color'  => $color,
          'fields' => array_map(
            fn($k, $v) => ['title' => $k, 'value' => (string)$v, 'short' => true],
            array_keys($fields), $fields
          ),
        ]],
      ],
    ];

    return $this->apiPost('/api/v4/posts', $payload) !== null;
  }

  public function validateToken(string $token, string $type = 'outgoing'): bool {
    $key      = $type === 'slash' ? 'mattermost.slash_token' : 'mattermost.outgoing_token';
    $expected = (string)cfg($key, '');
    if ($expected === '' || $token === '') return false;
    return hash_equals($expected, $token);
  }

  // Felhasználónév alapján megkeresi a user_id-t és a bot↔user DM channel_id-t
  public function lookupDmChannel(string $username): array {
    $user = $this->apiGet('/api/v4/users/username/' . urlencode($username));
    if (!$user || empty($user['id'])) {
      return ['error' => "Felhasználó nem található: @{$username}"];
    }
    $userId = $user['id'];

    $botId = $this->getBotUserId();
    if (!$botId) {
      return ['error' => 'Bot user ID nem lekérdezhető (ellenőrizd a bot_token-t).'];
    }

    $channel = $this->apiPost('/api/v4/channels/direct', [$botId, $userId]);
    if (!$channel || empty($channel['id'])) {
      return ['error' => 'DM channel létrehozása / lekérése sikertelen.'];
    }

    return [
      'username'   => $username,
      'user_id'    => $userId,
      'bot_id'     => $botId,
      'channel_id' => $channel['id'],
    ];
  }

  // --- Belső metódusok ---

  private function postMessage(string $channelId, string $text): bool {
    $result = $this->apiPost('/api/v4/posts', [
      'channel_id' => $channelId,
      'message'    => $text,
      'props'      => ['from_bot' => true],
    ]);

    $this->log($text, $result !== null ? 200 : 0);
    return $result !== null;
  }

  private function resolveChannelId(string $channelName): ?string {
    static $cache = [];
    if (isset($cache[$channelName])) return $cache[$channelName];

    // Team ID lekérdezés (első team)
    $teams = $this->apiGet('/api/v4/teams');
    if (!$teams || empty($teams[0]['id'])) return null;
    $teamId = $teams[0]['id'];

    $channel = $this->apiGet("/api/v4/teams/{$teamId}/channels/name/{$channelName}");
    $id = $channel['id'] ?? null;
    if ($id) $cache[$channelName] = $id;
    return $id;
  }

  private function getBotUserId(): ?string {
    static $id = null;
    if ($id) return $id;
    $me = $this->apiGet('/api/v4/users/me');
    $id = $me['id'] ?? null;
    return $id;
  }

  private function getUserId(string $username): ?string {
    static $cache = [];
    if (isset($cache[$username])) return $cache[$username];
    $user = $this->apiGet('/api/v4/users/username/' . urlencode($username));
    $id = $user['id'] ?? null;
    if ($id) $cache[$username] = $id;
    return $id;
  }

  private function getOrCreateDmChannel(string $botId, string $targetId): ?string {
    $result = $this->apiPost('/api/v4/channels/direct', [$botId, $targetId]);
    return $result['id'] ?? null;
  }

  private function apiGet(string $path): ?array {
    $ch = curl_init($this->baseUrl . $path);
    curl_setopt_array($ch, [
      CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 8,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !$body) return null;
    return json_decode((string)$body, true) ?: null;
  }

  private function apiPost(string $path, mixed $payload): ?array {
    $ch = curl_init($this->baseUrl . $path);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'],
      CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 8,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !$body) return null;
    return json_decode((string)$body, true) ?: null;
  }

  private function log(string $content, int $code): void {
    try {
      db()->prepare("INSERT INTO mm_interactions (user_id, message_type, content) VALUES (1, 'checkin', ?)")
        ->execute([mb_substr($content, 0, 500)]);
    } catch (Throwable $e) {}
  }
}

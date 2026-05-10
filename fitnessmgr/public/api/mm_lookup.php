<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/functions.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/Services/MattermostService.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) { http_response_code(401); echo '{"error":"Nincs bejelentkezve"}'; exit; }

$mm     = new MattermostService();
$action = $_GET['action'] ?? 'lookup';

if ($action === 'test') {
  if (!$mm->isEnabled()) {
    echo json_encode(['ok' => false, 'error' => 'Mattermost nincs konfigurálva.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $ok = $mm->send('🔔 Teszt üzenet a Fitness Manager-ből – minden rendben!');
  echo json_encode(
    $ok ? ['ok' => true, 'msg' => 'Üzenet elküldve!'] : ['ok' => false, 'error' => 'Küldés sikertelen – ellenőrizd a config.php beállításait.'],
    JSON_UNESCAPED_UNICODE
  );
  exit;
}

// action=lookup (alapértelmezett) – felhasználónév → user_id + dm_channel_id
$username = trim($_GET['username'] ?? '');
if ($username === '') {
  echo json_encode(['error' => 'Hiányzó username paraméter.'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!$mm->isEnabled()) {
  echo json_encode(['error' => 'Mattermost nincs konfigurálva (server_url / bot_token hiányzik).'], JSON_UNESCAPED_UNICODE);
  exit;
}

$result = $mm->lookupDmChannel($username);
echo json_encode($result, JSON_UNESCAPED_UNICODE);

<?php
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/mailer.php';

date_default_timezone_set('Europe/Budapest');

const PP_STATUS_SYNC_TO = 'marvin@kalamar.hu';

$logFile      = __DIR__ . '/../../storage/logs/pp_status_sync.log';
$stateFile    = __DIR__ . '/../../storage/pp_status_sync_hash.txt';
$payloadFile  = __DIR__ . '/../../storage/pp_status_last_payload.json';

function sync_log(string $msg): void
{
    global $logFile;
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

try {
    $rows = db()->query("
        SELECT id, name, color_hex
        FROM pp_status
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $payload = [
        'source'   => 'pp_system',
        'type'     => 'pp_status_sync',
        'sent_at'  => date('c'),
        'count'    => count($rows),
        'statuses' => $rows,
    ];

    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException('Nem sikerült JSON-t készíteni a pp_status listából.');
    }

    $currentHash = hash('sha256', $json);
    $previousHash = is_file($stateFile) ? trim((string)file_get_contents($stateFile)) : '';

    if ($currentHash === $previousHash) {
        sync_log('Nincs változás a pp_status táblában, nem küldött e-mailt.');
        exit(0);
    }

    $subject = 'PP status sync változás';
    $body = ''
        . "<p>A <strong>pp_status</strong> tábla tartalma megváltozott.</p>"
        . "<p>Időpont: " . htmlspecialchars(date('Y-m-d H:i:s')) . "</p>"
        . "<p>Rekordok száma: " . count($rows) . "</p>"
        . "<pre style=\"font-family: monospace; white-space: pre-wrap;\">"
        . htmlspecialchars($json, ENT_QUOTES, 'UTF-8')
        . "</pre>";

    [$ok, $err] = app_mail_send(PP_STATUS_SYNC_TO, $subject, $body);

    if (!$ok) {
        sync_log('E-mail küldési hiba: ' . ($err ?: 'ismeretlen hiba'));
        exit(1);
    }

    @file_put_contents($stateFile, $currentHash);
    @file_put_contents($payloadFile, $json);

    sync_log('Változás észlelve, e-mail elküldve a címre: ' . PP_STATUS_SYNC_TO);
    exit(0);

} catch (Throwable $e) {
    sync_log('HIBA: ' . $e->getMessage());
    exit(1);
}
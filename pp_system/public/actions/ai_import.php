<?php
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/config.php';

header('Content-Type: application/json; charset=utf-8');

function ai_log(string $status, string $note, ?array $payload = null, ?string $eventus = null, ?int $recordId = null): void {
    $line = sprintf(
        "[%s] [%s] %s | eventus=%s | record_id=%s | payload=%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($status),
        $note,
        $eventus ?? '',
        $recordId ?? '',
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents(AI_IMPORT_LOG, $line, FILE_APPEND);

    try {
        $st = db()->prepare("
            INSERT INTO ai_import_log (eventus, payload_json, status, note, record_id)
            VALUES (?,?,?,?,?)
        ");
        $st->execute([
            $eventus,
            $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            $status,
            $note,
            $recordId
        ]);
    } catch (Throwable $e) {
        @file_put_contents(AI_IMPORT_LOG, "[DBLOG-ERROR] ".$e->getMessage()."\n", FILE_APPEND);
    }
}

function json_out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function find_or_create_city(PDO $db, string $cityName): int {
    $cityName = trim($cityName);
    if ($cityName === '') {
        throw new RuntimeException('Hiányzó település');
    }

    $st = $db->prepare("SELECT id FROM cities WHERE name = ?");
    $st->execute([$cityName]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;

    $st = $db->prepare("INSERT INTO cities (name) VALUES (?)");
    $st->execute([$cityName]);
    return (int)$db->lastInsertId();
}

function find_status_id(PDO $db, string $statusName): int {
    $statusName = trim($statusName);

    if ($statusName !== '') {
        $st = $db->prepare("SELECT id FROM pp_status WHERE name = ?");
        $st->execute([$statusName]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;
    }

    $st = $db->prepare("SELECT id FROM pp_status WHERE name = ?");
    $st->execute([AI_IMPORT_DEFAULT_STATUS_NAME]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;

    throw new RuntimeException('Nem található alap PP státusz');
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(405, ['ok' => false, 'error' => 'Method not allowed']);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        ai_log('error', 'Érvénytelen JSON');
        json_out(400, ['ok' => false, 'error' => 'Érvénytelen JSON']);
    }

    $apiKey = $data['api_key'] ?? '';
    if ($apiKey !== AI_IMPORT_API_KEY) {
        ai_log('error', 'Hibás API kulcs', $data);
        json_out(403, ['ok' => false, 'error' => 'Forbidden']);
    }

    $eventus   = substr(trim((string)($data['eventus'] ?? '')), 0, 15);
    $issued_at = substr(trim((string)($data['issued_at'] ?? '')), 0, 10);
    $due_at    = substr(trim((string)($data['due_at'] ?? '')), 0, 10);
    $cityName  = trim((string)($data['city'] ?? ''));
    $address   = substr(trim((string)($data['address'] ?? '')), 0, 190);
    $operation = substr(trim((string)($data['operation'] ?? '')), 0, 120);
    $long_desc = trim((string)($data['long_desc'] ?? ''));
    $pp_status = trim((string)($data['pp_status'] ?? ''));

    if ($eventus === '' || $issued_at === '' || $cityName === '' || $address === '' || $operation === '') {
        ai_log('error', 'Hiányzó kötelező mező', $data, $eventus ?: null);
        json_out(400, ['ok' => false, 'error' => 'Hiányzó kötelező mező']);
    }

    if ($due_at === '') {
        $due_at = calc_due($issued_at);
    }

    $db = db();

    // duplikáció védelem
    $st = $db->prepare("SELECT id FROM records WHERE eventus = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$eventus]);
    $existingId = $st->fetchColumn();
    if ($existingId) {
        ai_log('skip', 'Már létező eventus', $data, $eventus, (int)$existingId);
        json_out(200, ['ok' => true, 'status' => 'skip', 'record_id' => (int)$existingId]);
    }

    $city_id = find_or_create_city($db, $cityName);
    $pp_status_id = find_status_id($db, $pp_status);

    $st = $db->prepare("
        INSERT INTO records
        (eventus, pp_status_id, issued_at, due_at, city_id, address, operation, long_desc, archived, created_by)
        VALUES (?,?,?,?,?,?,?,?,0,?)
    ");
    $st->execute([
        $eventus,
        $pp_status_id,
        $issued_at,
        $due_at,
        $city_id,
        $address,
        $operation,
        $long_desc !== '' ? $long_desc : null,
        AI_IMPORT_CREATED_BY
    ]);

    $recordId = (int)$db->lastInsertId();

    // napló a record_changes-be
    try {
        log_change($db, $recordId, AI_IMPORT_CREATED_BY, 'ai_import', '', 'AI importból létrehozva');
    } catch (Throwable $e) {
        @file_put_contents(AI_IMPORT_LOG, "[WARN] record_changes log hiba: ".$e->getMessage()."\n", FILE_APPEND);
    }

    ai_log('ok', 'Rekord létrehozva', $data, $eventus, $recordId);

    json_out(200, [
        'ok' => true,
        'status' => 'created',
        'record_id' => $recordId
    ]);

} catch (Throwable $e) {
    ai_log('error', $e->getMessage(), $data ?? null, $eventus ?? null);
    json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
}
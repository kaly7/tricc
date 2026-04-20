<?php
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!defined('AI_IMPORT_API_KEY')) {
    define('AI_IMPORT_API_KEY', 'csereld-le-egy-sajat-titkos-kulcsra');
}
if (!defined('AI_IMPORT_CREATED_BY')) {
    define('AI_IMPORT_CREATED_BY', 1); // admin user ID
}
if (!defined('AI_IMPORT_DEFAULT_STATUS_NAME')) {
    define('AI_IMPORT_DEFAULT_STATUS_NAME', 'Új');
}
if (!defined('AI_IMPORT_TEST_LOG')) {
    define('AI_IMPORT_TEST_LOG', __DIR__ . '/../../storage/logs/ai_import_test.log');
}

function ai_test_log(string $level, string $message, ?array $payload = null): void
{
    $line = sprintf(
        "[%s] [%s] %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $payload ? ' | ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents(AI_IMPORT_TEST_LOG, $line, FILE_APPEND);
}

function respond(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function is_valid_date_ymd(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value;
}

function find_city_id(PDO $db, string $cityName): ?int
{
    $st = $db->prepare("SELECT id FROM cities WHERE name = ?");
    $st->execute([$cityName]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function find_status_id(PDO $db, string $statusName): ?int
{
    $st = $db->prepare("SELECT id FROM pp_status WHERE name = ?");
    $st->execute([$statusName]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function find_default_status_id(PDO $db): ?int
{
    $st = $db->prepare("SELECT id FROM pp_status WHERE name = ?");
    $st->execute([AI_IMPORT_DEFAULT_STATUS_NAME]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function find_existing_record_id(PDO $db, string $eventus): ?int
{
    $st = $db->prepare("SELECT id FROM records WHERE eventus = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$eventus]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, [
            'ok' => false,
            'mode' => 'test',
            'error' => 'Csak POST engedélyezett'
        ]);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        ai_test_log('error', 'Érvénytelen JSON');
        respond(400, [
            'ok' => false,
            'mode' => 'test',
            'error' => 'Érvénytelen JSON'
        ]);
    }

    $apiKey = trim((string)($data['api_key'] ?? ''));
    if ($apiKey !== AI_IMPORT_API_KEY) {
        ai_test_log('error', 'Hibás API kulcs', $data);
        respond(403, [
            'ok' => false,
            'mode' => 'test',
            'error' => 'Hibás API kulcs'
        ]);
    }

    $eventus   = substr(trim((string)($data['eventus'] ?? '')), 0, 15);
    $issued_at = substr(trim((string)($data['issued_at'] ?? '')), 0, 10);
    $due_at    = substr(trim((string)($data['due_at'] ?? '')), 0, 10);
    $city      = trim((string)($data['city'] ?? ''));
    $address   = substr(trim((string)($data['address'] ?? '')), 0, 190);
    $operation = substr(trim((string)($data['operation'] ?? '')), 0, 120);
    $long_desc = trim((string)($data['long_desc'] ?? ''));
    $pp_status = trim((string)($data['pp_status'] ?? ''));
    $confidence = $data['confidence'] ?? null;

    $errors = [];
    $warnings = [];

    if ($eventus === '')  $errors[] = 'Hiányzik az eventus';
    if ($issued_at === '') $errors[] = 'Hiányzik az issued_at';
    if ($city === '')     $errors[] = 'Hiányzik a city';
    if ($address === '')  $errors[] = 'Hiányzik az address';
    if ($operation === '') $errors[] = 'Hiányzik az operation';

    if ($issued_at !== '' && !is_valid_date_ymd($issued_at)) {
        $errors[] = 'Érvénytelen issued_at formátum (YYYY-MM-DD kell)';
    }

    if ($due_at !== '' && !is_valid_date_ymd($due_at)) {
        $errors[] = 'Érvénytelen due_at formátum (YYYY-MM-DD kell)';
    }

    if ($due_at === '' && $issued_at !== '' && is_valid_date_ymd($issued_at)) {
        try {
            $due_at = calc_due($issued_at);
            $warnings[] = 'A due_at hiányzott, ezért calc_due() alapján lett kiszámolva';
        } catch (Throwable $e) {
            $errors[] = 'A due_at hiányzott és nem sikerült kiszámolni: ' . $e->getMessage();
        }
    }

    $db = db();

    $existingRecordId = $eventus !== '' ? find_existing_record_id($db, $eventus) : null;
    if ($existingRecordId) {
        $warnings[] = 'Már létezik ilyen eventus a records táblában';
    }

    $cityId = null;
    if ($city !== '') {
        $cityId = find_city_id($db, $city);
        if (!$cityId) {
            $warnings[] = 'A település még nincs a cities táblában, éles módban létre kellene hozni';
        }
    }

    $statusId = null;
    if ($pp_status !== '') {
        $statusId = find_status_id($db, $pp_status);
        if (!$statusId) {
            $warnings[] = 'A megadott pp_status név nem található, fallback kellene az alap státuszra';
        }
    } else {
        $warnings[] = 'A pp_status hiányzik, fallback kellene az alap státuszra';
    }

    $defaultStatusId = find_default_status_id($db);
    if (!$statusId && $defaultStatusId) {
        $statusId = $defaultStatusId;
        $warnings[] = 'A rendszer az alap státuszt használná: ' . AI_IMPORT_DEFAULT_STATUS_NAME;
    }
    if (!$statusId) {
        $errors[] = 'Nem található használható PP státusz';
    }

    $result = [
        'ok' => count($errors) === 0,
        'mode' => 'test',
        'received' => [
            'eventus' => $eventus,
            'issued_at' => $issued_at,
            'due_at' => $due_at,
            'city' => $city,
            'address' => $address,
            'operation' => $operation,
            'pp_status' => $pp_status,
            'confidence' => $confidence,
            'long_desc_length' => mb_strlen($long_desc),
        ],
        'checks' => [
            'record_exists' => (bool)$existingRecordId,
            'existing_record_id' => $existingRecordId,
            'city_found' => (bool)$cityId,
            'city_id' => $cityId,
            'status_found' => (bool)$statusId,
            'status_id' => $statusId,
            'default_status_name' => AI_IMPORT_DEFAULT_STATUS_NAME,
            'created_by' => AI_IMPORT_CREATED_BY,
        ],
        'would_insert' => [
            'eventus' => $eventus,
            'pp_status_id' => $statusId,
            'issued_at' => $issued_at,
            'due_at' => $due_at,
            'city_id' => $cityId,
            'address' => $address,
            'operation' => $operation,
            'long_desc' => $long_desc,
            'archived' => 0,
            'created_by' => AI_IMPORT_CREATED_BY,
        ],
        'warnings' => $warnings,
        'errors' => $errors,
        'db_write_performed' => false
    ];

    ai_test_log(
        count($errors) ? 'warn' : 'info',
        count($errors) ? 'AI import teszt hibával futott' : 'AI import teszt rendben lefutott',
        $result
    );

    respond(200, $result);

} catch (Throwable $e) {
    ai_test_log('error', 'Kivétel: ' . $e->getMessage());
    respond(500, [
        'ok' => false,
        'mode' => 'test',
        'error' => $e->getMessage()
    ]);
}
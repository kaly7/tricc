<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * A warehousemgr központi függvénygyűjteménye.
 * Ebben a fájlban van a fő üzleti logika: raktárak, anyagok, készlet, átadások,
 * azonnosítók, archiválás, audit és ideiglenes beolvasás kezelése.
 */

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}


// -----------------------------------------------------------------------------
// Kérés / URL segédfüggvények
// HTTPS, hostnév és mobil szkenner URL előállítása.
// -----------------------------------------------------------------------------
function warehouse_request_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
        if (str_contains($proto, 'https')) {
            return true;
        }
    }
    return ((int)($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

function warehouse_request_port(): int {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
        return (int)$_SERVER['HTTP_X_FORWARDED_PORT'];
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0);
}

function warehouse_current_host_name(): string {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') {
        return 'localhost';
    }
    if ($host[0] === '[') {
        $end = strpos($host, ']');
        return $end !== false ? substr($host, 0, $end + 1) : $host;
    }
    return (string)preg_replace('/:\d+$/', '', $host);
}

function warehouse_https_port_url(string $uri = '/', int $port = 9444): string {
    $normalizedUri = trim($uri) !== '' ? trim($uri) : '/';
    if (!preg_match('~^https?://~i', $normalizedUri)) {
        if ($normalizedUri[0] !== '/') {
            $normalizedUri = '/' . $normalizedUri;
        }
        $normalizedUri = 'https://' . warehouse_current_host_name() . ($port > 0 ? ':' . $port : '') . $normalizedUri;
    }
    return $normalizedUri;
}

function warehouse_mobile_scanner_url(string $uri = '/identifier_staging_mobile.php'): string {
    return warehouse_https_port_url($uri, 9444);
}

function current_auth_user_id(): int {
    $candidates = [
        $_SESSION['user_id'] ?? null,
        $_SESSION['uid'] ?? null,
        $_SESSION['auth_user_id'] ?? null,
        $_SESSION['user']['id'] ?? null,
        $_SESSION['user']['user_id'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if ($candidate !== null && $candidate !== '') {
            return (int)$candidate;
        }
    }
    return 0;
}

function current_auth_display_name(): string {
    if (!empty($_SESSION['full_name'])) {
        return (string)$_SESSION['full_name'];
    }
    if (!empty($_SESSION['user']['full_name'])) {
        return (string)$_SESSION['user']['full_name'];
    }
    if (!empty($_SESSION['username'])) {
        return (string)$_SESSION['username'];
    }
    if (!empty($_SESSION['user']['username'])) {
        return (string)$_SESSION['user']['username'];
    }
    return 'Felhasználó';
}

function flash_set(string $type, string $message): void {
    $_SESSION['_flash'][$type] = $message;
}

function flash_get(string $type): string {
    $value = (string)($_SESSION['_flash'][$type] ?? '');
    unset($_SESSION['_flash'][$type]);
    return $value;
}

// -----------------------------------------------------------------------------
// Jogosultságok és alap raktárfa lekérdezések
// -----------------------------------------------------------------------------
function warehouse_module_admin(array $config): bool {
    return CentralAuth::isAdmin($config, $config['module_key']);
}

function warehouse_role_label(string $roleKey): string {
    return match ($roleKey) {
        'admin' => 'Admin',
        'user' => 'Kezelő',
        default => 'Megtekintő',
    };
}

function warehouse_all(array $config): array {
    $pdo = warehouse_pdo($config);
    $sql = "
        SELECT w.*,
               COALESCE(NULLIF(w.warehouse_type, ''), 'internal') AS warehouse_type,
               pw.name AS parent_name,
               p.partner_name,
               p.receiver_name AS partner_receiver_name,
               p.phone AS partner_phone,
               p.email AS partner_email,
               (
                 SELECT COUNT(*)
                 FROM warehouse_user_access wua
                 WHERE wua.warehouse_id = w.id
               ) AS access_count,
               (
                 SELECT COUNT(*)
                 FROM warehouses cw
                 WHERE cw.parent_id = w.id
               ) AS child_count
        FROM warehouses w
        LEFT JOIN warehouses pw ON pw.id = w.parent_id
        LEFT JOIN warehouse_partners p ON p.id = w.partner_id
        ORDER BY COALESCE(pw.name, w.name), w.name
    ";
    return $pdo->query($sql)->fetchAll();
}

function warehouse_tree_options(array $config, ?int $selectedId = null): string {
    $rows = warehouse_all($config);
    $out = '<option value="">— nincs —</option>';
    foreach ($rows as $r) {
        $label = ((int)$r['parent_id'] > 0 ? '↳ ' : '') . (string)$r['name'];
        $sel = ((int)$selectedId === (int)$r['id']) ? ' selected' : '';
        $out .= '<option value="' . (int)$r['id'] . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $out;
}

function warehouse_find(array $config, int $warehouseId): ?array {
    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare("SELECT w.*, COALESCE(NULLIF(w.warehouse_type, ''), 'internal') AS warehouse_type, pw.name AS parent_name, p.partner_name, p.receiver_name AS partner_receiver_name, p.phone AS partner_phone, p.email AS partner_email FROM warehouses w LEFT JOIN warehouses pw ON pw.id=w.parent_id LEFT JOIN warehouse_partners p ON p.id = w.partner_id WHERE w.id=? LIMIT 1");
    $st->execute([$warehouseId]);
    $row = $st->fetch();
    return $row ?: null;
}

function warehouse_request_context(): array {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (strlen($uri) > 255) {
        $uri = substr($uri, 0, 255);
    }

    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen($userAgent) > 255) {
        $userAgent = substr($userAgent, 0, 255);
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (strlen($ip) > 64) {
        $ip = substr($ip, 0, 64);
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (strlen($method) > 10) {
        $method = substr($method, 0, 10);
    }

    return [
        'ip_address' => $ip !== '' ? $ip : null,
        'request_uri' => $uri !== '' ? $uri : null,
        'request_method' => $method !== '' ? $method : null,
        'user_agent' => $userAgent !== '' ? $userAgent : null,
    ];
}

function warehouse_audit(array $config, string $action, string $entityType, ?int $entityId, array $details = []): void {
    $pdo = warehouse_pdo($config);
    $ctx = warehouse_request_context();
    $st = $pdo->prepare(
        "INSERT INTO audit_log (auth_user_id, action_key, entity_type, entity_id, details_json, ip_address, request_uri, request_method, user_agent)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $st->execute([
        current_auth_user_id(),
        $action,
        $entityType,
        $entityId,
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $ctx['ip_address'],
        $ctx['request_uri'],
        $ctx['request_method'],
        $ctx['user_agent'],
    ]);
}

function warehouse_resolved_auth_users(array $config): array {
    $auth = auth_pdo($config);
    $rows = $auth->query("SELECT id, username, email, full_name, hr_employee_id, is_active FROM users WHERE is_active=1 ORDER BY full_name, username")->fetchAll();

    $hrMap = [];
    $hr = warehouse_hr_pdo($config);
    if ($hr instanceof PDO) {
        foreach ($hr->query("SELECT id, full_name, is_active FROM employees ORDER BY full_name") as $e) {
            $hrMap[(int)$e['id']] = $e;
        }
    }

    foreach ($rows as &$row) {
        $empId = (int)($row['hr_employee_id'] ?? 0);
        $row['resolved_name'] = $row['full_name'];
        if ($empId > 0 && isset($hrMap[$empId]) && !empty($hrMap[$empId]['full_name'])) {
            $row['resolved_name'] = $hrMap[$empId]['full_name'];
        }
    }
    unset($row);

    usort($rows, static function(array $a, array $b): int {
        return strcasecmp((string)$a['resolved_name'], (string)$b['resolved_name']);
    });

    return $rows;
}

function warehouse_resolved_auth_user_map(array $config, array $onlyIds = []): array {
    $users = warehouse_resolved_auth_users($config);
    $filter = [];
    if ($onlyIds !== []) {
        foreach ($onlyIds as $id) {
            $filter[(int)$id] = true;
        }
    }

    $map = [];
    foreach ($users as $user) {
        $id = (int)$user['id'];
        if ($filter !== [] && !isset($filter[$id])) {
            continue;
        }
        $map[$id] = $user;
    }
    return $map;
}

function warehouse_access_list(array $config, int $warehouseId): array {
    $pdo = warehouse_pdo($config);
    $items = $pdo->prepare("SELECT id, warehouse_id, auth_user_id, role_key, created_at FROM warehouse_user_access WHERE warehouse_id=? ORDER BY role_key, auth_user_id");
    $items->execute([$warehouseId]);
    $items = $items->fetchAll();

    $map = warehouse_resolved_auth_user_map($config);
    foreach ($items as &$item) {
        $uid = (int)$item['auth_user_id'];
        $item['resolved_name'] = $map[$uid]['resolved_name'] ?? ('User #' . $uid);
        $item['username'] = $map[$uid]['username'] ?? '';
        $item['email'] = $map[$uid]['email'] ?? '';
    }
    unset($item);

    return $items;
}

function warehouse_collect_subtree_rows(array $config, int $rootWarehouseId): array {
    $pdo = warehouse_pdo($config);
    $rows = $pdo->query("SELECT * FROM warehouses ORDER BY name, id")->fetchAll();
    $byParent = [];
    $byId = [];

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $parentId = (int)($row['parent_id'] ?? 0);
        $byId[$id] = $row;
        $byParent[$parentId][] = $id;
    }

    $result = [];
    $walk = static function(int $id, int $depth) use (&$walk, &$result, $byId, $byParent): void {
        if (!isset($byId[$id])) {
            return;
        }
        $row = $byId[$id];
        $row['depth'] = $depth;
        $result[] = $row;
        foreach ($byParent[$id] ?? [] as $childId) {
            $walk((int)$childId, $depth + 1);
        }
    };

    $walk($rootWarehouseId, 0);
    return $result;
}

function warehouse_storage_path(string $relative = ''): string {
    $base = __DIR__ . '/../storage';
    if ($relative === '') {
        return $base;
    }
    return $base . '/' . ltrim($relative, '/');
}

function warehouse_table_exists(PDO $pdo, string $tableName): bool {
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $st = $pdo->prepare('SHOW TABLES LIKE ?');
    $st->execute([$tableName]);
    $cache[$tableName] = (bool)$st->fetchColumn();
    return $cache[$tableName];
}

function warehouse_delete_subtree_nonempty_stock(array $config, int $rootWarehouseId): array {
    $rows = warehouse_collect_subtree_rows($config, $rootWarehouseId);
    if ($rows === []) {
        return [];
    }

    warehouse_stock_sync_table_from_movements($config);

    $pdo = warehouse_pdo($config);
    $ids = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $rows)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
        SELECT ws.warehouse_id,
               ws.material_id,
               ws.quantity,
               w.name AS warehouse_name,
               w.code AS warehouse_code,
               m.sku,
               m.name AS material_name,
               m.unit
        FROM warehouse_stock ws
        INNER JOIN warehouses w ON w.id = ws.warehouse_id
        INNER JOIN material_items m ON m.id = ws.material_id
        WHERE ws.warehouse_id IN ($placeholders)
          AND ABS(ws.quantity) > 0.0005
        ORDER BY w.name, m.name, m.sku
    ";

    $st = $pdo->prepare($sql);
    $st->execute($ids);
    return $st->fetchAll();
}

function warehouse_delete_archive_build(array $config, int $rootWarehouseId, array $rows): array {
    $pdo = warehouse_pdo($config);
    $ids = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $rows)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $transferRows = [];
    $transferIds = [];
    if (warehouse_table_exists($pdo, 'stock_transfers')) {
        $st = $pdo->prepare("SELECT * FROM stock_transfers WHERE source_warehouse_id IN ($placeholders) OR target_warehouse_id IN ($placeholders) ORDER BY id ASC");
        $st->execute(array_merge($ids, $ids));
        $transferRows = $st->fetchAll();
        $transferIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $transferRows)));
    }

    $transferItems = [];
    if ($transferIds !== [] && warehouse_table_exists($pdo, 'stock_transfer_items')) {
        $transferPlaceholders = implode(',', array_fill(0, count($transferIds), '?'));
        $st = $pdo->prepare("SELECT * FROM stock_transfer_items WHERE transfer_id IN ($transferPlaceholders) ORDER BY id ASC");
        $st->execute($transferIds);
        $transferItems = $st->fetchAll();
    }

    $partnerIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['partner_id'] ?? 0),
        $rows
    ), static fn(int $id): bool => $id > 0)));
    $partnerRows = [];
    if ($partnerIds !== [] && warehouse_table_exists($pdo, 'warehouse_partners')) {
        $partnerPlaceholders = implode(',', array_fill(0, count($partnerIds), '?'));
        $st = $pdo->prepare("SELECT * FROM warehouse_partners WHERE id IN ($partnerPlaceholders) ORDER BY id ASC");
        $st->execute($partnerIds);
        $partnerRows = $st->fetchAll();
    }

    $auditRows = [];
    if (warehouse_table_exists($pdo, 'audit_log')) {
        $parts = [];
        $params = [];

        $parts[] = "(entity_type = 'warehouse' AND entity_id IN ($placeholders))";
        $params = array_merge($params, $ids);

        if ($transferIds !== []) {
            $transferPlaceholders = implode(',', array_fill(0, count($transferIds), '?'));
            $parts[] = "(entity_type = 'stock_transfer' AND entity_id IN ($transferPlaceholders))";
            $params = array_merge($params, $transferIds);
        }

        $st = $pdo->prepare('SELECT * FROM audit_log WHERE ' . implode(' OR ', $parts) . ' ORDER BY id ASC');
        $st->execute($params);
        $auditRows = $st->fetchAll();
    }

    $stAccess = $pdo->prepare("SELECT * FROM warehouse_user_access WHERE warehouse_id IN ($placeholders) ORDER BY id ASC");
    $stAccess->execute($ids);

    $stStock = $pdo->prepare("SELECT * FROM warehouse_stock WHERE warehouse_id IN ($placeholders) ORDER BY id ASC");
    $stStock->execute($ids);

    $stMovements = $pdo->prepare("SELECT * FROM stock_movements WHERE warehouse_id IN ($placeholders) ORDER BY id ASC");
    $stMovements->execute($ids);

    return [
        'archive_version' => 1,
        'generated_at' => date('c'),
        'deleted_by_auth_user_id' => current_auth_user_id(),
        'request_context' => warehouse_request_context(),
        'root_warehouse_id' => $rootWarehouseId,
        'root_warehouse_name' => (string)($rows[0]['name'] ?? ''),
        'subtree_warehouse_ids' => $ids,
        'warehouses' => $rows,
        'partners' => $partnerRows,
        'warehouse_user_access' => $stAccess->fetchAll(),
        'warehouse_stock' => $stStock->fetchAll(),
        'stock_movements' => $stMovements->fetchAll(),
        'stock_transfers' => $transferRows,
        'stock_transfer_items' => $transferItems,
        'audit_log' => $auditRows,
    ];
}

function warehouse_delete_archive_write(array $payload): array {
    $dir = warehouse_storage_path('archive/warehouse_delete');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nem sikerült létrehozni a raktár-archívum könyvtárát.');
    }

    $rootId = (int)($payload['root_warehouse_id'] ?? 0);
    $stamp = date('Ymd-His');
    $filename = 'warehouse-delete-' . $stamp . '-root-' . $rootId . '.json';
    $path = $dir . '/' . $filename;

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Nem sikerült JSON archívumot készíteni a törléshez.');
    }
    if (@file_put_contents($path, $json) === false) {
        throw new RuntimeException('Nem sikerült lementeni a törlés előtti archívumot.');
    }

    return [
        'path' => $path,
        'relative_path' => '/storage/archive/warehouse_delete/' . $filename,
        'filename' => $filename,
    ];
}

function warehouse_delete_recursive(array $config, int $rootWarehouseId): array {
    $pdo = warehouse_pdo($config);
    $rows = warehouse_collect_subtree_rows($config, $rootWarehouseId);
    if ($rows === []) {
        throw new RuntimeException('A törlendő raktár nem található.');
    }

    $stockRows = warehouse_delete_subtree_nonempty_stock($config, $rootWarehouseId);
    if ($stockRows !== []) {
        $first = $stockRows[0];
        $label = trim((string)($first['sku'] ?? '') . ' · ' . (string)($first['material_name'] ?? ''), ' ·');
        $message = 'A raktár nem törölhető, mert még van benne készlet.';
        if ($label !== '') {
            $message .= ' Példa: ' . (string)$first['warehouse_name'] . ' / ' . $label . ' = ' . warehouse_quantity_display($first['quantity']);
            if (!empty($first['unit'])) {
                $message .= ' ' . (string)$first['unit'];
            }
            if (count($stockRows) > 1) {
                $message .= ' (+' . (count($stockRows) - 1) . ' további tétel)';
            }
        }
        throw new RuntimeException($message);
    }

    $archivePayload = warehouse_delete_archive_build($config, $rootWarehouseId, $rows);
    $archiveInfo = warehouse_delete_archive_write($archivePayload);

    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $accessCounts = [];
    $stAccess = $pdo->prepare("SELECT warehouse_id, COUNT(*) AS cnt FROM warehouse_user_access WHERE warehouse_id IN ($placeholders) GROUP BY warehouse_id");
    $stAccess->execute($ids);
    foreach ($stAccess->fetchAll() as $row) {
        $accessCounts[(int)$row['warehouse_id']] = (int)$row['cnt'];
    }

    usort($rows, static fn(array $a, array $b): int => ((int)$b['depth']) <=> ((int)$a['depth']));

    $pdo->beginTransaction();
    try {
        $delAccess = $pdo->prepare("DELETE FROM warehouse_user_access WHERE warehouse_id IN ($placeholders)");
        $delAccess->execute($ids);

        $delWarehouse = $pdo->prepare('DELETE FROM warehouses WHERE id=?');
        foreach ($rows as $row) {
            $wid = (int)$row['id'];
            warehouse_audit($config, 'warehouse.delete', 'warehouse', $wid, [
                'name' => (string)$row['name'],
                'code' => (string)$row['code'],
                'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
                'subtree_root_id' => $rootWarehouseId,
                'depth' => (int)$row['depth'],
                'deleted_access_count' => $accessCounts[$wid] ?? 0,
                'archive_file' => $archiveInfo['relative_path'],
            ]);
            $delWarehouse->execute([$wid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'deleted_count' => count($rows),
        'deleted_ids' => $ids,
        'archive_relative_path' => $archiveInfo['relative_path'],
        'archive_filename' => $archiveInfo['filename'],
    ];
}

// -----------------------------------------------------------------------------
// Anyagtörzs, archiválás és CSV import
// -----------------------------------------------------------------------------
function warehouse_materials_all(array $config, bool $activeOnly = false, bool $includeArchived = false): array {
    $pdo = warehouse_pdo($config);
    $where = [];
    if ($activeOnly) {
        $where[] = 'is_active = 1';
    }
    if (warehouse_material_archive_feature_ready($config) && !$includeArchived) {
        $where[] = 'COALESCE(is_archived, 0) = 0';
    }
    $sql = 'SELECT * FROM material_items';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY name, sku';
    return $pdo->query($sql)->fetchAll();
}

function warehouse_material_find(array $config, int $materialId): ?array {
    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare("SELECT * FROM material_items WHERE id=? LIMIT 1");
    $st->execute([$materialId]);
    $row = $st->fetch();
    return $row ?: null;
}

function warehouse_material_archive_feature_ready(array $config): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = warehouse_pdo($config);
        $materialCol = (bool)$pdo->query("SHOW COLUMNS FROM material_items LIKE 'is_archived'")->fetchColumn();
        if (!$materialCol) {
            return $cache = false;
        }
        $identifierTable = (bool)$pdo->query("SHOW TABLES LIKE 'material_identifiers'")->fetchColumn();
        if (!$identifierTable) {
            return $cache = true;
        }
        $identifierCol = (bool)$pdo->query("SHOW COLUMNS FROM material_identifiers LIKE 'is_archived'")->fetchColumn();
        return $cache = $identifierCol;
    } catch (Throwable $e) {
        return $cache = false;
    }
}


function warehouse_material_price_feature_ready(array $config): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = warehouse_pdo($config);
        $hasUnitPrice = (bool)$pdo->query("SHOW COLUMNS FROM material_items LIKE 'unit_price'")->fetchColumn();
        $hasCurrencyCode = (bool)$pdo->query("SHOW COLUMNS FROM material_items LIKE 'currency_code'")->fetchColumn();
        return $cache = ($hasUnitPrice && $hasCurrencyCode);
    } catch (Throwable $e) {
        return $cache = false;
    }
}


function warehouse_material_category_options(array $config, bool $includeArchived = true): array {
    $pdo = warehouse_pdo($config);
    $archiveReady = warehouse_material_archive_feature_ready($config);

    $sql = 'SELECT DISTINCT TRIM(COALESCE(category_name, "")) AS category_name FROM material_items WHERE TRIM(COALESCE(category_name, "")) <> ""';
    $params = [];
    if ($archiveReady && !$includeArchived) {
        $sql .= ' AND COALESCE(is_archived, 0) = 0';
    }
    $sql .= ' ORDER BY category_name ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return array_values(array_filter(array_map(static function ($value): string {
        return trim((string)$value);
    }, $st->fetchAll(PDO::FETCH_COLUMN)), static fn(string $value): bool => $value !== ''));
}

function warehouse_material_list_filters(array $input): array {
    $sort = trim((string)($input['sort'] ?? 'name'));
    if (!in_array($sort, ['sku', 'name', 'category', 'price', 'currency', 'archived'], true)) {
        $sort = 'name';
    }

    $dir = strtolower(trim((string)($input['dir'] ?? 'asc')));
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = 'asc';
    }

    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = max(1, min(200, (int)($input['per_page'] ?? 50)));
    $q = trim((string)($input['q'] ?? ''));
    $category = trim((string)($input['category_name'] ?? ''));
    $includeArchived = in_array((string)($input['include_archived'] ?? ''), ['1', 'on', 'true'], true) ? 1 : 0;

    return [
        'q' => $q,
        'category_name' => $category,
        'sort' => $sort,
        'dir' => $dir,
        'page' => $page,
        'per_page' => $perPage,
        'include_archived' => $includeArchived,
    ];
}

function warehouse_material_list(array $config, array $input = []): array {
    $pdo = warehouse_pdo($config);
    $filters = warehouse_material_list_filters($input);
    $archiveReady = warehouse_material_archive_feature_ready($config);
    $identifierReady = warehouse_material_identifier_feature_ready($config);
    $priceReady = warehouse_material_price_feature_ready($config);

    $where = [];
    $params = [];

    if ($archiveReady && (int)$filters['include_archived'] !== 1) {
        $where[] = 'COALESCE(mi.is_archived, 0) = 0';
    }

    if ($filters['category_name'] !== '') {
        $where[] = 'COALESCE(mi.category_name, "") = ?';
        $params[] = $filters['category_name'];
    }

    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $parts = ['mi.sku LIKE ?', 'mi.name LIKE ?', 'COALESCE(mi.category_name, "") LIKE ?'];
        $qParams = [$like, $like, $like];
        if ($identifierReady) {
            $existsSql = 'EXISTS (
                SELECT 1
                FROM material_identifiers mid
                WHERE mid.material_id = mi.id
                  AND (
                      mid.identifier_value LIKE ?
                      OR COALESCE(mid.secondary_identifier_value, "") LIKE ?
                  )';
            if ($archiveReady && (int)$filters['include_archived'] !== 1) {
                $existsSql .= '
                  AND COALESCE(mid.is_archived, 0) = 0';
            }
            $existsSql .= '
            )';
            $parts[] = $existsSql;
            $qParams[] = $like;
            $qParams[] = $like;
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
        array_push($params, ...$qParams);
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $sortMap = [
        'sku' => 'mi.sku',
        'name' => 'mi.name',
        'category' => 'mi.category_name',
        'price' => $priceReady ? 'COALESCE(mi.unit_price, 0)' : 'mi.name',
        'currency' => $priceReady ? 'COALESCE(mi.currency_code, "")' : 'mi.name',
        'archived' => $archiveReady ? 'COALESCE(mi.is_archived, 0)' : 'mi.name',
    ];
    $orderBy = $sortMap[$filters['sort']] ?? 'mi.name';
    $dir = strtoupper($filters['dir']) === 'DESC' ? 'DESC' : 'ASC';

    $countSt = $pdo->prepare('SELECT COUNT(*) FROM material_items mi' . $whereSql);
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $pages = max(1, (int)ceil($total / max(1, $filters['per_page'])));
    $page = min($filters['page'], $pages);
    $offset = ($page - 1) * $filters['per_page'];

    $sql = 'SELECT mi.* FROM material_items mi' . $whereSql . ' ORDER BY ' . $orderBy . ' ' . $dir . ', mi.name ASC, mi.sku ASC LIMIT ? OFFSET ?';
    $st = $pdo->prepare($sql);
    $index = 1;
    foreach ($params as $param) {
        $st->bindValue($index++, $param, PDO::PARAM_STR);
    }
    $st->bindValue($index++, $filters['per_page'], PDO::PARAM_INT);
    $st->bindValue($index, $offset, PDO::PARAM_INT);
    $st->execute();

    return [
        'rows' => $st->fetchAll(),
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $filters['per_page'],
        'sort' => $filters['sort'],
        'dir' => strtolower($dir),
        'q' => $filters['q'],
        'category_name' => $filters['category_name'],
        'include_archived' => $filters['include_archived'],
        'offset' => $offset,
    ];
}

function warehouse_material_stock_locations(array $config, array $materialIds, bool $onlyNonZero = true): array {
    $pdo = warehouse_pdo($config);
    $materialIds = array_values(array_unique(array_map('intval', $materialIds)));
    if ($materialIds === []) {
        return [];
    }

    warehouse_stock_sync_table_from_movements($config);

    $materialPlaceholders = implode(',', array_fill(0, count($materialIds), '?'));
    $result = [];
    foreach ($materialIds as $mid) {
        $result[$mid] = [
            'material_id' => $mid,
            'material' => [
                'id' => $mid,
                'sku' => '',
                'name' => '',
                'unit' => '',
                'category_name' => '',
            ],
            'locations' => [],
            'total_quantity' => '0.000',
        ];
    }

    $matSql = '
        SELECT id, sku, name, unit, category_name
        FROM material_items
        WHERE id IN (' . $materialPlaceholders . ')
        ORDER BY name, sku
    ';
    $matSt = $pdo->prepare($matSql);
    $matSt->execute($materialIds);
    foreach ($matSt->fetchAll() as $mat) {
        $mid = (int)($mat['id'] ?? 0);
        if (!isset($result[$mid])) {
            continue;
        }
        $result[$mid]['material'] = [
            'id' => $mid,
            'sku' => (string)($mat['sku'] ?? ''),
            'name' => (string)($mat['name'] ?? ''),
            'unit' => (string)($mat['unit'] ?? ''),
            'category_name' => (string)($mat['category_name'] ?? ''),
        ];
    }

    $isModuleAdmin = warehouse_module_admin($config);
    $accessible = warehouse_accessible_warehouses($config, false);
    $allowedIds = $isModuleAdmin ? [] : array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $accessible)));
    if (!$isModuleAdmin && $allowedIds === []) {
        return $result;
    }

    $where = ['ws.material_id IN (' . $materialPlaceholders . ')'];
    $params = $materialIds;

    if (!$isModuleAdmin) {
        $where[] = 'ws.warehouse_id IN (' . implode(',', array_fill(0, count($allowedIds), '?')) . ')';
        $params = array_merge($params, $allowedIds);
    }

    if ($onlyNonZero) {
        $where[] = 'ABS(ws.quantity) > 0.0005';
    }

    $sql = '
        SELECT ws.material_id,
               ws.quantity,
               ws.updated_at,
               w.id AS warehouse_id,
               w.name AS warehouse_name,
               w.code AS warehouse_code,
               w.is_active AS warehouse_is_active
        FROM warehouse_stock ws
        INNER JOIN warehouses w ON w.id = ws.warehouse_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY w.name, w.code
    ';

    $locSt = $pdo->prepare($sql);
    $locSt->execute($params);
    foreach ($locSt->fetchAll() as $row) {
        $mid = (int)$row['material_id'];
        if (!isset($result[$mid])) {
            continue;
        }
        $row['quantity'] = warehouse_decimal_string($row['quantity']);
        $result[$mid]['locations'][] = $row;
        $result[$mid]['total_quantity'] = warehouse_decimal_string(
            (float)$result[$mid]['total_quantity'] + (float)$row['quantity']
        );
    }

    $ordered = [];
    foreach ($materialIds as $mid) {
        if (isset($result[$mid])) {
            $ordered[$mid] = $result[$mid];
        }
    }
    return $ordered;
}

function warehouse_csv_delimiter(string $headerLine): string {
    $semicolon = substr_count($headerLine, ';');
    $comma = substr_count($headerLine, ',');
    return $semicolon > $comma ? ';' : ',';
}

function warehouse_normalize_header(string $value): string {
    $value = trim($value);
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    $map = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ö' => 'o', 'Ő' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ű' => 'u',
    ];
    $value = strtr($value, $map);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
    return trim($value, '_');
}

function warehouse_material_header_map(array $headers): array {
    $aliases = [
        'sku' => ['sku', 'cikkszam', 'cikkszám', 'kod', 'kód', 'anyagkod', 'anyag_kod', 'code'],
        'name' => ['name', 'megnevezes', 'megnevezés', 'anyag', 'nev', 'név'],
        'unit' => ['unit', 'uom', 'mertekegyseg', 'mértékegység', 'mertekegyseg_rovid'],
        'category_name' => ['category', 'kategoria', 'kategória', 'csoport', 'anyagcsoport'],
        'minimum_stock' => ['minimum_stock', 'minimum', 'minimum_keszlet', 'minimum_készlet', 'min_stock', 'min_keszlet'],
        'note' => ['note', 'comment', 'megjegyzes', 'megjegyzés', 'leiras', 'leírás'],
        'is_active' => ['is_active', 'active', 'aktiv', 'aktív'],
        'is_identified' => ['is_identified', 'identified', 'egyedi_azonositos', 'egyedi_azonosítós', 'azonositos', 'azonosítós', 'serial_tracked'],
        'identifier_label' => ['identifier_label', 'azonosito_tipus', 'azonosító_típus', 'azonosito_nev', 'azonosító_név', 'serial_label', 'identifier_type'],
        'unit_price' => ['unit_price', 'price', 'egysegar', 'egységár', 'ar', 'ár', 'netto_ar', 'nettó_ár'],
        'currency_code' => ['currency_code', 'currency', 'penznem', 'pénznem', 'deviza', 'currency_code_iso'],
    ];

    $normalized = [];
    foreach ($headers as $index => $header) {
        $normalized[$index] = warehouse_normalize_header((string)$header);
    }

    $result = [];
    foreach ($aliases as $target => $options) {
        $normalizedAliases = array_map('warehouse_normalize_header', $options);
        foreach ($normalized as $index => $norm) {
            if (in_array($norm, $normalizedAliases, true)) {
                $result[$target] = $index;
                break;
            }
        }
    }

    return $result;
}

function warehouse_material_bool(mixed $value): int {
    $value = strtolower(trim((string)$value));
    if ($value === '') {
        return 1;
    }
    return in_array($value, ['1', 'igen', 'yes', 'true', 'aktiv', 'aktív'], true) ? 1 : 0;
}

function warehouse_material_decimal(mixed $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace(' ', '', $value);
    $value = str_replace(',', '.', $value);
    if (!is_numeric($value)) {
        return null;
    }
    return number_format((float)$value, 3, '.', '');
}

function warehouse_material_price_decimal(mixed $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace(' ', '', $value);
    $value = str_replace(',', '.', $value);
    if (!is_numeric($value)) {
        throw new RuntimeException('Az egységár nem szám.');
    }
    $float = (float)$value;
    if ($float < 0) {
        throw new RuntimeException('Az egységár nem lehet negatív.');
    }
    return number_format($float, 2, '.', '');
}

function warehouse_material_currency_code(mixed $value): ?string {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return null;
    }
    $value = preg_replace('/\s+/', '', $value) ?? '';
    if ($value === '') {
        return null;
    }
    return substr($value, 0, 10);
}

function warehouse_format_money_amount(mixed $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    return number_format((float)$value, 2, ',', ' ');
}

/**
 * Anyagtörzs rekord létrehozása vagy módosítása.
 * A hívó oldal ugyanazzal a függvénnyel tud új anyagot létrehozni és meglévőt frissíteni.
 */
function warehouse_material_upsert(array $config, array $data, ?int $materialId = null): int {
    $pdo = warehouse_pdo($config);

    $sku = trim((string)($data['sku'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $unit = trim((string)($data['unit'] ?? ''));
    $categoryName = trim((string)($data['category_name'] ?? ''));
    $minimumStock = warehouse_material_decimal($data['minimum_stock'] ?? null);
    $note = trim((string)($data['note'] ?? ''));
    $isActive = (int)($data['is_active'] ?? 1) === 1 ? 1 : 0;
    $identifierFeatureReady = warehouse_material_identifier_feature_ready($config);
    $priceFeatureReady = warehouse_material_price_feature_ready($config);
    $isIdentified = $identifierFeatureReady && (int)($data['is_identified'] ?? 0) === 1 ? 1 : 0;
    $identifierLabel = trim((string)($data['identifier_label'] ?? ''));
    if ($isIdentified !== 1) {
        $identifierLabel = '';
    }

    $unitPrice = $priceFeatureReady ? warehouse_material_price_decimal($data['unit_price'] ?? null) : null;
    $currencyCode = $priceFeatureReady ? warehouse_material_currency_code($data['currency_code'] ?? null) : null;
    if ($unitPrice === null) {
        $currencyCode = null;
    } elseif ($currencyCode === null) {
        $currencyCode = 'HUF';
    }

    if ($sku === '' || $name === '') {
        throw new RuntimeException('A cikkszám és a megnevezés kötelező.');
    }

    $fields = [
        'sku' => $sku,
        'name' => $name,
        'unit' => ($unit === '' ? null : $unit),
        'category_name' => ($categoryName === '' ? null : $categoryName),
        'minimum_stock' => $minimumStock,
        'note' => ($note === '' ? null : $note),
        'is_active' => $isActive,
    ];

    if ($identifierFeatureReady) {
        $fields['is_identified'] = $isIdentified;
        $fields['identifier_label'] = ($identifierLabel === '' ? null : $identifierLabel);
    }

    if ($priceFeatureReady) {
        $fields['unit_price'] = $unitPrice;
        $fields['currency_code'] = $currencyCode;
    }

    if ($materialId !== null && $materialId > 0) {
        $fields['updated_by'] = current_auth_user_id();
        $setParts = [];
        $values = [];
        foreach ($fields as $column => $value) {
            $setParts[] = $column . ' = ?';
            $values[] = $value;
        }
        $values[] = $materialId;
        $sql = 'UPDATE material_items SET ' . implode(', ', $setParts) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($values);
        return $materialId;
    }

    $fields['created_by'] = current_auth_user_id();
    $fields['updated_by'] = current_auth_user_id();
    $columns = array_keys($fields);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO material_items (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    $pdo->prepare($sql)->execute(array_values($fields));
    return (int)$pdo->lastInsertId();
}

function warehouse_material_import_targets(): array {
    return [
        'sku' => ['label' => 'Cikkszám', 'required' => true],
        'name' => ['label' => 'Megnevezés', 'required' => true],
        'unit' => ['label' => 'Mértékegység', 'required' => false],
        'category_name' => ['label' => 'Kategória', 'required' => false],
        'minimum_stock' => ['label' => 'Minimum készlet', 'required' => false],
        'note' => ['label' => 'Megjegyzés', 'required' => false],
        'is_active' => ['label' => 'Aktív', 'required' => false],
        'is_identified' => ['label' => 'Egyedi azonosítós', 'required' => false],
        'identifier_label' => ['label' => 'Azonosító típusa', 'required' => false],
        'unit_price' => ['label' => 'Egységár', 'required' => false],
        'currency_code' => ['label' => 'Pénznem', 'required' => false],
    ];
}

function warehouse_material_import_pending_get(): ?array {
    $state = $_SESSION['_warehouse_material_import'] ?? null;
    return is_array($state) ? $state : null;
}

function warehouse_material_import_pending_clear(): void {
    $state = warehouse_material_import_pending_get();
    if (is_array($state)) {
        $path = (string)($state['temp_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
    unset($_SESSION['_warehouse_material_import']);
}

/**
 * Anyag archiválása vagy visszaállítása.
 * Archiváláskor a kapcsolódó azonosítók is archivált állapotba kerülnek.
 */
function warehouse_material_archive_toggle(array $config, int $materialId, bool $archive): array {
    $material = warehouse_material_find($config, $materialId);
    if (!$material) {
        throw new RuntimeException('A megadott anyag nem található.');
    }
    if (!warehouse_material_archive_feature_ready($config)) {
        throw new RuntimeException('Az archiválási bővítés adatbázis része még nincs telepítve.');
    }

    $pdo = warehouse_pdo($config);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE material_items SET is_archived = ?, updated_by = ? WHERE id = ?')->execute([
            $archive ? 1 : 0,
            current_auth_user_id(),
            $materialId,
        ]);

        if (warehouse_material_identifier_feature_ready($config)) {
            $pdo->prepare('UPDATE material_identifiers SET is_archived = ?, updated_by = ? WHERE material_id = ?')->execute([
                $archive ? 1 : 0,
                current_auth_user_id(),
                $materialId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return warehouse_material_find($config, $materialId) ?? $material;
}

function warehouse_material_import_prepare(string $tmpFile, string $originalName): array {
    $fh = fopen($tmpFile, 'rb');
    if (!$fh) {
        throw new RuntimeException('A CSV fájl nem olvasható.');
    }

    $firstLine = fgets($fh);
    if ($firstLine === false) {
        fclose($fh);
        throw new RuntimeException('A CSV fájl üres.');
    }

    $delimiter = warehouse_csv_delimiter($firstLine);
    rewind($fh);

    $headers = fgetcsv($fh, 0, $delimiter);
    if (!$headers || count($headers) < 2) {
        fclose($fh);
        throw new RuntimeException('A CSV fejléc nem értelmezhető.');
    }

    $sampleRows = [];
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        if ($row === [null] || count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        $sampleRows[] = array_map(static fn($v): string => trim((string)$v), $row);
        if (count($sampleRows) >= 5) {
            break;
        }
    }
    fclose($fh);

    $dir = warehouse_storage_path('tmp/material_import');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nem sikerült létrehozni az import átmeneti könyvtárát.');
    }

    warehouse_material_import_pending_clear();

    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: 'anyagtorzs.csv';
    $targetPath = $dir . '/material-import-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . $safeBase;
    if (!@copy($tmpFile, $targetPath)) {
        throw new RuntimeException('Nem sikerült eltárolni az importfájlt az összerendeléshez.');
    }

    $map = warehouse_material_header_map($headers);
    $state = [
        'original_name' => $originalName,
        'temp_path' => $targetPath,
        'delimiter' => $delimiter,
        'headers' => array_values(array_map(static fn($v): string => trim((string)$v), $headers)),
        'map' => $map,
        'sample_rows' => $sampleRows,
        'prepared_at' => date('c'),
    ];
    $_SESSION['_warehouse_material_import'] = $state;
    return $state;
}

function warehouse_material_import_mapping_from_request(array $src, array $headers): array {
    $targets = warehouse_material_import_targets();
    $raw = is_array($src['mapping'] ?? null) ? $src['mapping'] : [];
    $result = [];
    $maxIndex = max(0, count($headers) - 1);

    foreach ($targets as $key => $_meta) {
        $value = $raw[$key] ?? '';
        if ($value === '' || $value === null) {
            $result[$key] = null;
            continue;
        }
        if (!is_numeric((string)$value)) {
            throw new RuntimeException('Hibás mező-összerendelés érkezett.');
        }
        $index = (int)$value;
        if ($index < 0 || $index > $maxIndex) {
            throw new RuntimeException('Az egyik kiválasztott CSV oszlop nem létezik.');
        }
        $result[$key] = $index;
    }

    $used = [];
    foreach ($result as $target => $index) {
        if ($index === null) {
            continue;
        }
        if (isset($used[$index])) {
            $firstLabel = $targets[$used[$index]]['label'] ?? $used[$index];
            $secondLabel = $targets[$target]['label'] ?? $target;
            throw new RuntimeException('Ugyanaz a CSV oszlop nem rendelhető több mezőhöz: ' . $firstLabel . ' / ' . $secondLabel . '.');
        }
        $used[$index] = $target;
    }

    foreach ($targets as $key => $meta) {
        if (!empty($meta['required']) && !isset($result[$key])) {
            $result[$key] = null;
        }
        if (!empty($meta['required']) && $result[$key] === null) {
            throw new RuntimeException('A(z) ' . $meta['label'] . ' mező hozzárendelése kötelező.');
        }
    }

    return $result;
}

function warehouse_material_import_preview_rows(array $sampleRows, array $map): array {
    $targets = warehouse_material_import_targets();
    $preview = [];
    foreach ($sampleRows as $row) {
        $line = [];
        foreach ($targets as $key => $_meta) {
            $index = $map[$key] ?? null;
            $line[$key] = ($index !== null && array_key_exists($index, $row)) ? trim((string)$row[$index]) : '';
        }
        $preview[] = $line;
    }
    return $preview;
}


function warehouse_material_import_write_error_report(string $originalName, array $headers, array $errors): ?array {
    if ($errors === []) {
        return null;
    }

    $dir = warehouse_storage_path('tmp/material_import');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nem sikerült létrehozni a hibajelentés könyvtárát.');
    }

    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'anyagtorzs';
    $fileName = 'material-import-errors-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . $safeBase . '.csv';
    $fullPath = $dir . '/' . $fileName;

    $fh = fopen($fullPath, 'wb');
    if (!$fh) {
        throw new RuntimeException('Nem sikerült létrehozni a hibajelentés fájlt.');
    }

    try {
        fwrite($fh, "ï»¿");
        $headerRow = array_merge(['CSV sor', 'Hiba oka'], array_map(static fn($v): string => trim((string)$v), $headers));
        fputcsv($fh, $headerRow, ';');

        foreach ($errors as $item) {
            $row = is_array($item['row'] ?? null) ? $item['row'] : [];
            $values = [];
            foreach ($headers as $index => $_label) {
                $values[] = trim((string)($row[$index] ?? ''));
            }
            fputcsv($fh, array_merge([
                (string)($item['line_no'] ?? ''),
                (string)($item['message'] ?? ''),
            ], $values), ';');
        }
    } finally {
        fclose($fh);
    }

    return [
        'file_name' => $fileName,
        'full_path' => $fullPath,
        'relative_path' => 'tmp/material_import/' . $fileName,
    ];
}

function warehouse_material_import_execute(array $config, string $tmpFile, string $originalName, string $delimiter, array $map): array {
    $pdo = warehouse_pdo($config);
    $fh = fopen($tmpFile, 'rb');
    if (!$fh) {
        throw new RuntimeException('A CSV fájl nem olvasható.');
    }

    $headers = fgetcsv($fh, 0, $delimiter);
    if (!$headers || count($headers) < 2) {
        fclose($fh);
        throw new RuntimeException('A CSV fejléc nem értelmezhető.');
    }

    $headers = array_values(array_map(static fn($v): string => trim((string)$v), $headers));
    warehouse_material_import_mapping_from_request(['mapping' => $map], $headers);

    $batch = $pdo->prepare("INSERT INTO material_import_batches (file_name, imported_by, total_rows, inserted_rows, updated_rows, error_rows, notes) VALUES (?,?,?,?,?,?,?)");
    $batch->execute([$originalName, current_auth_user_id(), 0, 0, 0, 0, null]);
    $batchId = (int)$pdo->lastInsertId();

    $selectExisting = $pdo->prepare("SELECT id FROM material_items WHERE sku=? LIMIT 1");
    $inserted = 0;
    $updated = 0;
    $errors = 0;
    $total = 0;
    $lineNo = 1;
    $errorItems = [];

    try {
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            $lineNo++;
            if ($row === [null] || count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }
            $total++;

            $payload = [
                'sku' => ($map['sku'] !== null) ? ($row[$map['sku']] ?? '') : '',
                'name' => ($map['name'] !== null) ? ($row[$map['name']] ?? '') : '',
                'unit' => ($map['unit'] !== null) ? ($row[$map['unit']] ?? '') : '',
                'category_name' => ($map['category_name'] !== null) ? ($row[$map['category_name']] ?? '') : '',
                'minimum_stock' => ($map['minimum_stock'] !== null) ? ($row[$map['minimum_stock']] ?? '') : null,
                'note' => ($map['note'] !== null) ? ($row[$map['note']] ?? '') : '',
                'is_active' => ($map['is_active'] !== null) ? warehouse_material_bool($row[$map['is_active']] ?? '1') : 1,
                'is_identified' => ($map['is_identified'] !== null) ? warehouse_material_bool($row[$map['is_identified']] ?? '0') : 0,
                'identifier_label' => ($map['identifier_label'] !== null) ? ($row[$map['identifier_label']] ?? '') : '',
                'unit_price' => ($map['unit_price'] !== null) ? ($row[$map['unit_price']] ?? '') : null,
                'currency_code' => ($map['currency_code'] !== null) ? ($row[$map['currency_code']] ?? '') : '',
            ];

            try {
                $selectExisting->execute([trim((string)$payload['sku'])]);
                $existingId = (int)($selectExisting->fetchColumn() ?: 0);
                if ($existingId > 0) {
                    warehouse_material_upsert($config, $payload, $existingId);
                    $updated++;
                } else {
                    warehouse_material_upsert($config, $payload, null);
                    $inserted++;
                }
            } catch (Throwable $e) {
                $errors++;
                $message = $e->getMessage();
                $errorItems[] = [
                    'line_no' => $lineNo,
                    'message' => $message,
                    'row' => $row,
                ];
                $log = $pdo->prepare("INSERT INTO material_import_errors (batch_id, line_no, row_json, error_message) VALUES (?,?,?,?)");
                $log->execute([
                    $batchId,
                    $lineNo,
                    json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $message,
                ]);
            }
        }

        $errorReport = warehouse_material_import_write_error_report($originalName, $headers, $errorItems);
        $notes = null;
        if ($errorReport) {
            $notes = 'Hibalista: ' . ($errorReport['relative_path'] ?? '');
        }

        $pdo->prepare("UPDATE material_import_batches SET total_rows=?, inserted_rows=?, updated_rows=?, error_rows=?, notes=? WHERE id=?")
            ->execute([$total, $inserted, $updated, $errors, $notes, $batchId]);

        warehouse_audit($config, 'material.import_csv', 'material_import_batch', $batchId, [
            'file_name' => $originalName,
            'mapping' => $map,
            'total_rows' => $total,
            'inserted_rows' => $inserted,
            'updated_rows' => $updated,
            'error_rows' => $errors,
            'error_report' => $errorReport['relative_path'] ?? null,
        ]);

        return [
            'batch_id' => $batchId,
            'total_rows' => $total,
            'inserted_rows' => $inserted,
            'updated_rows' => $updated,
            'error_rows' => $errors,
            'error_report_file' => $errorReport['file_name'] ?? null,
            'error_report_path' => $errorReport['relative_path'] ?? null,
            'error_report_full_path' => $errorReport['full_path'] ?? null,
        ];
    } finally {
        fclose($fh);
    }
}

function warehouse_material_import_csv(array $config, string $tmpFile, string $originalName): array {
    $fh = fopen($tmpFile, 'rb');
    if (!$fh) {
        throw new RuntimeException('A CSV fájl nem olvasható.');
    }

    $firstLine = fgets($fh);
    if ($firstLine === false) {
        fclose($fh);
        throw new RuntimeException('A CSV fájl üres.');
    }
    $delimiter = warehouse_csv_delimiter($firstLine);
    rewind($fh);

    $headers = fgetcsv($fh, 0, $delimiter);
    fclose($fh);
    if (!$headers || count($headers) < 2) {
        throw new RuntimeException('A CSV fejléc nem értelmezhető.');
    }

    $map = warehouse_material_header_map($headers);
    warehouse_material_import_mapping_from_request(['mapping' => $map], $headers);
    return warehouse_material_import_execute($config, $tmpFile, $originalName, $delimiter, $map);
}

function warehouse_material_import_batches(array $config, int $limit = 10): array {
    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare("SELECT * FROM material_import_batches ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

// -----------------------------------------------------------------------------
// Audit napló szűrés és keresés
// -----------------------------------------------------------------------------
function warehouse_audit_filter_values(array $input): array {
    return [
        'q' => trim((string)($input['q'] ?? '')),
        'action_key' => trim((string)($input['action_key'] ?? '')),
        'entity_type' => trim((string)($input['entity_type'] ?? '')),
        'auth_user_id' => max(0, (int)($input['auth_user_id'] ?? 0)),
        'date_from' => trim((string)($input['date_from'] ?? '')),
        'date_to' => trim((string)($input['date_to'] ?? '')),
    ];
}

function warehouse_audit_filter_options(array $config): array {
    $pdo = warehouse_pdo($config);
    return [
        'actions' => $pdo->query("SELECT DISTINCT action_key FROM audit_log ORDER BY action_key")->fetchAll(PDO::FETCH_COLUMN),
        'entities' => $pdo->query("SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN),
        'users' => warehouse_resolved_auth_users($config),
    ];
}

function warehouse_auth_user_ids_by_search(array $config, string $q): array {
    $q = trim($q);
    if ($q === '') {
        return [];
    }

    $auth = auth_pdo($config);
    $like = '%' . $q . '%';
    $st = $auth->prepare("SELECT id FROM users WHERE full_name LIKE ? OR username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 100");
    $st->execute([$like, $like, $like]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function warehouse_audit_query_parts(array $config, array $filters): array {
    $filters = warehouse_audit_filter_values($filters);
    $where = [];
    $params = [];

    if ($filters['action_key'] !== '') {
        $where[] = 'action_key = ?';
        $params[] = $filters['action_key'];
    }
    if ($filters['entity_type'] !== '') {
        $where[] = 'entity_type = ?';
        $params[] = $filters['entity_type'];
    }
    if ($filters['auth_user_id'] > 0) {
        $where[] = 'auth_user_id = ?';
        $params[] = $filters['auth_user_id'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $filters['date_to'] . ' 00:00:00';
    }
    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $parts = [
            'action_key LIKE ?',
            'entity_type LIKE ?',
            'CAST(entity_id AS CHAR) LIKE ?',
            'details_json LIKE ?',
            'ip_address LIKE ?',
            'request_uri LIKE ?',
            'request_method LIKE ?',
        ];
        array_push($params, $like, $like, $like, $like, $like, $like, $like);

        $matchedUserIds = warehouse_auth_user_ids_by_search($config, $filters['q']);
        if ($matchedUserIds !== []) {
            $parts[] = 'auth_user_id IN (' . implode(',', array_fill(0, count($matchedUserIds), '?')) . ')';
            foreach ($matchedUserIds as $uid) {
                $params[] = $uid;
            }
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    $whereSql = $where !== [] ? (' WHERE ' . implode(' AND ', $where)) : '';
    return [$whereSql, $params, $filters];
}

function warehouse_audit_count(array $config, array $filters): int {
    $pdo = warehouse_pdo($config);
    [$whereSql, $params] = warehouse_audit_query_parts($config, $filters);

    $sql = 'SELECT COUNT(*) FROM audit_log' . $whereSql;
    $st = $pdo->prepare($sql);
    foreach ($params as $idx => $value) {
        $st->bindValue($idx + 1, $value);
    }
    $st->execute();
    return (int)$st->fetchColumn();
}

function warehouse_audit_search(array $config, array $filters, int $limit = 200, int $offset = 0): array {
    $pdo = warehouse_pdo($config);
    [$whereSql, $params] = warehouse_audit_query_parts($config, $filters);

    $sql = 'SELECT * FROM audit_log' . $whereSql . ' ORDER BY id DESC LIMIT ? OFFSET ?';

    $st = $pdo->prepare($sql);
    $idx = 1;
    foreach ($params as $value) {
        $st->bindValue($idx++, $value);
    }
    $st->bindValue($idx++, max(1, $limit), PDO::PARAM_INT);
    $st->bindValue($idx, max(0, $offset), PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    $userIds = [];
    foreach ($rows as $row) {
        $uid = (int)($row['auth_user_id'] ?? 0);
        if ($uid > 0) {
            $userIds[$uid] = $uid;
        }
    }
    $userMap = warehouse_resolved_auth_user_map($config, array_values($userIds));

    foreach ($rows as &$row) {
        $uid = (int)($row['auth_user_id'] ?? 0);
        $row['resolved_name'] = $userMap[$uid]['resolved_name'] ?? ($uid > 0 ? ('User #' . $uid) : 'Rendszer');
        $row['username'] = $userMap[$uid]['username'] ?? '';
        $row['email'] = $userMap[$uid]['email'] ?? '';
        $row['details'] = [];
        $json = (string)($row['details_json'] ?? '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $row['details'] = $decoded;
            }
        }
    }
    unset($row);

    return $rows;
}

function warehouse_detail_pairs_html(array $details): string {
    if ($details === []) {
        return '<span class="text-secondary">—</span>';
    }

    $items = [];
    foreach ($details as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        }
        $items[] = '<div><span class="text-secondary">' . h((string)$key) . ':</span> ' . h((string)$value) . '</div>';
    }
    return implode('', $items);
}

// -----------------------------------------------------------------------------
// Felhasználó-raktár jogosultságok és szerepkörök
// -----------------------------------------------------------------------------
function warehouse_role_rank(string $roleKey): int {
    return match ($roleKey) {
        'admin' => 30,
        'user' => 20,
        default => 10,
    };
}

function warehouse_user_warehouse_roles(array $config): array {
    if (warehouse_module_admin($config)) {
        $all = [];
        foreach (warehouse_all($config) as $row) {
            $all[(int)$row['id']] = 'admin';
        }
        return $all;
    }

    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare("SELECT warehouse_id, role_key FROM warehouse_user_access WHERE auth_user_id=?");
    $st->execute([current_auth_user_id()]);
    $roles = [];
    foreach ($st->fetchAll() as $row) {
        $wid = (int)$row['warehouse_id'];
        $role = (string)$row['role_key'];
        if (!isset($roles[$wid]) || warehouse_role_rank($role) > warehouse_role_rank((string)$roles[$wid])) {
            $roles[$wid] = $role;
        }
    }
    return $roles;
}

function warehouse_accessible_warehouses(array $config, bool $onlyActive = true): array {
    $rows = warehouse_all($config);
    if (warehouse_module_admin($config)) {
        if ($onlyActive) {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['is_active'] === 1));
        }
        return $rows;
    }

    $roles = warehouse_user_warehouse_roles($config);
    $out = [];
    foreach ($rows as $row) {
        $wid = (int)$row['id'];
        if (!isset($roles[$wid])) {
            continue;
        }
        if ($onlyActive && (int)$row['is_active'] !== 1) {
            continue;
        }
        $row['local_role_key'] = $roles[$wid];
        $out[] = $row;
    }
    return $out;
}

function warehouse_manageable_warehouses(array $config, bool $onlyActive = true): array {
    $rows = warehouse_accessible_warehouses($config, $onlyActive);
    if (warehouse_module_admin($config)) {
        return $rows;
    }
    return array_values(array_filter($rows, static function(array $row): bool {
        $role = (string)($row['local_role_key'] ?? 'viewer');
        return in_array($role, ['admin', 'user'], true);
    }));
}

function warehouse_user_can_view_warehouse(array $config, int $warehouseId): bool {
    if (warehouse_module_admin($config)) {
        return true;
    }
    $roles = warehouse_user_warehouse_roles($config);
    return isset($roles[$warehouseId]);
}

function warehouse_user_can_manage_warehouse(array $config, int $warehouseId): bool {
    if (warehouse_module_admin($config)) {
        return true;
    }
    $roles = warehouse_user_warehouse_roles($config);
    if (!isset($roles[$warehouseId])) {
        return false;
    }
    return in_array((string)$roles[$warehouseId], ['admin', 'user'], true);
}

function warehouse_user_local_warehouse_roles(array $config): array {
    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare("SELECT warehouse_id, role_key FROM warehouse_user_access WHERE auth_user_id=?");
    $st->execute([current_auth_user_id()]);
    $roles = [];
    foreach ($st->fetchAll() as $row) {
        $wid = (int)$row['warehouse_id'];
        $role = (string)$row['role_key'];
        if (!isset($roles[$wid]) || warehouse_role_rank($role) > warehouse_role_rank((string)$roles[$wid])) {
            $roles[$wid] = $role;
        }
    }
    return $roles;
}

function warehouse_user_can_manage_warehouse_local(array $config, int $warehouseId): bool {
    $roles = warehouse_user_local_warehouse_roles($config);
    if (!isset($roles[$warehouseId])) {
        return false;
    }
    return in_array((string)$roles[$warehouseId], ['admin', 'user'], true);
}

// -----------------------------------------------------------------------------
// Mennyiség, CSV és készlet-összesítő segédfüggvények
// -----------------------------------------------------------------------------
function warehouse_material_select_options(array $config, bool $activeOnly = true, bool $includeArchived = false): array {
    return warehouse_materials_all($config, $activeOnly, $includeArchived);
}

function warehouse_decimal_input(mixed $value, bool $allowZero = false): string {
    $value = trim((string)$value);
    if ($value === '') {
        throw new RuntimeException('A mennyiség megadása kötelező.');
    }
    $value = str_replace(' ', '', $value);
    $value = str_replace(',', '.', $value);
    if (!is_numeric($value)) {
        throw new RuntimeException('A mennyiség nem szám.');
    }
    $float = (float)$value;
    if ($allowZero ? ($float < 0) : ($float <= 0)) {
        throw new RuntimeException($allowZero ? 'A mennyiség nem lehet negatív.' : 'A mennyiségnek 0-nál nagyobbnak kell lennie.');
    }
    return number_format($float, 3, '.', '');
}

function warehouse_decimal_string(mixed $value): string {
    return number_format((float)$value, 3, '.', '');
}


function warehouse_format_quantity(mixed $value): string {
    if ($value === null || $value === '') {
        return '';
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }

    $normalized = str_replace(' ', '', $raw);
    $normalized = str_replace(',', '.', $normalized);
    if (!is_numeric($normalized)) {
        return $raw;
    }

    $number = round((float)$normalized, 3);
    if (abs($number - round($number)) < 0.0005) {
        return number_format((float)round($number), 0, ',', ' ');
    }

    $formatted = number_format($number, 3, ',', ' ');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return $formatted === '-0' ? '0' : $formatted;
}

function warehouse_quantity_display(mixed $value): string {
    return warehouse_format_quantity($value);
}

function warehouse_csv_download(string $filename, array $headerRow, array $rows): never {
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'export.csv';
    if (!str_ends_with(strtolower($safeName), '.csv')) {
        $safeName .= '.csv';
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('A CSV kimenet nem nyitható meg.');
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(static fn($v) => (string)$v, $headerRow), ';');
    foreach ($rows as $row) {
        $cells = [];
        foreach ((array)$row as $cell) {
            if ($cell === null) {
                $cells[] = '';
            } elseif (is_bool($cell)) {
                $cells[] = $cell ? '1' : '0';
            } else {
                $cells[] = (string)$cell;
            }
        }
        fputcsv($out, $cells, ';');
    }
    fclose($out);
    exit;
}

function warehouse_transfer_available_stock_map(array $config, array $warehouseIds = []): array {
    $warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds), static fn(int $id): bool => $id > 0)));
    if ($warehouseIds === []) {
        return [];
    }

    warehouse_stock_sync_table_from_movements($config);

    $pdo = warehouse_pdo($config);
    $sql = 'SELECT warehouse_id, material_id, quantity FROM warehouse_stock WHERE warehouse_id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($warehouseIds);

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $wid = (int)($row['warehouse_id'] ?? 0);
        $mid = (int)($row['material_id'] ?? 0);
        if ($wid <= 0 || $mid <= 0) {
            continue;
        }
        $qty = (float)($row['quantity'] ?? 0);
        $map[$wid][$mid] = [
            'raw' => warehouse_decimal_string($qty),
            'display' => warehouse_quantity_display($qty),
        ];
    }

    return $map;
}

function warehouse_stock_union_keys_sql(): string {
    return 'SELECT warehouse_id, material_id FROM warehouse_stock UNION SELECT warehouse_id, material_id FROM stock_movements';
}

function warehouse_stock_totals_sql(): string {
    return 'SELECT warehouse_id, material_id, SUM(quantity_change) AS effective_quantity, MAX(created_at) AS last_movement_at FROM stock_movements GROUP BY warehouse_id, material_id';
}


function warehouse_stock_summary_source_sql(): string {
    return "
        SELECT agg.warehouse_id,
               agg.material_id,
               CAST(SUM(agg.quantity_delta) AS DECIMAL(14,3)) AS quantity,
               MAX(agg.row_updated_at) AS updated_at
        FROM (
            SELECT sm.warehouse_id,
                   sm.material_id,
                   sm.quantity_change AS quantity_delta,
                   sm.created_at AS row_updated_at
            FROM stock_movements sm

            UNION ALL

            SELECT ws.warehouse_id,
                   ws.material_id,
                   ws.quantity AS quantity_delta,
                   ws.updated_at AS row_updated_at
            FROM warehouse_stock ws
            WHERE NOT EXISTS (
                SELECT 1
                FROM stock_movements smx
                WHERE smx.warehouse_id = ws.warehouse_id
                  AND smx.material_id = ws.material_id
            )
        ) agg
        GROUP BY agg.warehouse_id, agg.material_id
    ";
}







/**
 * Készletösszesítő lekérdezés raktáranként és anyagonként.
 * A raktárkészlet képernyő és a CSV export ugyanebből az eredményből dolgozik.
 */
function warehouse_stock_summary(array $config, array $filters = []): array {
    $pdo = warehouse_pdo($config);
    $filters = warehouse_stock_filter_values($filters);
    $identifierFeatureReady = warehouse_material_identifier_feature_ready($config);
    $archiveReady = warehouse_material_archive_feature_ready($config);
    $includeZero = (int)($filters['include_zero'] ?? 0) === 1;

    $identifiedSelect = $identifierFeatureReady
        ? "COALESCE(m.is_identified, 0) AS is_identified, m.identifier_label,"
        : "0 AS is_identified, NULL AS identifier_label,";
    $archivedSelect = $archiveReady
        ? "COALESCE(m.is_archived, 0) AS material_is_archived,"
        : "0 AS material_is_archived,";

    $params = [];
    $warehouseJoinKey = $includeZero ? 'w.id' : 'ws.warehouse_id';

    $sql = $includeZero
        ? "
        SELECT
            w.id AS warehouse_id,
            m.id AS material_id,
            COALESCE(ws.quantity, 0) AS quantity,
            ws.updated_at,
            w.name AS warehouse_name,
            w.code AS warehouse_code,
            w.is_active AS warehouse_is_active,
            m.sku,
            m.name AS material_name,
            m.unit,
            m.category_name,
            m.minimum_stock,
            m.is_active AS material_is_active,
            {$archivedSelect}
            {$identifiedSelect}
            m.note AS material_note
        FROM warehouses w
        CROSS JOIN material_items m
        LEFT JOIN (" . warehouse_stock_summary_source_sql() . ") ws
               ON ws.warehouse_id = w.id
              AND ws.material_id = m.id
        WHERE 1=1
    "
        : "
        SELECT
            ws.warehouse_id,
            ws.material_id,
            ws.quantity,
            ws.updated_at,
            w.name AS warehouse_name,
            w.code AS warehouse_code,
            w.is_active AS warehouse_is_active,
            m.sku,
            m.name AS material_name,
            m.unit,
            m.category_name,
            m.minimum_stock,
            m.is_active AS material_is_active,
            {$archivedSelect}
            {$identifiedSelect}
            m.note AS material_note
        FROM warehouse_stock ws
        INNER JOIN warehouses w ON w.id = ws.warehouse_id
        INNER JOIN material_items m ON m.id = ws.material_id
        WHERE ABS(ws.quantity) > 0.0005
    ";

    if (!warehouse_module_admin($config)) {
        $accessible = warehouse_accessible_warehouses($config, false);
        if ($accessible === []) {
            return [];
        }

        $allowedIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['id'],
            $accessible
        )));

        if ($allowedIds === []) {
            return [];
        }

        $sql .= " AND {$warehouseJoinKey} IN (" . implode(',', array_map('intval', $allowedIds)) . ")";
    }

    if ($archiveReady && (int)$filters['include_archived'] !== 1) {
        $sql .= " AND COALESCE(m.is_archived, 0) = 0";
    }

    if ((int)$filters['warehouse_id'] > 0) {
        $sql .= " AND {$warehouseJoinKey} = :warehouse_id";
        $params[':warehouse_id'] = (int)$filters['warehouse_id'];
    }

    if (trim((string)($filters['category_name'] ?? '')) !== '') {
        $sql .= " AND COALESCE(m.category_name, '') = :category_name";
        $params[':category_name'] = trim((string)$filters['category_name']);
    }

    if ($filters['q'] !== '') {
        $sql .= " AND (
            m.sku LIKE :q
            OR m.name LIKE :q
            OR COALESCE(m.category_name, '') LIKE :q
            OR w.name LIKE :q
            OR w.code LIKE :q
            OR COALESCE(m.identifier_label, '') LIKE :q";

        if ($identifierFeatureReady) {
            $sql .= "
            OR EXISTS (
                SELECT 1
                FROM material_identifiers mi
                WHERE mi.warehouse_id = {$warehouseJoinKey}
                  AND mi.material_id = m.id
                  AND mi.status = 'in_stock'";
            if ($archiveReady && (int)$filters['include_archived'] !== 1) {
                $sql .= "
                  AND COALESCE(mi.is_archived, 0) = 0";
            }
            $sql .= "
                  AND (
                      mi.identifier_value LIKE :q
                      OR COALESCE(mi.secondary_identifier_value, '') LIKE :q
                  )
            )";
        }

        $sql .= "
        )";
        $params[':q'] = '%' . $filters['q'] . '%';
    }

    if ((int)$filters['low_only'] === 1) {
        $sql .= " AND m.minimum_stock IS NOT NULL AND COALESCE(ws.quantity, 0) <= m.minimum_stock";
    }

    $sql .= " ORDER BY w.name, m.name, m.sku";

    $st = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        if ($key === ':warehouse_id') {
            $st->bindValue($key, (int)$value, PDO::PARAM_INT);
        } else {
            $st->bindValue($key, $value, PDO::PARAM_STR);
        }
    }

    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['quantity'] = warehouse_decimal_string($row['quantity']);
    }
    unset($row);

    return $rows;
}

function warehouse_stock_identifier_map(array $config, array $stockRows, bool $includeArchived = false): array {
    if (!warehouse_material_identifier_feature_ready($config) || $stockRows === []) {
        return [];
    }

    $pairs = [];
    foreach ($stockRows as $row) {
        if ((int)($row['is_identified'] ?? 0) !== 1) {
            continue;
        }
        $warehouseId = (int)($row['warehouse_id'] ?? 0);
        $materialId = (int)($row['material_id'] ?? 0);
        if ($warehouseId <= 0 || $materialId <= 0) {
            continue;
        }
        $pairs[$warehouseId . ':' . $materialId] = [$warehouseId, $materialId];
    }

    if ($pairs === []) {
        return [];
    }

    $pdo = warehouse_pdo($config);
    $whereParts = [];
    $params = [];
    foreach ($pairs as [$warehouseId, $materialId]) {
        $whereParts[] = '(warehouse_id = ? AND material_id = ?)';
        $params[] = $warehouseId;
        $params[] = $materialId;
    }

    $sql = 'SELECT id, warehouse_id, material_id, identifier_value, secondary_identifier_value, note, status, created_at, updated_at '
        . 'FROM material_identifiers '
        . 'WHERE status = ? ';
    if (warehouse_material_archive_feature_ready($config) && !$includeArchived) {
        $sql .= 'AND COALESCE(is_archived, 0) = 0 ';
    }
    $sql .= 'AND (' . implode(' OR ', $whereParts) . ') '
        . 'ORDER BY identifier_value ASC, secondary_identifier_value ASC, id ASC';

    array_unshift($params, 'in_stock');

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int)$row['warehouse_id'] . ':' . (int)$row['material_id'];
        $map[$key][] = $row;
    }

    return $map;
}

function warehouse_stock_sync_table_from_movements(array $config): void {
    $pdo = warehouse_pdo($config);
    $sql = "
        INSERT INTO warehouse_stock (warehouse_id, material_id, quantity, created_by, updated_by, created_at, updated_at)
        SELECT sm.warehouse_id,
               sm.material_id,
               CAST(SUM(sm.quantity_change) AS DECIMAL(14,3)) AS quantity,
               MAX(sm.performed_by) AS created_by,
               MAX(sm.performed_by) AS updated_by,
               MIN(sm.created_at) AS created_at,
               MAX(sm.created_at) AS updated_at
        FROM stock_movements sm
        GROUP BY sm.warehouse_id, sm.material_id
        ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            updated_by = VALUES(updated_by),
            updated_at = VALUES(updated_at)
    ";
    $pdo->exec($sql);
}

function warehouse_stock_sync_locked(PDO $pdo, array $config, int $warehouseId, int $materialId): array {
    $lock = $pdo->prepare('SELECT * FROM warehouse_stock WHERE warehouse_id=? AND material_id=? FOR UPDATE');
    $lock->execute([$warehouseId, $materialId]);
    $stockRow = $lock->fetch();

    $sumSt = $pdo->prepare('SELECT COUNT(*) AS movement_count, COALESCE(SUM(quantity_change), 0) AS effective_quantity FROM stock_movements WHERE warehouse_id=? AND material_id=?');
    $sumSt->execute([$warehouseId, $materialId]);
    $sumRow = $sumSt->fetch();
    $movementCount = (int)($sumRow['movement_count'] ?? 0);
    $effective = $movementCount > 0 ? (float)($sumRow['effective_quantity'] ?? 0) : (float)($stockRow['quantity'] ?? 0);
    $effectiveS = warehouse_decimal_string($effective);

    if ($stockRow) {
        $rowId = (int)$stockRow['id'];
        if ($movementCount > 0 && abs((float)$stockRow['quantity'] - $effective) > 0.0005) {
            $pdo->prepare('UPDATE warehouse_stock SET quantity=?, updated_by=? WHERE id=?')
                ->execute([$effectiveS, current_auth_user_id(), $rowId]);
        }
        return [
            'id' => $rowId,
            'quantity' => $effectiveS,
            'exists' => true,
        ];
    }

    if (abs($effective) > 0.0005) {
        $pdo->prepare('INSERT INTO warehouse_stock (warehouse_id, material_id, quantity, created_by, updated_by) VALUES (?,?,?,?,?)')
            ->execute([$warehouseId, $materialId, $effectiveS, current_auth_user_id(), current_auth_user_id()]);
        return [
            'id' => (int)$pdo->lastInsertId(),
            'quantity' => $effectiveS,
            'exists' => true,
        ];
    }

    return [
        'id' => 0,
        'quantity' => '0.000',
        'exists' => false,
    ];
}

function warehouse_stock_current_quantity(array $config, int $warehouseId, int $materialId): string {
    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare('SELECT COUNT(*) AS movement_count, COALESCE(SUM(quantity_change), 0) AS effective_quantity FROM stock_movements WHERE warehouse_id=? AND material_id=?');
    $st->execute([$warehouseId, $materialId]);
    $row = $st->fetch();
    $movementCount = (int)($row['movement_count'] ?? 0);
    if ($movementCount > 0) {
        return warehouse_decimal_string((float)($row['effective_quantity'] ?? 0));
    }

    $stockSt = $pdo->prepare('SELECT quantity FROM warehouse_stock WHERE warehouse_id=? AND material_id=? LIMIT 1');
    $stockSt->execute([$warehouseId, $materialId]);
    $stockRow = $stockSt->fetch();
    return warehouse_decimal_string((float)($stockRow['quantity'] ?? 0));
}


function warehouse_stock_debug_snapshot(array $config, array $filters = []): array {
    $pdo = warehouse_pdo($config);
    $filters = warehouse_stock_filter_values($filters);

    $snapshot = [
        'current_auth_user_id' => current_auth_user_id(),
        'warehouse_module_admin' => warehouse_module_admin($config),
        'session_keys' => array_keys($_SESSION ?? []),
        'accessible_warehouses' => [],
        'manageable_warehouses' => [],
        'warehouse_stock_rows' => 0,
        'stock_movement_groups' => 0,
        'stock_summary_preview' => [],
        'stock_summary_count' => 0,
        'stock_summary_rows' => [],
        'filters' => $filters,
    ];

    $accessible = warehouse_accessible_warehouses($config, false);
    $manageable = warehouse_manageable_warehouses($config, false);
    $snapshot['accessible_warehouses'] = array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'code' => (string)$row['code'],
        'local_role_key' => (string)($row['local_role_key'] ?? ''),
    ], $accessible);
    $snapshot['manageable_warehouses'] = array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'code' => (string)$row['code'],
        'local_role_key' => (string)($row['local_role_key'] ?? ''),
    ], $manageable);

    $snapshot['warehouse_stock_rows'] = (int)$pdo->query('SELECT COUNT(*) FROM warehouse_stock')->fetchColumn();
    $snapshot['stock_movement_groups'] = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT warehouse_id, material_id FROM stock_movements GROUP BY warehouse_id, material_id) x')->fetchColumn();

    $preview = $pdo->query(
        'SELECT ws.warehouse_id, ws.material_id, ws.quantity, w.name AS warehouse_name, m.sku, m.name AS material_name
'
        . 'FROM warehouse_stock ws
'
        . 'INNER JOIN warehouses w ON w.id = ws.warehouse_id
'
        . 'INNER JOIN material_items m ON m.id = ws.material_id
'
        . 'ORDER BY ws.warehouse_id, ws.material_id
'
        . 'LIMIT 20'
    )->fetchAll();
    $snapshot['stock_summary_preview'] = $preview;

    $rows = warehouse_stock_summary($config, $filters);
    $snapshot['stock_summary_count'] = count($rows);
    $snapshot['stock_summary_rows'] = array_slice($rows, 0, 20);

    return $snapshot;
}


// -----------------------------------------------------------------------------
// Egyedi azonosítós anyagok és azonosító-kezelés
// -----------------------------------------------------------------------------
function warehouse_identified_materials_all(array $config, bool $activeOnly = true, bool $includeArchived = false): array {
    if (!warehouse_material_identifier_feature_ready($config)) {
        return [];
    }

    $pdo = warehouse_pdo($config);
    $sql = 'SELECT * FROM material_items WHERE is_identified = 1';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    if (warehouse_material_archive_feature_ready($config) && !$includeArchived) {
        $sql .= ' AND COALESCE(is_archived, 0) = 0';
    }
    $sql .= ' ORDER BY name, sku';
    return $pdo->query($sql)->fetchAll();
}

function warehouse_material_identifier_normalize(string $value): string {
    $value = trim((string)preg_replace('/\s+/u', ' ', trim($value)));
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function warehouse_material_identifier_value_label(array $material): string {
    $label = trim((string)($material['identifier_label'] ?? ''));
    return $label !== '' ? $label : 'Azonosító';
}

function warehouse_material_identifier_secondary_label(array $material): string {
    $base = warehouse_material_identifier_value_label($material);
    return 'Belső ' . $base;
}

function warehouse_material_identifier_display_value_from_values(?string $primaryValue, ?string $secondaryValue = null): string {
    $primaryValue = trim((string)$primaryValue);
    $secondaryValue = trim((string)$secondaryValue);
    if ($primaryValue !== '' && $secondaryValue !== '') {
        return $primaryValue . ' ↔ ' . $secondaryValue;
    }
    return $primaryValue !== '' ? $primaryValue : $secondaryValue;
}

function warehouse_material_identifier_display_value(array $row): string {
    return warehouse_material_identifier_display_value_from_values(
        (string)($row['identifier_value'] ?? $row['value'] ?? ''),
        (string)($row['secondary_identifier_value'] ?? $row['secondary_value'] ?? '')
    );
}

function warehouse_material_identifier_code_conflicts(array $config, array $normalizedValues, array $options = []): array {
    $normalizedValues = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $normalizedValues), static fn(string $value): bool => $value !== '')));
    if ($normalizedValues === []) {
        return [];
    }

    $pdo = warehouse_pdo($config);
    $excludeStagingIds = array_values(array_unique(array_filter(array_map('intval', (array)($options['exclude_staging_ids'] ?? [])), static fn(int $id): bool => $id > 0)));

    $placeholders = implode(',', array_fill(0, count($normalizedValues), '?'));
    $materialSql = '
        SELECT "final_primary" AS source_type, id AS source_id, identifier_value_norm AS normalized_value, identifier_value AS raw_value
        FROM material_identifiers
        WHERE identifier_value_norm IN (' . $placeholders . ')
        UNION ALL
        SELECT "final_secondary" AS source_type, id AS source_id, secondary_identifier_value_norm AS normalized_value, secondary_identifier_value AS raw_value
        FROM material_identifiers
        WHERE secondary_identifier_value_norm IS NOT NULL
          AND secondary_identifier_value_norm IN (' . $placeholders . ')
    ';
    $st = $pdo->prepare($materialSql);
    $st->execute(array_merge($normalizedValues, $normalizedValues));
    $conflicts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $norm = trim((string)($row['normalized_value'] ?? ''));
        if ($norm === '' || isset($conflicts[$norm])) {
            continue;
        }
        $conflicts[$norm] = [
            'source' => 'material_identifiers',
            'source_type' => (string)($row['source_type'] ?? ''),
            'source_id' => (int)($row['source_id'] ?? 0),
            'raw_value' => (string)($row['raw_value'] ?? ''),
        ];
    }

    if (warehouse_identifier_staging_feature_ready($config)) {
        $stagingSql = '
            SELECT "staging_primary" AS source_type, id AS source_id, identifier_value_norm AS normalized_value, identifier_value AS raw_value
            FROM material_identifier_staging
            WHERE identifier_value_norm IN (' . $placeholders . ')';
        $params = $normalizedValues;
        if ($excludeStagingIds !== []) {
            $stagingSql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($excludeStagingIds), '?')) . ')';
            $params = array_merge($params, $excludeStagingIds);
        }
        $stagingSql .= '
            UNION ALL
            SELECT "staging_secondary" AS source_type, id AS source_id, secondary_identifier_value_norm AS normalized_value, secondary_identifier_value AS raw_value
            FROM material_identifier_staging
            WHERE secondary_identifier_value_norm IS NOT NULL
              AND secondary_identifier_value_norm IN (' . $placeholders . ')';
        $params = array_merge($params, $normalizedValues);
        if ($excludeStagingIds !== []) {
            $stagingSql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($excludeStagingIds), '?')) . ')';
            $params = array_merge($params, $excludeStagingIds);
        }

        $st = $pdo->prepare($stagingSql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $norm = trim((string)($row['normalized_value'] ?? ''));
            if ($norm === '' || isset($conflicts[$norm])) {
                continue;
            }
            $conflicts[$norm] = [
                'source' => 'material_identifier_staging',
                'source_type' => (string)($row['source_type'] ?? ''),
                'source_id' => (int)($row['source_id'] ?? 0),
                'raw_value' => (string)($row['raw_value'] ?? ''),
            ];
        }
    }

    return $conflicts;
}



function warehouse_material_identifier_feature_ready(array $config): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = warehouse_pdo($config);

        $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'material_identifiers'")->fetchColumn();
        if (!$tableExists) {
            return $cache = false;
        }

        $hasIsIdentified = (bool)$pdo->query("SHOW COLUMNS FROM material_items LIKE 'is_identified'")->fetchColumn();
        $hasIdentifierLabel = (bool)$pdo->query("SHOW COLUMNS FROM material_items LIKE 'identifier_label'")->fetchColumn();

        return $cache = ($hasIsIdentified && $hasIdentifierLabel);
    } catch (Throwable $e) {
        return $cache = false;
    }
}
function warehouse_material_identifier_stock_quantity(array $config, int $warehouseId, int $materialId): string {
    warehouse_stock_sync_table_from_movements($config);
    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare('SELECT quantity FROM warehouse_stock WHERE warehouse_id = ? AND material_id = ? LIMIT 1');
    $st->execute([$warehouseId, $materialId]);
    $value = $st->fetchColumn();
    return warehouse_decimal_string($value !== false ? $value : '0');
}

function warehouse_material_identifier_in_stock_count(array $config, int $warehouseId, int $materialId): int {
    if (!warehouse_material_identifier_feature_ready($config)) {
        return 0;
    }
    $pdo = warehouse_pdo($config);
    $sql = "SELECT COUNT(*) FROM material_identifiers WHERE warehouse_id = ? AND material_id = ? AND status = 'in_stock'";
    if (warehouse_material_archive_feature_ready($config)) {
        $sql .= " AND COALESCE(is_archived, 0) = 0";
    }
    $st = $pdo->prepare($sql);
    $st->execute([$warehouseId, $materialId]);
    return (int)$st->fetchColumn();
}

function warehouse_material_identifier_available_slots(array $config, int $warehouseId, int $materialId): int {
    $stockQty = (float)warehouse_material_identifier_stock_quantity($config, $warehouseId, $materialId);
    $tracked = warehouse_material_identifier_in_stock_count($config, $warehouseId, $materialId);
    return max(0, (int)floor($stockQty + 0.0005) - $tracked);
}

function warehouse_material_identifier_overview(array $config, int $warehouseId, int $materialId): ?array {
    if ($warehouseId <= 0 || $materialId <= 0) {
        return null;
    }
    $material = warehouse_material_find($config, $materialId);
    $warehouse = warehouse_find($config, $warehouseId);
    if (!$material || !$warehouse) {
        return null;
    }
    return [
        'material' => $material,
        'warehouse' => $warehouse,
        'stock_quantity' => warehouse_material_identifier_stock_quantity($config, $warehouseId, $materialId),
        'tracked_count' => warehouse_material_identifier_in_stock_count($config, $warehouseId, $materialId),
        'available_slots' => warehouse_material_identifier_available_slots($config, $warehouseId, $materialId),
    ];
}

function warehouse_material_identifiers_filters(array $input): array {
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = max(1, min(200, (int)($input['per_page'] ?? 50)));
    $status = trim((string)($input['status'] ?? 'in_stock'));
    if (!in_array($status, ['all', 'in_stock', 'issued', 'archived'], true)) {
        $status = 'in_stock';
    }
    $includeArchived = in_array((string)($input['include_archived'] ?? ''), ['1', 'on', 'true'], true) ? 1 : 0;

    $normalizeSelect = static function ($value): array {
        $raw = trim((string)$value);
        if ($raw === '' || $raw === '__none__') {
            return ['raw' => '__none__', 'id' => 0];
        }
        if ($raw === '__all__') {
            return ['raw' => '__all__', 'id' => 0];
        }
        $id = max(0, (int)$raw);
        return $id > 0
            ? ['raw' => (string)$id, 'id' => $id]
            : ['raw' => '__none__', 'id' => 0];
    };

    $material = $normalizeSelect($input['material_id'] ?? '__none__');
    $warehouse = $normalizeSelect($input['warehouse_id'] ?? '__none__');

    return [
        'material_id' => $material['id'],
        'warehouse_id' => $warehouse['id'],
        'material_filter' => $material['raw'],
        'warehouse_filter' => $warehouse['raw'],
        'q' => trim((string)($input['q'] ?? '')),
        'status' => $status,
        'include_archived' => $includeArchived,
        'page' => $page,
        'per_page' => $perPage,
    ];
}

function warehouse_material_identifiers_list(array $config, array $input = []): array {
    $filters = warehouse_material_identifiers_filters($input);
    if (!warehouse_material_identifier_feature_ready($config)) {
        return [
            'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $filters['per_page'], 'offset' => 0,
            'material_id' => $filters['material_id'], 'warehouse_id' => $filters['warehouse_id'],
            'material_filter' => $filters['material_filter'], 'warehouse_filter' => $filters['warehouse_filter'],
            'q' => $filters['q'], 'status' => $filters['status'], 'include_archived' => $filters['include_archived'],
        ];
    }

    $pdo = warehouse_pdo($config);
    $where = [];
    $params = [];

    if (!warehouse_module_admin($config)) {
        $allowed = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], warehouse_accessible_warehouses($config, false))));
        if ($allowed === []) {
            return [
                'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $filters['per_page'], 'offset' => 0,
                'material_id' => $filters['material_id'], 'warehouse_id' => $filters['warehouse_id'],
                'material_filter' => $filters['material_filter'], 'warehouse_filter' => $filters['warehouse_filter'],
                'q' => $filters['q'], 'status' => $filters['status'], 'include_archived' => $filters['include_archived'],
            ];
        }
        $where[] = 'mi.warehouse_id IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
        $params = array_merge($params, $allowed);
    }

    if ($filters['material_filter'] === '__none__' || $filters['warehouse_filter'] === '__none__') {
        return [
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'pages' => 1,
            'per_page' => $filters['per_page'],
            'offset' => 0,
            'material_id' => $filters['material_id'],
            'warehouse_id' => $filters['warehouse_id'],
            'material_filter' => $filters['material_filter'],
            'warehouse_filter' => $filters['warehouse_filter'],
            'q' => $filters['q'],
            'status' => $filters['status'],
            'include_archived' => $filters['include_archived'],
        ];
    }

    if ($filters['material_id'] > 0) {
        $where[] = 'mi.material_id = ?';
        $params[] = $filters['material_id'];
    }
    if ($filters['warehouse_id'] > 0) {
        $where[] = 'mi.warehouse_id = ?';
        $params[] = $filters['warehouse_id'];
    }
    if ($filters['status'] !== 'all') {
        $where[] = 'mi.status = ?';
        $params[] = $filters['status'];
    }
    if (warehouse_material_archive_feature_ready($config) && (int)$filters['include_archived'] !== 1) {
        $where[] = 'COALESCE(mi.is_archived, 0) = 0 AND COALESCE(m.is_archived, 0) = 0';
    }
    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $where[] = '(mi.identifier_value LIKE ? OR COALESCE(mi.secondary_identifier_value, "") LIKE ? OR COALESCE(mi.note, "") LIKE ? OR m.sku LIKE ? OR m.name LIKE ? OR w.name LIKE ? OR w.code LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $countSt = $pdo->prepare('SELECT COUNT(*) FROM material_identifiers mi INNER JOIN material_items m ON m.id = mi.material_id INNER JOIN warehouses w ON w.id = mi.warehouse_id' . $whereSql);
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();
    $pages = max(1, (int)ceil($total / max(1, $filters['per_page'])));
    $page = min($filters['page'], $pages);
    $offset = ($page - 1) * $filters['per_page'];

    $sql = 'SELECT mi.*, m.sku, m.name AS material_name, m.unit, m.identifier_label, COALESCE(m.is_archived, 0) AS material_is_archived, COALESCE(mi.is_archived, 0) AS identifier_is_archived, w.name AS warehouse_name, w.code AS warehouse_code
'
        . 'FROM material_identifiers mi
'
        . 'INNER JOIN material_items m ON m.id = mi.material_id
'
        . 'INNER JOIN warehouses w ON w.id = mi.warehouse_id'
        . $whereSql
        . ' ORDER BY mi.created_at DESC, mi.id DESC LIMIT ? OFFSET ?';
    $st = $pdo->prepare($sql);
    $i = 1;
    foreach ($params as $param) {
        $st->bindValue($i++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->bindValue($i++, $filters['per_page'], PDO::PARAM_INT);
    $st->bindValue($i, $offset, PDO::PARAM_INT);
    $st->execute();

    return [
        'rows' => $st->fetchAll(),
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $filters['per_page'],
        'offset' => $offset,
        'material_id' => $filters['material_id'],
        'warehouse_id' => $filters['warehouse_id'],
        'material_filter' => $filters['material_filter'],
        'warehouse_filter' => $filters['warehouse_filter'],
        'q' => $filters['q'],
        'status' => $filters['status'],
        'include_archived' => $filters['include_archived'],
    ];
}

/**
 * Egy új azonosító vagy kódpár végleges rögzítése.
 * A függvény duplikációt, készlethelyet és anyag/raktár összerendelést is ellenőriz.
 */
function warehouse_material_identifier_create(array $config, array $data): int {
    if (!warehouse_material_identifier_feature_ready($config)) {
        throw new RuntimeException('Az egyedi azonosítós bővítés adatbázis része még nincs telepítve.');
    }

    $warehouseId = max(0, (int)($data['warehouse_id'] ?? 0));
    $materialId = max(0, (int)($data['material_id'] ?? 0));
    $identifierValue = trim((string)($data['identifier_value'] ?? ''));
    $secondaryIdentifierValue = trim((string)($data['secondary_identifier_value'] ?? ''));
    $note = trim((string)($data['note'] ?? ''));

    if ($warehouseId <= 0 || $materialId <= 0 || $identifierValue === '') {
        throw new RuntimeException('A raktár, anyag és azonosító megadása kötelező.');
    }
    if (!warehouse_user_can_manage_warehouse($config, $warehouseId)) {
        throw new RuntimeException('Ehhez a raktárhoz nincs kezelési jogosultságod.');
    }

    $material = warehouse_material_find($config, $materialId);
    if (!$material) {
        throw new RuntimeException('A megadott anyag nem található.');
    }
    if (warehouse_material_archive_feature_ready($config) && (int)($material['is_archived'] ?? 0) === 1) {
        throw new RuntimeException('Archivált anyaghoz nem rögzíthető új azonosító.');
    }
    if ((int)($material['is_identified'] ?? 0) !== 1) {
        throw new RuntimeException('Ehhez az anyaghoz nincs bekapcsolva az egyedi azonosítós kezelés.');
    }

    $available = warehouse_material_identifier_available_slots($config, $warehouseId, $materialId);
    if ($available <= 0) {
        throw new RuntimeException('Ebben a raktárban ehhez az anyaghoz már nincs szabad azonosítóhely. Előbb növeld a készletet, vagy ellenőrizd a meglévő azonosítókat.');
    }

    $normalized = warehouse_material_identifier_normalize($identifierValue);
    if ($normalized === '') {
        throw new RuntimeException('Az azonosító nem lehet üres.');
    }

    $secondaryNormalized = '';
    if ($secondaryIdentifierValue !== '') {
        $secondaryNormalized = warehouse_material_identifier_normalize($secondaryIdentifierValue);
        if ($secondaryNormalized === '') {
            throw new RuntimeException('A második kód nem lehet üres.');
        }
        if ($secondaryNormalized === $normalized) {
            throw new RuntimeException('A két kód nem lehet azonos.');
        }
    }

    $conflicts = warehouse_material_identifier_code_conflicts($config, array_filter([$normalized, $secondaryNormalized], static fn(string $value): bool => $value !== ''), [
        'exclude_staging_ids' => (array)($data['exclude_staging_ids'] ?? []),
    ]);
    if (isset($conflicts[$normalized])) {
        $conflictValue = (string)($conflicts[$normalized]['raw_value'] ?? $identifierValue);
        throw new RuntimeException('Ez a kód már szerepel az adatbázisban: ' . $conflictValue . '.');
    }
    if ($secondaryNormalized !== '' && isset($conflicts[$secondaryNormalized])) {
        $conflictValue = (string)($conflicts[$secondaryNormalized]['raw_value'] ?? $secondaryIdentifierValue);
        throw new RuntimeException('A második kód már szerepel az adatbázisban: ' . $conflictValue . '.');
    }

    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare('INSERT INTO material_identifiers (material_id, warehouse_id, identifier_value, identifier_value_norm, secondary_identifier_value, secondary_identifier_value_norm, status, note, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        $materialId,
        $warehouseId,
        $identifierValue,
        $normalized,
        $secondaryIdentifierValue !== '' ? $secondaryIdentifierValue : null,
        $secondaryNormalized !== '' ? $secondaryNormalized : null,
        'in_stock',
        ($note === '' ? null : $note),
        current_auth_user_id(),
        current_auth_user_id(),
    ]);
    $id = (int)$pdo->lastInsertId();
    warehouse_audit($config, 'material_identifier.create', 'material_identifier', $id, [
        'material_id' => $materialId,
        'warehouse_id' => $warehouseId,
        'identifier_value' => $identifierValue,
        'secondary_identifier_value' => $secondaryIdentifierValue !== '' ? $secondaryIdentifierValue : null,
    ]);
    return $id;
}

function warehouse_material_identifier_delete(array $config, int $identifierId): void {
    if (!warehouse_material_identifier_feature_ready($config)) {
        throw new RuntimeException('Az egyedi azonosítós bővítés adatbázis része még nincs telepítve.');
    }
    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare('SELECT mi.*, m.sku, m.name AS material_name, w.name AS warehouse_name FROM material_identifiers mi INNER JOIN material_items m ON m.id = mi.material_id INNER JOIN warehouses w ON w.id = mi.warehouse_id WHERE mi.id = ? LIMIT 1');
    $st->execute([$identifierId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Az egyedi azonosító nem található.');
    }
    if (!warehouse_user_can_manage_warehouse($config, (int)$row['warehouse_id'])) {
        throw new RuntimeException('Ehhez a raktárhoz nincs kezelési jogosultságod.');
    }
    $pdo->prepare('DELETE FROM material_identifiers WHERE id = ?')->execute([$identifierId]);
    warehouse_audit($config, 'material_identifier.delete', 'material_identifier', $identifierId, [
        'material_id' => (int)$row['material_id'],
        'warehouse_id' => (int)$row['warehouse_id'],
        'identifier_value' => (string)$row['identifier_value'],
        'secondary_identifier_value' => (string)($row['secondary_identifier_value'] ?? ''),
    ]);
}


/**
 * Tömeges azonosító-feldolgozás.
 * A nyers szkenneres szöveget sorokra / párokra bontja, ellenőrzi, majd rögzíti.
 */
function warehouse_material_identifiers_bulk_add(array $config, int $materialId, int $warehouseId, string $rawInput, ?string $note = null, string $scanMode = 'single'): array {
    if (!warehouse_material_identifier_feature_ready($config)) {
        throw new RuntimeException('Az egyedi azonosítós bővítés adatbázis része még nincs telepítve.');
    }
    if (!warehouse_user_can_manage_warehouse($config, $warehouseId)) {
        throw new RuntimeException('Ehhez a raktárhoz nincs kezelési jogosultságod.');
    }

    $material = warehouse_material_find($config, $materialId);
    if (!$material || (int)($material['is_identified'] ?? 0) !== 1) {
        throw new RuntimeException('A kiválasztott anyag nem támogatja az egyedi azonosítókat.');
    }

    $commonNote = trim((string)($note ?? ''));
    $scanMode = strtolower(trim($scanMode));
    $scanMode = $scanMode === 'pair' ? 'pair' : 'single';
    $candidates = warehouse_identifier_staging_parse_candidates($rawInput, $scanMode);

    $total = 0;
    $duplicateInInput = 0;
    $errors = [];
    $seen = [];
    $validCandidates = [];

    foreach ($candidates as $candidate) {
        $lineNo = (int)($candidate['line'] ?? 0);
        $value = trim((string)($candidate['value'] ?? ''));
        $normalized = trim((string)($candidate['normalized'] ?? ''));
        $secondaryValue = trim((string)($candidate['secondary_value'] ?? ''));
        $secondaryNormalized = trim((string)($candidate['secondary_normalized'] ?? ''));
        $effectiveMode = (string)($candidate['scan_mode'] ?? $scanMode);

        if ($value === '') {
            continue;
        }
        $total++;

        if ($normalized === '') {
            if (count($errors) < 50) {
                $errors[] = 'Sor ' . $lineNo . ': az első kód üres vagy érvénytelen.';
            }
            continue;
        }

        if ($effectiveMode === 'pair_incomplete') {
            if (count($errors) < 50) {
                $errors[] = 'Sor ' . $lineNo . ': páros módban a második kód hiányzik.';
            }
            continue;
        }

        if ($scanMode === 'pair') {
            if ($secondaryValue === '' || $secondaryNormalized === '') {
                if (count($errors) < 50) {
                    $errors[] = 'Sor ' . $lineNo . ': páros módban két kód szükséges.';
                }
                continue;
            }
            if ($normalized === $secondaryNormalized) {
                if (count($errors) < 50) {
                    $errors[] = 'Sor ' . $lineNo . ': a két kód nem lehet azonos (' . $value . ').';
                }
                continue;
            }
        }

        foreach (array_filter([$normalized, $secondaryNormalized], static fn(string $norm): bool => $norm !== '') as $norm) {
            if (isset($seen[$norm])) {
                $duplicateInInput++;
                if (count($errors) < 50) {
                    $errors[] = 'Sor ' . $lineNo . ': a most beolvasott listában már szerepel ez a kód (' . ($norm === $normalized ? $value : $secondaryValue) . ').';
                }
                continue 2;
            }
        }

        $seen[$normalized] = $lineNo;
        if ($secondaryNormalized !== '') {
            $seen[$secondaryNormalized] = $lineNo;
        }

        $validCandidates[] = [
            'line' => $lineNo,
            'value' => $value,
            'secondary_value' => $secondaryValue,
        ];
    }

    if ($total <= 0) {
        throw new RuntimeException($scanMode === 'pair'
            ? 'Nem érkezett rögzíthető kódpár. Páros módban két egymáshoz tartozó kód szükséges.'
            : 'Nem érkezett rögzíthető azonosító. Egy sorba egy azonosító kerüljön.');
    }

    $inserted = 0;
    $errorRows = $duplicateInInput;
    foreach ($validCandidates as $candidate) {
        try {
            warehouse_material_identifier_create($config, [
                'material_id' => $materialId,
                'warehouse_id' => $warehouseId,
                'identifier_value' => $candidate['value'],
                'secondary_identifier_value' => $candidate['secondary_value'],
                'note' => $commonNote,
            ]);
            $inserted++;
        } catch (Throwable $e) {
            $errorRows++;
            if (count($errors) < 50) {
                $errors[] = 'Sor ' . (int)$candidate['line'] . ': ' . $e->getMessage();
            }
        }
    }

    return [
        'total_rows' => $total,
        'inserted_rows' => $inserted,
        'error_rows' => $errorRows,
        'duplicate_input_rows' => $duplicateInInput,
        'already_exists_rows' => max(0, $errorRows - $duplicateInInput),
        'skipped_no_capacity_rows' => 0,
        'scan_mode' => $scanMode,
        'errors' => $errors,
    ];
}

function warehouse_material_identifiers_import_csv(array $config, int $materialId, int $warehouseId, string $tmpFile, string $originalName, string $scanMode = 'single'): array {
    if (!warehouse_material_identifier_feature_ready($config)) {
        throw new RuntimeException('Az egyedi azonosítós bővítés adatbázis része még nincs telepítve.');
    }
    if (!warehouse_user_can_manage_warehouse($config, $warehouseId)) {
        throw new RuntimeException('Ehhez a raktárhoz nincs kezelési jogosultságod.');
    }
    $material = warehouse_material_find($config, $materialId);
    if (!$material || (int)($material['is_identified'] ?? 0) !== 1) {
        throw new RuntimeException('A kiválasztott anyag nem támogatja az egyedi azonosítókat.');
    }

    $scanMode = strtolower(trim($scanMode));
    $scanMode = $scanMode === 'pair' ? 'pair' : 'single';

    $fh = fopen($tmpFile, 'rb');
    if (!$fh) {
        throw new RuntimeException('A CSV fájl nem olvasható.');
    }
    $firstLine = fgets($fh);
    if ($firstLine === false) {
        fclose($fh);
        throw new RuntimeException('A CSV fájl üres.');
    }
    $delimiter = warehouse_csv_delimiter($firstLine);
    rewind($fh);

    $inserted = 0;
    $errorRows = 0;
    $total = 0;
    $lineNo = 0;
    $errorMessages = [];
    $headerChecked = false;
    $seen = [];

    $singleHeaderFirst = ['azonosito', 'azonosító', 'identifier', 'serial', 'sorozatszam', 'sorozatszám', 'kod', 'kód', 'code'];
    $singleHeaderSecond = ['', 'megjegyzes', 'megjegyzes', 'megjegyzés', 'note', 'notes', 'remark', 'remarks'];
    $pairHeaderFirst = array_merge($singleHeaderFirst, ['kulso', 'külső', 'external', 'externalcode', 'external_code', 'code1', 'kod1', 'elso', 'első', 'first']);
    $pairHeaderSecond = ['belso', 'belső', 'internal', 'internalcode', 'internal_code', 'code2', 'kod2', 'masodik', 'második', 'second', 'azonosito2', 'identifier2'];

    try {
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            $lineNo++;
            if ($row === [null] || count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }

            if (!$headerChecked) {
                $headerChecked = true;
                $firstNorm = warehouse_normalize_header((string)($row[0] ?? ''));
                $secondNorm = warehouse_normalize_header((string)($row[1] ?? ''));
                if ($scanMode === 'pair') {
                    if (in_array($firstNorm, $pairHeaderFirst, true) && in_array($secondNorm, $pairHeaderSecond, true)) {
                        continue;
                    }
                } else {
                    if (in_array($firstNorm, $singleHeaderFirst, true) && in_array($secondNorm, $singleHeaderSecond, true)) {
                        continue;
                    }
                }
            }

            $firstValue = trim((string)($row[0] ?? ''));
            $secondColumn = trim((string)($row[1] ?? ''));
            $thirdColumn = trim((string)($row[2] ?? ''));

            $total++;

            $identifierValue = $firstValue;
            $secondaryValue = '';
            $note = '';

            if ($scanMode === 'pair') {
                $secondaryValue = $secondColumn;
                $note = $thirdColumn;
                if ($identifierValue === '' || $secondaryValue === '') {
                    $errorRows++;
                    if (count($errorMessages) < 10) {
                        $errorMessages[] = 'Sor ' . $lineNo . ': páros módban az első két oszlop kötelező (1. kód, 2. kód).';
                    }
                    continue;
                }
            } else {
                $note = $secondColumn;
                if ($identifierValue === '') {
                    $errorRows++;
                    if (count($errorMessages) < 10) {
                        $errorMessages[] = 'Sor ' . $lineNo . ': az azonosító nem lehet üres.';
                    }
                    continue;
                }
            }

            $normalized = warehouse_material_identifier_normalize($identifierValue);
            $secondaryNormalized = $secondaryValue !== '' ? warehouse_material_identifier_normalize($secondaryValue) : '';

            if ($normalized === '') {
                $errorRows++;
                if (count($errorMessages) < 10) {
                    $errorMessages[] = 'Sor ' . $lineNo . ': az első kód üres vagy érvénytelen.';
                }
                continue;
            }

            if ($scanMode === 'pair') {
                if ($secondaryNormalized === '') {
                    $errorRows++;
                    if (count($errorMessages) < 10) {
                        $errorMessages[] = 'Sor ' . $lineNo . ': a második kód üres vagy érvénytelen.';
                    }
                    continue;
                }
                if ($normalized === $secondaryNormalized) {
                    $errorRows++;
                    if (count($errorMessages) < 10) {
                        $errorMessages[] = 'Sor ' . $lineNo . ': a két kód nem lehet azonos (' . $identifierValue . ').';
                    }
                    continue;
                }
            }

            foreach (array_filter([$normalized, $secondaryNormalized], static fn(string $norm): bool => $norm !== '') as $norm) {
                if (isset($seen[$norm])) {
                    $errorRows++;
                    if (count($errorMessages) < 10) {
                        $badValue = $norm === $normalized ? $identifierValue : $secondaryValue;
                        $errorMessages[] = 'Sor ' . $lineNo . ': a CSV-ben már szerepel ez a kód (' . $badValue . ').';
                    }
                    continue 2;
                }
            }
            $seen[$normalized] = $lineNo;
            if ($secondaryNormalized !== '') {
                $seen[$secondaryNormalized] = $lineNo;
            }

            try {
                warehouse_material_identifier_create($config, [
                    'material_id' => $materialId,
                    'warehouse_id' => $warehouseId,
                    'identifier_value' => $identifierValue,
                    'secondary_identifier_value' => $secondaryValue,
                    'note' => $note,
                ]);
                $inserted++;
            } catch (Throwable $e) {
                $errorRows++;
                if (count($errorMessages) < 10) {
                    $errorMessages[] = 'Sor ' . $lineNo . ': ' . $e->getMessage();
                }
            }
        }
    } finally {
        fclose($fh);
    }

    warehouse_audit($config, 'material_identifier.import_csv', 'material', $materialId, [
        'warehouse_id' => $warehouseId,
        'file_name' => $originalName,
        'total_rows' => $total,
        'inserted_rows' => $inserted,
        'error_rows' => $errorRows,
        'scan_mode' => $scanMode,
    ]);

    return [
        'total_rows' => $total,
        'inserted_rows' => $inserted,
        'error_rows' => $errorRows,
        'errors' => $errorMessages,
        'scan_mode' => $scanMode,
    ];
}

// -----------------------------------------------------------------------------
// Készletmozgások és készletmódosítások
// -----------------------------------------------------------------------------
function warehouse_stock_filter_values(array $src): array {
    $warehouseId = 0;
    if (isset($src['warehouse_id']) && is_numeric($src['warehouse_id'])) {
        $warehouseId = (int)$src['warehouse_id'];
    }

    $q = '';
    if (isset($src['q'])) {
        $q = trim((string)$src['q']);
    }

    $categoryName = '';
    if (isset($src['category_name'])) {
        $categoryName = trim((string)$src['category_name']);
    }

    $lowOnly = 0;
    if (isset($src['low_only'])) {
        $value = (string)$src['low_only'];
        $lowOnly = in_array($value, ['1', 'on', 'true'], true) ? 1 : 0;
    }

    $includeArchived = 0;
    if (isset($src['include_archived'])) {
        $value = (string)$src['include_archived'];
        $includeArchived = in_array($value, ['1', 'on', 'true'], true) ? 1 : 0;
    }

    $includeZero = 0;
    if (isset($src['include_zero'])) {
        $value = (string)$src['include_zero'];
        $includeZero = in_array($value, ['1', 'on', 'true'], true) ? 1 : 0;
    }

    return [
        'warehouse_id' => $warehouseId,
        'q' => $q,
        'category_name' => $categoryName,
        'low_only' => $lowOnly,
        'include_archived' => $includeArchived,
        'include_zero' => $includeZero,
    ];
}


function warehouse_movement_type_label(string $type): string {
    return match ($type) {
        'receipt' => 'Bevételezés',
        'adjustment_set' => 'Korrekció: beállítás',
        'adjustment_add' => 'Korrekció: növelés',
        'adjustment_subtract' => 'Korrekció: csökkentés',
        'transfer_out' => 'Raktárközi átadás ki',
        'transfer_in' => 'Raktárközi átadás be',
        'external_transfer_out' => 'Külsős partner átadás ki',
        'external_transfer_in' => 'Külsős partner átadás be',
        'identifier_relocate_out' => 'Azonosító áthelyezés ki',
        'identifier_relocate_in' => 'Azonosító áthelyezés be',
        default => $type,
    };
}

/**
 * Készletmozgás rögzítése és az összesített készlet frissítése.
 * Minden kézi készletmódosítás ezen az egységes üzleti logikán keresztül megy át.
 */
function warehouse_stock_apply_movement(array $config, int $warehouseId, int $materialId, string $movementType, mixed $quantityInput, ?string $referenceNo = null, ?string $note = null): int {
    $pdo = warehouse_pdo($config);

    if (!warehouse_user_can_manage_warehouse($config, $warehouseId)) {
        throw new RuntimeException('Ehhez a raktárhoz nincs módosítási jogosultságod.');
    }

    $warehouse = warehouse_find($config, $warehouseId);
    if (!$warehouse) {
        throw new RuntimeException('A raktár nem található.');
    }
    $material = warehouse_material_find($config, $materialId);
    if (!$material) {
        throw new RuntimeException('Az anyag nem található.');
    }
    if ((int)$material['is_active'] !== 1) {
        throw new RuntimeException('Inaktív anyagra nem lehet készletmozgást rögzíteni.');
    }
    if (warehouse_material_archive_feature_ready($config) && (int)($material['is_archived'] ?? 0) === 1) {
        throw new RuntimeException('Archivált anyagra nem lehet készletmozgást rögzíteni.');
    }

    $quantity = warehouse_decimal_input($quantityInput, $movementType === 'adjustment_set');
    $referenceNo = trim((string)$referenceNo);
    $note = trim((string)$note);

    if (!in_array($movementType, ['receipt', 'adjustment_set', 'adjustment_add', 'adjustment_subtract'], true)) {
        throw new RuntimeException('Érvénytelen mozgástípus.');
    }

    $pdo->beginTransaction();
    try {
        $stockState = warehouse_stock_sync_locked($pdo, $config, $warehouseId, $materialId);
        $before = (float)$stockState['quantity'];
        $qty = (float)$quantity;
        $change = 0.0;
        $after = $before;

        if ($movementType === 'receipt' || $movementType === 'adjustment_add') {
            $change = $qty;
            $after = $before + $qty;
        } elseif ($movementType === 'adjustment_subtract') {
            $change = -1 * $qty;
            $after = $before - $qty;
            if ($after < -0.0005) {
                throw new RuntimeException('A csökkentés negatív készletet eredményezne, ez most tiltva van.');
            }
        } elseif ($movementType === 'adjustment_set') {
            $after = $qty;
            $change = $after - $before;
        }

        $beforeS = warehouse_decimal_string($before);
        $afterS = warehouse_decimal_string($after);
        $changeS = warehouse_decimal_string($change);

        if ((int)$stockState['id'] > 0) {
            $pdo->prepare('UPDATE warehouse_stock SET quantity=?, updated_by=? WHERE id=?')
                ->execute([$afterS, current_auth_user_id(), (int)$stockState['id']]);
        } else {
            $pdo->prepare('INSERT INTO warehouse_stock (warehouse_id, material_id, quantity, created_by, updated_by) VALUES (?,?,?,?,?)')
                ->execute([$warehouseId, $materialId, $afterS, current_auth_user_id(), current_auth_user_id()]);
        }

        $ins = $pdo->prepare('INSERT INTO stock_movements (warehouse_id, material_id, movement_type, quantity_change, quantity_before, quantity_after, reference_no, note, performed_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            $warehouseId,
            $materialId,
            $movementType,
            $changeS,
            $beforeS,
            $afterS,
            $referenceNo !== '' ? $referenceNo : null,
            $note !== '' ? $note : null,
            current_auth_user_id(),
        ]);
        $movementId = (int)$pdo->lastInsertId();

        $pdo->commit();

        warehouse_audit($config, 'stock.' . $movementType, 'stock_movement', $movementId, [
            'warehouse_id' => $warehouseId,
            'warehouse_name' => (string)$warehouse['name'],
            'material_id' => $materialId,
            'material_sku' => (string)($material['sku'] ?? ''),
            'material_name' => (string)($material['name'] ?? ''),
            'quantity_change' => $changeS,
            'quantity_before' => $beforeS,
            'quantity_after' => $afterS,
            'reference_no' => $referenceNo !== '' ? $referenceNo : null,
            'note' => $note !== '' ? $note : null,
        ]);

        return $movementId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function warehouse_stock_movement_filter_values(array $input): array {
    $sort = trim((string)($input['sort'] ?? 'date'));
    if (!in_array($sort, ['date', 'warehouse', 'material', 'movement_type'], true)) {
        $sort = 'date';
    }

    $dir = strtolower(trim((string)($input['dir'] ?? 'desc')));
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = 'desc';
    }

    return [
        'warehouse_id' => max(0, (int)($input['warehouse_id'] ?? 0)),
        'material_id' => max(0, (int)($input['material_id'] ?? 0)),
        'movement_type' => trim((string)($input['movement_type'] ?? '')),
        'date_from' => trim((string)($input['date_from'] ?? '')),
        'date_to' => trim((string)($input['date_to'] ?? '')),
        'q' => trim((string)($input['q'] ?? '')),
        'sort' => $sort,
        'dir' => $dir,
        'page' => max(1, (int)($input['page'] ?? 1)),
        'per_page' => max(1, min(10000, (int)($input['per_page'] ?? 50))),
    ];
}

function warehouse_stock_movement_types(): array {
    return [
        'receipt' => warehouse_movement_type_label('receipt'),
        'adjustment_set' => warehouse_movement_type_label('adjustment_set'),
        'adjustment_add' => warehouse_movement_type_label('adjustment_add'),
        'adjustment_subtract' => warehouse_movement_type_label('adjustment_subtract'),
        'transfer_out' => warehouse_movement_type_label('transfer_out'),
        'transfer_in' => warehouse_movement_type_label('transfer_in'),
        'external_transfer_out' => warehouse_movement_type_label('external_transfer_out'),
        'external_transfer_in' => warehouse_movement_type_label('external_transfer_in'),
        'identifier_relocate_out' => warehouse_movement_type_label('identifier_relocate_out'),
        'identifier_relocate_in' => warehouse_movement_type_label('identifier_relocate_in'),
    ];
}

function warehouse_stock_movements_search(array $config, array $filters = []): array {
    $pdo = warehouse_pdo($config);
    $filters = warehouse_stock_movement_filter_values($filters);

    $empty = [
        'rows' => [],
        'total' => 0,
        'page' => 1,
        'pages' => 1,
        'per_page' => 50,
        'sort' => $filters['sort'],
        'dir' => $filters['dir'],
        'offset' => 0,
        'filters' => $filters,
    ];

    $accessible = warehouse_accessible_warehouses($config, false);
    if ($accessible === []) {
        return $empty;
    }
    $allowedIds = array_map(static fn(array $row): int => (int)$row['id'], $accessible);

    $where = ['sm.warehouse_id IN (' . implode(',', array_fill(0, count($allowedIds), '?')) . ')'];
    $params = $allowedIds;

    if ($filters['warehouse_id'] > 0) {
        if (!in_array($filters['warehouse_id'], $allowedIds, true)) {
            return $empty;
        }
        $where[] = 'sm.warehouse_id = ?';
        $params[] = $filters['warehouse_id'];
    }
    if ($filters['material_id'] > 0) {
        $where[] = 'sm.material_id = ?';
        $params[] = $filters['material_id'];
    }
    if ($filters['movement_type'] !== '') {
        $where[] = 'sm.movement_type = ?';
        $params[] = $filters['movement_type'];
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'sm.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'sm.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $filters['date_to'] . ' 00:00:00';
    }
    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $where[] = "(w.name LIKE ? OR w.code LIKE ? OR m.sku LIKE ? OR m.name LIKE ? OR COALESCE(sm.reference_no, '') LIKE ? OR COALESCE(sm.note, '') LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $sortMap = [
        'date' => 'sm.created_at',
        'warehouse' => 'w.name',
        'material' => 'm.name',
        'movement_type' => 'sm.movement_type',
    ];
    $orderBy = $sortMap[$filters['sort']] ?? 'sm.created_at';
    $dir = $filters['dir'] === 'asc' ? 'ASC' : 'DESC';
    $whereSql = implode(' AND ', $where);

    $countSt = $pdo->prepare("
        SELECT COUNT(*)
        FROM stock_movements sm
        INNER JOIN warehouses w ON w.id = sm.warehouse_id
        INNER JOIN material_items m ON m.id = sm.material_id
        WHERE $whereSql
    ");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();
    $pages = max(1, (int)ceil($total / $filters['per_page']));
    $page = min($filters['page'], $pages);
    $offset = ($page - 1) * $filters['per_page'];

    $sql = "
        SELECT sm.*, w.name AS warehouse_name, w.code AS warehouse_code,
               m.sku, m.name AS material_name, m.unit
        FROM stock_movements sm
        INNER JOIN warehouses w ON w.id = sm.warehouse_id
        INNER JOIN material_items m ON m.id = sm.material_id
        WHERE $whereSql
        ORDER BY $orderBy $dir, sm.id " . ($dir === 'ASC' ? 'ASC' : 'DESC') . "
        LIMIT ? OFFSET ?
    ";

    $st = $pdo->prepare($sql);
    $index = 1;
    foreach ($params as $param) {
        $type = is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $st->bindValue($index++, $param, $type);
    }
    $st->bindValue($index++, (int)$filters['per_page'], PDO::PARAM_INT);
    $st->bindValue($index++, (int)$offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    $userIds = [];
    foreach ($rows as $row) {
        $uid = (int)($row['performed_by'] ?? 0);
        if ($uid > 0) {
            $userIds[$uid] = $uid;
        }
    }
    $userMap = warehouse_resolved_auth_user_map($config, array_values($userIds));

    foreach ($rows as &$row) {
        $uid = (int)($row['performed_by'] ?? 0);
        $row['performed_name'] = $userMap[$uid]['resolved_name'] ?? ($uid > 0 ? ('User #' . $uid) : 'Rendszer');
        $row['performed_username'] = $userMap[$uid]['username'] ?? '';
    }
    unset($row);

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $filters['per_page'],
        'sort' => $filters['sort'],
        'dir' => $filters['dir'],
        'offset' => $offset,
        'filters' => $filters,
    ];
}

// -----------------------------------------------------------------------------
// Átadások: belső, külsős, azonosítós és partneres műveletek
// -----------------------------------------------------------------------------
function warehouse_transfer_status_label(string $status): string {
    return match ($status) {
        'pending' => 'Függőben',
        'accepted' => 'Elfogadva',
        'rejected' => 'Elutasítva',
        'cancelled' => 'Törölve',
        default => $status,
    };
}

function warehouse_transfer_badge_class(string $status): string {
    return match ($status) {
        'pending' => 'bg-warning text-dark',
        'accepted' => 'bg-success',
        'rejected' => 'bg-danger',
        'cancelled' => 'bg-secondary',
        default => 'bg-light text-dark',
    };
}


function warehouse_transfer_type_normalize(?string $value): string {
    $value = strtolower(trim((string)$value));
    return $value === 'external' ? 'external' : 'internal';
}

function warehouse_transfer_type_label(?string $value): string {
    return warehouse_transfer_type_normalize($value) === 'external'
        ? 'Külsős partner átadás'
        : 'Raktárközi átadás';
}


function warehouse_type_normalize(?string $value): string {
    $value = strtolower(trim((string)$value));
    return $value === 'external_partner' ? 'external_partner' : 'internal';
}

function warehouse_type_label(?string $value): string {
    return warehouse_type_normalize($value) === 'external_partner'
        ? 'Külső partner raktár'
        : 'Belső raktár';
}

function warehouse_parent_options(array $config, ?int $selectedId = null): string {
    $rows = warehouse_all($config);
    $out = '<option value="">— nincs —</option>';
    foreach ($rows as $r) {
        if (warehouse_type_normalize((string)($r['warehouse_type'] ?? 'internal')) !== 'internal') {
            continue;
        }
        $label = ((int)($r['parent_id'] ?? 0) > 0 ? '↳ ' : '') . (string)$r['name'];
        $sel = ((int)$selectedId === (int)$r['id']) ? ' selected' : '';
        $out .= '<option value="' . (int)$r['id'] . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $out;
}

function warehouse_partners_all(array $config, bool $activeOnly = false): array {
    $pdo = warehouse_pdo($config);
    $sql = 'SELECT * FROM warehouse_partners';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY partner_name, receiver_name, id';
    return $pdo->query($sql)->fetchAll();
}

function warehouse_partner_find(array $config, int $partnerId): ?array {
    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare('SELECT * FROM warehouse_partners WHERE id = ? LIMIT 1');
    $st->execute([$partnerId]);
    $row = $st->fetch();
    return $row ?: null;
}

function warehouse_partner_options(array $config, ?int $selectedId = null, bool $activeOnly = true): string {
    $rows = warehouse_partners_all($config, $activeOnly);
    $out = '<option value="">— válassz partnert —</option>';
    foreach ($rows as $row) {
        $label = trim((string)$row['partner_name']);
        $receiver = trim((string)($row['receiver_name'] ?? ''));
        if ($receiver !== '') {
            $label .= ' · ' . $receiver;
        }
        $sel = ((int)$selectedId === (int)$row['id']) ? ' selected' : '';
        $out .= '<option value="' . (int)$row['id'] . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $out;
}

function warehouse_external_partner_warehouses(array $config, bool $onlyActive = true): array {
    $rows = warehouse_all($config);
    return array_values(array_filter($rows, static function (array $row) use ($onlyActive): bool {
        if ($onlyActive && (int)($row['is_active'] ?? 0) !== 1) {
            return false;
        }
        return warehouse_type_normalize((string)($row['warehouse_type'] ?? 'internal')) === 'external_partner';
    }));
}

function warehouse_internal_manageable_warehouses(array $config, bool $onlyActive = true): array {
    $rows = warehouse_manageable_warehouses($config, $onlyActive);
    return array_values(array_filter($rows, static function (array $row): bool {
        return warehouse_type_normalize((string)($row['warehouse_type'] ?? 'internal')) === 'internal';
    }));
}


function warehouse_transfer_filter_values(array $input): array {
    $status = trim((string)($input['status'] ?? ''));
    if (!in_array($status, ['', 'pending', 'accepted', 'rejected', 'cancelled'], true)) {
        $status = '';
    }

    $scope = trim((string)($input['scope'] ?? 'all'));
    if (!in_array($scope, ['all', 'incoming', 'outgoing'], true)) {
        $scope = 'all';
    }

    return [
        'status' => $status,
        'scope' => $scope,
        'warehouse_id' => max(0, (int)($input['warehouse_id'] ?? 0)),
        'category_name' => trim((string)($input['category_name'] ?? '')),
        'q' => trim((string)($input['q'] ?? '')),
    ];
}

function warehouse_all_active_options(array $config): array {
    $pdo = warehouse_pdo($config);
    return $pdo->query('SELECT * FROM warehouses WHERE is_active=1 ORDER BY name, code')->fetchAll();
}


function warehouse_transfer_reference(int $transferId): string {
    return 'TR-' . str_pad((string)$transferId, 6, '0', STR_PAD_LEFT);
}

function warehouse_transfer_items_table_exists(array $config): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = warehouse_pdo($config);
    $st = $pdo->query("SHOW TABLES LIKE 'stock_transfer_items'");
    $cache = (bool)$st->fetchColumn();
    return $cache;
}


function warehouse_transfer_signature_columns_exist(array $config): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = warehouse_pdo($config);
    $st = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'receiver_signature_data'");
    $hasData = (bool)$st->fetchColumn();
    $st = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'receiver_signature_signed_at'");
    $hasSignedAt = (bool)$st->fetchColumn();
    $cache = $hasData && $hasSignedAt;
    return $cache;
}



function warehouse_transfer_item_identifiers_table_exists(array $config): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = warehouse_pdo($config);
    $st = $pdo->query("SHOW TABLES LIKE 'stock_transfer_item_identifiers'");
    $cache = (bool)$st->fetchColumn();
    return $cache;
}

function warehouse_transfer_available_identifier_map(array $config, array $warehouseIds = []): array {
    $warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds), static fn(int $id): bool => $id > 0)));
    if ($warehouseIds === [] || !warehouse_material_identifier_feature_ready($config)) {
        return [];
    }

    $pdo = warehouse_pdo($config);
    $sql = '
        SELECT mi.id,
               mi.warehouse_id,
               mi.material_id,
               mi.identifier_value,
               mi.secondary_identifier_value
        FROM material_identifiers mi
        INNER JOIN material_items m ON m.id = mi.material_id
        WHERE mi.status = ?
          AND COALESCE(m.is_identified, 0) = 1
          AND mi.warehouse_id IN (' . implode(',', array_fill(0, count($warehouseIds), '?')) . ')
        ORDER BY mi.identifier_value ASC, mi.id ASC
    ';
    $params = array_merge(['in_stock'], $warehouseIds);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $warehouseId = (int)($row['warehouse_id'] ?? 0);
        $materialId = (int)($row['material_id'] ?? 0);
        if ($warehouseId < 1 || $materialId < 1) {
            continue;
        }
        $map[$warehouseId][$materialId][] = [
            'id' => (int)($row['id'] ?? 0),
            'value' => warehouse_material_identifier_display_value($row),
            'identifier_value' => warehouse_material_identifier_display_value($row),
            'secondary_identifier_value' => (string)($row['secondary_identifier_value'] ?? ''),
        ];
    }

    return $map;
}

function warehouse_transfer_selected_identifiers(array $config, int $sourceWarehouseId, array $material, array $item): array {
    if ((int)($material['is_identified'] ?? 0) !== 1) {
        return [];
    }
    if (!warehouse_material_identifier_feature_ready($config)) {
        throw new RuntimeException('Az egyedi azonosítós átadásokhoz előbb futtasd a material_identifiers frissítő SQL-t.');
    }
    if (!warehouse_transfer_item_identifiers_table_exists($config)) {
        throw new RuntimeException('Az azonosítós átadásokhoz előbb futtasd a database/warehousemgr_update_step13_transfer_item_identifiers.sql fájlt.');
    }

    $identifierIdsRaw = $item['identifier_ids'] ?? [];
    if (!is_array($identifierIdsRaw)) {
        $identifierIdsRaw = [$identifierIdsRaw];
    }
    $identifierIds = array_values(array_unique(array_filter(array_map('intval', $identifierIdsRaw), static fn(int $id): bool => $id > 0)));

    $materialName = (string)($material['name'] ?? 'ismeretlen anyag');
    $identifierLabel = warehouse_material_identifier_value_label($material);

    if ($identifierIds === []) {
        throw new RuntimeException('Az egyedi azonosítós anyagnál (' . $materialName . ') legalább egy ' . mb_strtolower($identifierLabel, 'UTF-8') . ' kiválasztása kötelező.');
    }

    $pdo = warehouse_pdo($config);
    $sql = '
        SELECT id, material_id, warehouse_id, identifier_value, secondary_identifier_value, status
        FROM material_identifiers
        WHERE material_id = ?
          AND warehouse_id = ?
          AND status = ?
          AND id IN (' . implode(',', array_fill(0, count($identifierIds), '?')) . ')
        ORDER BY identifier_value ASC, id ASC
    ';
    $params = array_merge([(int)($material['id'] ?? 0), $sourceWarehouseId, 'in_stock'], $identifierIds);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) !== count($identifierIds)) {
        throw new RuntimeException('A kiválasztott azonosítók között van olyan, amely már nem található a forrás raktárban vagy nem készleten van: ' . $materialName . '.');
    }

    return $rows;
}

function warehouse_transfer_item_identifiers_save(PDO $pdo, int $transferItemId, array $identifierRows): void {
    if ($transferItemId < 1 || $identifierRows === []) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO stock_transfer_item_identifiers (transfer_item_id, material_identifier_id, identifier_value) VALUES (?,?,?)');
    foreach ($identifierRows as $row) {
        $identifierId = (int)($row['id'] ?? 0);
        if ($identifierId < 1) {
            continue;
        }
        $insert->execute([
            $transferItemId,
            $identifierId,
            (string)($row['identifier_value'] ?? ''),
        ]);
    }
}

function warehouse_transfer_move_identifiers(PDO $pdo, array $item, int $sourceWarehouseId, int $targetWarehouseId): array {
    $materialId = (int)($item['material_id'] ?? 0);
    $isIdentified = (int)($item['is_identified'] ?? ($item['material']['is_identified'] ?? 0)) === 1;
    $identifierRows = is_array($item['identifiers'] ?? null) ? $item['identifiers'] : [];
    $identifierIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? $row['material_identifier_id'] ?? 0), $identifierRows), static fn(int $id): bool => $id > 0)));

    if (!$isIdentified) {
        return [];
    }
    if ($identifierIds === []) {
        throw new RuntimeException('Az egyedi azonosítós tételhez nincs kiválasztott azonosító.');
    }

    $sql = '
        SELECT id, material_id, warehouse_id, identifier_value, secondary_identifier_value, status
        FROM material_identifiers
        WHERE id IN (' . implode(',', array_fill(0, count($identifierIds), '?')) . ')
        FOR UPDATE
    ';
    $st = $pdo->prepare($sql);
    $st->execute($identifierIds);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) !== count($identifierIds)) {
        throw new RuntimeException('A kiválasztott azonosítók között van olyan, amely időközben már nem érhető el.');
    }

    foreach ($rows as $row) {
        if ((int)($row['material_id'] ?? 0) !== $materialId) {
            throw new RuntimeException('A kiválasztott azonosítók között érvénytelen elem szerepel.');
        }
        if ((int)($row['warehouse_id'] ?? 0) !== $sourceWarehouseId || (string)($row['status'] ?? '') !== 'in_stock') {
            throw new RuntimeException('A kiválasztott azonosítók között van olyan, amely már nincs a forrás raktár készletén.');
        }
    }

    $update = $pdo->prepare('UPDATE material_identifiers SET warehouse_id = ?, updated_by = ? WHERE id = ?');
    foreach ($rows as $row) {
        $update->execute([
            $targetWarehouseId,
            current_auth_user_id(),
            (int)$row['id'],
        ]);
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp(warehouse_material_identifier_display_value($a), warehouse_material_identifier_display_value($b));
    });

    return $rows;
}


function warehouse_material_identifier_lookup_for_transfer(array $config, int $sourceWarehouseId, int $materialId, string $identifierValue): array {
    if (!warehouse_material_identifier_feature_ready($config)) {
        throw new RuntimeException('Az egyedi azonosítók funkció még nincs előkészítve.');
    }
    if ($sourceWarehouseId < 1 || $materialId < 1) {
        throw new RuntimeException('A forrás raktár és az anyag megadása kötelező.');
    }

    $sourceWarehouse = warehouse_find($config, $sourceWarehouseId);
    if (!$sourceWarehouse || (int)($sourceWarehouse['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('A kiválasztott forrás raktár nem található vagy nem aktív.');
    }
    $sourceType = warehouse_type_normalize((string)($sourceWarehouse['warehouse_type'] ?? 'internal'));
    if ($sourceType !== 'external_partner' && !warehouse_user_can_manage_warehouse($config, $sourceWarehouseId)) {
        throw new RuntimeException('A kiválasztott forrás raktárhoz nincs módosítási jogosultságod.');
    }

    $material = warehouse_material_find($config, $materialId);
    if (!$material) {
        throw new RuntimeException('Az anyag nem található.');
    }
    if ((int)($material['is_identified'] ?? 0) !== 1) {
        throw new RuntimeException('Ehhez az anyaghoz nincs egyedi azonosító kezelés bekapcsolva.');
    }

    $identifierValue = trim($identifierValue);
    if ($identifierValue === '') {
        throw new RuntimeException('Az azonosító nem lehet üres.');
    }

    $normalized = warehouse_material_identifier_normalize($identifierValue);
    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare(
        'SELECT mi.id,
                mi.material_id,
                mi.warehouse_id,
                mi.identifier_value,
                mi.secondary_identifier_value,
                mi.status,
                w.name AS warehouse_name,
                w.code AS warehouse_code
         FROM material_identifiers mi
         INNER JOIN warehouses w ON w.id = mi.warehouse_id
         WHERE mi.material_id = ?
           AND (mi.identifier_value_norm = ? OR mi.secondary_identifier_value_norm = ?)
         LIMIT 1'
    );
    $st->execute([$materialId, $normalized, $normalized]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'status' => 'not_found',
            'identifier_value' => $identifierValue,
            'normalized_value' => $normalized,
            'material_id' => $materialId,
            'material_name' => (string)($material['name'] ?? ''),
            'identifier_label' => warehouse_material_identifier_value_label($material),
        ];
    }

    $warehouseId = (int)($row['warehouse_id'] ?? 0);
    $status = (string)($row['status'] ?? '');
    if ($warehouseId === $sourceWarehouseId && $status === 'in_stock') {
        return [
            'status' => 'in_source',
            'identifier' => [
                'id' => (int)($row['id'] ?? 0),
                'material_id' => (int)($row['material_id'] ?? 0),
                'warehouse_id' => $warehouseId,
                'warehouse_name' => (string)($row['warehouse_name'] ?? ''),
                'warehouse_code' => (string)($row['warehouse_code'] ?? ''),
                'identifier_value' => (string)($row['identifier_value'] ?? ''),
                'secondary_identifier_value' => (string)($row['secondary_identifier_value'] ?? ''),
                'display_value' => warehouse_material_identifier_display_value($row),
                'raw_status' => $status,
            ],
            'identifier_label' => warehouse_material_identifier_value_label($material),
        ];
    }

    if ($status === 'in_stock') {
        return [
            'status' => 'in_other_warehouse',
            'identifier' => [
                'id' => (int)($row['id'] ?? 0),
                'material_id' => (int)($row['material_id'] ?? 0),
                'warehouse_id' => $warehouseId,
                'warehouse_name' => (string)($row['warehouse_name'] ?? ''),
                'warehouse_code' => (string)($row['warehouse_code'] ?? ''),
                'identifier_value' => (string)($row['identifier_value'] ?? ''),
                'secondary_identifier_value' => (string)($row['secondary_identifier_value'] ?? ''),
                'display_value' => warehouse_material_identifier_display_value($row),
                'raw_status' => $status,
            ],
            'identifier_label' => warehouse_material_identifier_value_label($material),
        ];
    }

    return [
        'status' => 'not_available',
        'identifier' => [
            'id' => (int)($row['id'] ?? 0),
            'material_id' => (int)($row['material_id'] ?? 0),
            'warehouse_id' => $warehouseId,
            'warehouse_name' => (string)($row['warehouse_name'] ?? ''),
            'warehouse_code' => (string)($row['warehouse_code'] ?? ''),
            'identifier_value' => (string)($row['identifier_value'] ?? ''),
            'raw_status' => $status,
        ],
        'identifier_label' => warehouse_material_identifier_value_label($material),
    ];
}

function warehouse_transfer_relocate_identifier_to_source(array $config, int $identifierId, int $targetWarehouseId, int $materialId, ?string $note = null): array {
    if (!warehouse_material_identifier_feature_ready($config)) {
        throw new RuntimeException('Az egyedi azonosítók funkció még nincs előkészítve.');
    }
    if ($identifierId < 1 || $targetWarehouseId < 1 || $materialId < 1) {
        throw new RuntimeException('Hiányzó azonosító-átmozgatási adatok.');
    }

    $pdo = warehouse_pdo($config);
    $targetWarehouse = warehouse_find($config, $targetWarehouseId);
    if (!$targetWarehouse || (int)($targetWarehouse['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('A cél raktár nem található vagy nem aktív.');
    }
    $targetType = warehouse_type_normalize((string)($targetWarehouse['warehouse_type'] ?? 'internal'));
    if ($targetType !== 'external_partner' && !warehouse_user_can_manage_warehouse($config, $targetWarehouseId)) {
        throw new RuntimeException('A kiválasztott forrás raktárhoz nincs módosítási jogosultságod.');
    }

    $material = warehouse_material_find($config, $materialId);
    if (!$material) {
        throw new RuntimeException('Az anyag nem található.');
    }
    if ((int)($material['is_identified'] ?? 0) !== 1) {
        throw new RuntimeException('Csak egyedi azonosítós anyag mozgatható át így.');
    }

    $note = trim((string)$note);

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT mi.id,
                    mi.material_id,
                    mi.warehouse_id,
                    mi.identifier_value,
                    mi.status,
                    w.name AS warehouse_name,
                    w.code AS warehouse_code
             FROM material_identifiers mi
             INNER JOIN warehouses w ON w.id = mi.warehouse_id
             WHERE mi.id = ?
             FOR UPDATE'
        );
        $st->execute([$identifierId]);
        $identifier = $st->fetch(PDO::FETCH_ASSOC);
        if (!$identifier) {
            throw new RuntimeException('Az azonosító nem található.');
        }

        if ((int)($identifier['material_id'] ?? 0) !== $materialId) {
            throw new RuntimeException('Az azonosító nem ehhez az anyaghoz tartozik.');
        }

        $fromWarehouseId = (int)($identifier['warehouse_id'] ?? 0);
        if ($fromWarehouseId === $targetWarehouseId) {
            throw new RuntimeException('Az azonosító már ebben a raktárban van.');
        }
        if ((string)($identifier['status'] ?? '') !== 'in_stock') {
            throw new RuntimeException('Csak készleten lévő azonosító mozgatható át.');
        }

        $fromWarehouse = warehouse_find($config, $fromWarehouseId);
        if (!$fromWarehouse || (int)($fromWarehouse['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('A jelenlegi raktár nem található vagy nem aktív.');
        }

        $referenceNo = 'IDMOVE-' . date('YmdHis');
        $reasonParts = [
            'Vonalkódos átadás-előkészítés',
            (string)($material['name'] ?? ''),
            '[' . (string)($material['sku'] ?? '') . ']',
            warehouse_material_identifier_value_label($material) . ': ' . (string)($identifier['identifier_value'] ?? ''),
        ];
        if ($note !== '') {
            $reasonParts[] = $note;
        }
        $movementNote = implode(' | ', array_filter($reasonParts, static fn($value): bool => trim((string)$value) !== ''));

        $sourceState = warehouse_stock_sync_locked($pdo, $config, $fromWarehouseId, $materialId);
        $sourceBefore = (float)($sourceState['quantity'] ?? 0);
        if ($sourceBefore < 1 - 0.0005) {
            throw new RuntimeException('A jelenlegi raktár készlete nem elegendő az azonosító áthelyezéséhez.');
        }
        $sourceAfter = $sourceBefore - 1;
        $sourceBeforeS = warehouse_decimal_string($sourceBefore);
        $sourceAfterS = warehouse_decimal_string($sourceAfter);
        if ((int)($sourceState['id'] ?? 0) > 0) {
            $pdo->prepare('UPDATE warehouse_stock SET quantity = ?, updated_by = ? WHERE id = ?')
                ->execute([$sourceAfterS, current_auth_user_id(), (int)$sourceState['id']]);
        } else {
            throw new RuntimeException('A kiinduló készletsor nem található.');
        }

        $targetState = warehouse_stock_sync_locked($pdo, $config, $targetWarehouseId, $materialId);
        $targetBefore = (float)($targetState['quantity'] ?? 0);
        $targetAfter = $targetBefore + 1;
        $targetBeforeS = warehouse_decimal_string($targetBefore);
        $targetAfterS = warehouse_decimal_string($targetAfter);
        if ((int)($targetState['id'] ?? 0) > 0) {
            $pdo->prepare('UPDATE warehouse_stock SET quantity = ?, updated_by = ? WHERE id = ?')
                ->execute([$targetAfterS, current_auth_user_id(), (int)$targetState['id']]);
        } else {
            $pdo->prepare('INSERT INTO warehouse_stock (warehouse_id, material_id, quantity, created_by, updated_by) VALUES (?,?,?,?,?)')
                ->execute([$targetWarehouseId, $materialId, $targetAfterS, current_auth_user_id(), current_auth_user_id()]);
        }

        $pdo->prepare('UPDATE material_identifiers SET warehouse_id = ?, updated_by = ? WHERE id = ?')
            ->execute([$targetWarehouseId, current_auth_user_id(), $identifierId]);

        $insMovement = $pdo->prepare('INSERT INTO stock_movements (warehouse_id, material_id, movement_type, quantity_change, quantity_before, quantity_after, reference_no, note, performed_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $insMovement->execute([
            $fromWarehouseId,
            $materialId,
            'identifier_relocate_out',
            warehouse_decimal_string(-1),
            $sourceBeforeS,
            $sourceAfterS,
            $referenceNo,
            $movementNote . ' | ' . (string)($fromWarehouse['code'] ?? '') . ' → ' . (string)($targetWarehouse['code'] ?? ''),
            current_auth_user_id(),
        ]);
        $movementOutId = (int)$pdo->lastInsertId();

        $insMovement->execute([
            $targetWarehouseId,
            $materialId,
            'identifier_relocate_in',
            warehouse_decimal_string(1),
            $targetBeforeS,
            $targetAfterS,
            $referenceNo,
            $movementNote . ' | ' . (string)($fromWarehouse['code'] ?? '') . ' → ' . (string)($targetWarehouse['code'] ?? ''),
            current_auth_user_id(),
        ]);
        $movementInId = (int)$pdo->lastInsertId();

        $pdo->commit();

        warehouse_audit($config, 'material_identifier.relocate_for_transfer', 'material_identifier', $identifierId, [
            'reference_no' => $referenceNo,
            'material_id' => $materialId,
            'material_sku' => (string)($material['sku'] ?? ''),
            'material_name' => (string)($material['name'] ?? ''),
            'identifier_value' => (string)($identifier['identifier_value'] ?? ''),
            'display_value' => warehouse_material_identifier_display_value($identifier),
            'from_warehouse_id' => $fromWarehouseId,
            'from_warehouse_name' => (string)($fromWarehouse['name'] ?? ''),
            'to_warehouse_id' => $targetWarehouseId,
            'to_warehouse_name' => (string)($targetWarehouse['name'] ?? ''),
            'movement_out_id' => $movementOutId,
            'movement_in_id' => $movementInId,
            'note' => $note !== '' ? $note : null,
        ]);

        return [
            'identifier_id' => $identifierId,
            'identifier_value' => (string)($identifier['identifier_value'] ?? ''),
            'material_id' => $materialId,
            'material_sku' => (string)($material['sku'] ?? ''),
            'material_name' => (string)($material['name'] ?? ''),
            'from_warehouse_id' => $fromWarehouseId,
            'from_warehouse_name' => (string)($fromWarehouse['name'] ?? ''),
            'from_warehouse_code' => (string)($fromWarehouse['code'] ?? ''),
            'to_warehouse_id' => $targetWarehouseId,
            'to_warehouse_name' => (string)($targetWarehouse['name'] ?? ''),
            'to_warehouse_code' => (string)($targetWarehouse['code'] ?? ''),
            'reference_no' => $referenceNo,
            'movement_out_id' => $movementOutId,
            'movement_in_id' => $movementInId,
            'stock_map' => warehouse_transfer_available_stock_map($config, [$fromWarehouseId, $targetWarehouseId]),
            'from_identifiers' => warehouse_transfer_available_identifier_map($config, [$fromWarehouseId])[$fromWarehouseId][$materialId] ?? [],
            'to_identifiers' => warehouse_transfer_available_identifier_map($config, [$targetWarehouseId])[$targetWarehouseId][$materialId] ?? [],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function warehouse_signature_data_normalize(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    if (!preg_match('#^data:image/png;base64,#i', $raw)) {
        throw new RuntimeException('Az aláírás formátuma érvénytelen.');
    }
    $payload = substr($raw, strpos($raw, ',') + 1);
    if ($payload === false || $payload === '') {
        throw new RuntimeException('Az aláírás adata hiányzik.');
    }
    $decoded = base64_decode($payload, true);
    if ($decoded === false || strlen($decoded) < 100) {
        throw new RuntimeException('Az aláírás nem értelmezhető.');
    }
    return 'data:image/png;base64,' . base64_encode($decoded);
}

function warehouse_transfer_items_fetch_by_transfer_ids(array $config, array $transferIds): array {
    $transferIds = array_values(array_unique(array_filter(array_map('intval', $transferIds), static fn(int $id): bool => $id > 0)));
    if ($transferIds === []) {
        return [];
    }

    $pdo = warehouse_pdo($config);
    $map = [];

    if (warehouse_transfer_items_table_exists($config)) {
        $sql = '
            SELECT ti.id AS transfer_item_id,
                   ti.transfer_id,
                   ti.material_id,
                   ti.quantity,
                   m.sku,
                   m.name AS material_name,
                   m.unit,
                   m.category_name,
                   COALESCE(m.is_identified, 0) AS is_identified,
                   m.identifier_label
            FROM stock_transfer_items ti
            INNER JOIN material_items m ON m.id = ti.material_id
            WHERE ti.transfer_id IN (' . implode(',', array_fill(0, count($transferIds), '?')) . ')
            ORDER BY ti.id ASC
        ';
        $st = $pdo->prepare($sql);
        $st->execute($transferIds);

        $transferItemIds = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $transferId = (int)($row['transfer_id'] ?? 0);
            if ($transferId < 1) {
                continue;
            }
            $row['transfer_item_id'] = (int)($row['transfer_item_id'] ?? 0);
            $row['quantity'] = warehouse_decimal_string($row['quantity'] ?? 0);
            $row['identifiers'] = [];
            if ((int)$row['transfer_item_id'] > 0) {
                $transferItemIds[] = (int)$row['transfer_item_id'];
            }
            $map[$transferId][] = $row;
        }

        if ($transferItemIds !== [] && warehouse_transfer_item_identifiers_table_exists($config)) {
            $identifierSql = '
                SELECT sii.transfer_item_id,
                       sii.material_identifier_id,
                       CASE
                           WHEN mi.id IS NOT NULL THEN CONCAT_WS(" ↔ ", mi.identifier_value, mi.secondary_identifier_value)
                           ELSE sii.identifier_value
                       END AS identifier_value
                FROM stock_transfer_item_identifiers sii
                LEFT JOIN material_identifiers mi ON mi.id = sii.material_identifier_id
                WHERE sii.transfer_item_id IN (' . implode(',', array_fill(0, count($transferItemIds), '?')) . ')
                ORDER BY COALESCE(mi.identifier_value, sii.identifier_value) ASC, sii.id ASC
            ';
            $identifierSt = $pdo->prepare($identifierSql);
            $identifierSt->execute($transferItemIds);

            $identifierMap = [];
            foreach ($identifierSt->fetchAll(PDO::FETCH_ASSOC) as $identifierRow) {
                $transferItemId = (int)($identifierRow['transfer_item_id'] ?? 0);
                if ($transferItemId < 1) {
                    continue;
                }
                $identifierMap[$transferItemId][] = [
                    'id' => (int)($identifierRow['material_identifier_id'] ?? 0),
                    'material_identifier_id' => (int)($identifierRow['material_identifier_id'] ?? 0),
                    'identifier_value' => (string)($identifierRow['identifier_value'] ?? ''),
                ];
            }

            foreach ($map as &$items) {
                foreach ($items as &$item) {
                    $item['identifiers'] = $identifierMap[(int)($item['transfer_item_id'] ?? 0)] ?? [];
                }
                unset($item);
            }
            unset($items);
        }

        return $map;
    }

    $sql = '
        SELECT tr.id AS transfer_id,
               tr.material_id,
               tr.quantity,
               m.sku,
               m.name AS material_name,
               m.unit,
               m.category_name,
               COALESCE(m.is_identified, 0) AS is_identified,
               m.identifier_label
        FROM stock_transfers tr
        INNER JOIN material_items m ON m.id = tr.material_id
        WHERE tr.id IN (' . implode(',', array_fill(0, count($transferIds), '?')) . ')
        ORDER BY tr.id ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute($transferIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $transferId = (int)($row['transfer_id'] ?? 0);
        if ($transferId < 1) {
            continue;
        }
        $row['quantity'] = warehouse_decimal_string($row['quantity'] ?? 0);
        $row['identifiers'] = [];
        $map[$transferId][] = $row;
    }
    return $map;
}

function warehouse_transfer_items_for_transfer(array $config, int $transferId): array {
    $map = warehouse_transfer_items_fetch_by_transfer_ids($config, [$transferId]);
    return $map[$transferId] ?? [];
}

function warehouse_transfer_attach_items(array $config, array $rows): array {
    if ($rows === []) {
        return [];
    }

    $transferIds = array_values(array_unique(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows)));
    $itemsMap = warehouse_transfer_items_fetch_by_transfer_ids($config, $transferIds);

    foreach ($rows as &$row) {
        $transferId = (int)($row['id'] ?? 0);
        $items = $itemsMap[$transferId] ?? [];
        $row['items'] = $items;
        $row['item_count'] = count($items);
        if ($items !== []) {
            $first = $items[0];
            $row['sku'] = (string)($first['sku'] ?? '');
            $row['material_name'] = (string)($first['material_name'] ?? '');
            $row['unit'] = (string)($first['unit'] ?? '');
            $row['category_name'] = $first['category_name'] ?? null;
            $row['quantity'] = warehouse_decimal_string($first['quantity'] ?? 0);
        }
    }
    unset($row);

    return $rows;
}

function warehouse_transfer_normalize_items_input(array $config, int $sourceWarehouseId, array $itemsInput): array {
    $normalized = [];
    $seenMaterialIds = [];

    foreach ($itemsInput as $item) {
        if (!is_array($item)) {
            continue;
        }

        $materialId = (int)($item['material_id'] ?? 0);
        $quantityRaw = trim((string)($item['quantity'] ?? ''));

        if ($materialId < 1 && $quantityRaw === '') {
            continue;
        }
        if ($materialId < 1) {
            throw new RuntimeException('Minden felvett tételnél kötelező az anyag megadása.');
        }

        if (isset($seenMaterialIds[$materialId])) {
            throw new RuntimeException('Ugyanaz az anyag egy átadáson belül csak egyszer szerepelhet.');
        }

        $material = warehouse_material_find($config, $materialId);
        if (!$material) {
            throw new RuntimeException('Az egyik kiválasztott anyag nem található.');
        }
        if ((int)($material['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Inaktív anyag nem adható át.');
        }
        if (warehouse_material_archive_feature_ready($config) && (int)($material['is_archived'] ?? 0) === 1) {
            throw new RuntimeException('Archivált anyag nem adható át.');
        }

        $identifiers = [];
        if ((int)($material['is_identified'] ?? 0) === 1) {
            $identifiers = warehouse_transfer_selected_identifiers($config, $sourceWarehouseId, $material, $item);
            $quantity = warehouse_decimal_string((float)count($identifiers));

            if ($quantityRaw !== '') {
                $enteredQuantity = warehouse_decimal_input($quantityRaw, false);
                if (abs((float)$enteredQuantity - (float)count($identifiers)) > 0.0005) {
                    throw new RuntimeException('Az egyedi azonosítós anyagnál a mennyiségnek egyeznie kell a kiválasztott azonosítók darabszámával: ' . (string)$material['name'] . '.');
                }
            }
        } else {
            if ($quantityRaw === '') {
                throw new RuntimeException('Minden felvett tételnél kötelező a mennyiség megadása.');
            }
            $quantity = warehouse_decimal_input($quantityRaw, false);
        }

        $currentQty = (float)warehouse_stock_current_quantity($config, $sourceWarehouseId, $materialId);
        if ($currentQty < (float)$quantity - 0.0005) {
            throw new RuntimeException('A forrás raktárban nincs elegendő készlet ehhez: ' . (string)$material['name'] . ' [' . (string)$material['sku'] . '].');
        }

        $seenMaterialIds[$materialId] = true;
        $normalized[] = [
            'material_id' => $materialId,
            'quantity' => $quantity,
            'material' => $material,
            'identifiers' => $identifiers,
        ];
    }

    if ($normalized === []) {
        throw new RuntimeException('Legalább egy teljes tételt meg kell adni az átadáshoz.');
    }

    return $normalized;
}

function warehouse_transfer_find(array $config, int $transferId): ?array {
    $pdo = warehouse_pdo($config);
    $sql = "
        SELECT tr.*, 
               sw.name AS source_warehouse_name, sw.code AS source_warehouse_code,
               tw.name AS target_warehouse_name, tw.code AS target_warehouse_code
        FROM stock_transfers tr
        INNER JOIN warehouses sw ON sw.id = tr.source_warehouse_id
        INNER JOIN warehouses tw ON tw.id = tr.target_warehouse_id
        WHERE tr.id = ?
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$transferId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }

    $row = warehouse_transfer_attach_items($config, [$row])[0];

    $userIds = [];
    foreach (['requested_by', 'accepted_by', 'rejected_by', 'cancelled_by'] as $field) {
        $uid = (int)($row[$field] ?? 0);
        if ($uid > 0) {
            $userIds[] = $uid;
        }
    }
    $userMap = warehouse_resolved_auth_user_map($config, $userIds);
    foreach (['requested_by', 'accepted_by', 'rejected_by', 'cancelled_by'] as $field) {
        $uid = (int)($row[$field] ?? 0);
        $row[$field . '_name'] = $userMap[$uid]['resolved_name'] ?? ($uid > 0 ? ('User #' . $uid) : '');
    }
    return $row;
}

function warehouse_transfer_search_query_parts(array $config, array $filters = []): array {
    $filters = warehouse_transfer_filter_values($filters);

    $viewable = warehouse_accessible_warehouses($config, false);
    if (!warehouse_module_admin($config) && $viewable === []) {
        return ['allowed' => false, 'filters' => $filters, 'where' => [], 'params' => []];
    }

    $where = [];
    $params = [];

    if (!warehouse_module_admin($config)) {
        $allowedIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $viewable)));
        $where[] = '(tr.source_warehouse_id IN (' . implode(',', array_fill(0, count($allowedIds), '?')) . ') OR tr.target_warehouse_id IN (' . implode(',', array_fill(0, count($allowedIds), '?')) . '))';
        $params = array_merge($params, $allowedIds, $allowedIds);
    }

    if ($filters['status'] !== '') {
        $where[] = 'tr.status = ?';
        $params[] = $filters['status'];
    }
    if ($filters['scope'] === 'incoming') {
        $manageable = warehouse_manageable_warehouses($config, false);
        if (!warehouse_module_admin($config)) {
            $ids = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $manageable)));
            if ($ids === []) {
                return ['allowed' => false, 'filters' => $filters, 'where' => [], 'params' => []];
            }
            $where[] = 'tr.target_warehouse_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
    } elseif ($filters['scope'] === 'outgoing') {
        $manageable = warehouse_manageable_warehouses($config, false);
        if (!warehouse_module_admin($config)) {
            $ids = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $manageable)));
            if ($ids === []) {
                return ['allowed' => false, 'filters' => $filters, 'where' => [], 'params' => []];
            }
            $where[] = 'tr.source_warehouse_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
    }

    if ($filters['warehouse_id'] > 0) {
        if (!warehouse_module_admin($config) && !warehouse_user_can_view_warehouse($config, $filters['warehouse_id'])) {
            return ['allowed' => false, 'filters' => $filters, 'where' => [], 'params' => []];
        }
        $where[] = '(tr.source_warehouse_id = ? OR tr.target_warehouse_id = ?)';
        $params[] = $filters['warehouse_id'];
        $params[] = $filters['warehouse_id'];
    }

    if ($filters['category_name'] !== '') {
        if (warehouse_transfer_items_table_exists($config)) {
            $where[] = 'EXISTS (SELECT 1 FROM stock_transfer_items ti INNER JOIN material_items mi ON mi.id = ti.material_id WHERE ti.transfer_id = tr.id AND COALESCE(mi.category_name, "") = ?)';
            $params[] = $filters['category_name'];
        } else {
            $where[] = 'EXISTS (SELECT 1 FROM material_items mi WHERE mi.id = tr.material_id AND COALESCE(mi.category_name, "") = ?)';
            $params[] = $filters['category_name'];
        }
    }

    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        if (warehouse_transfer_items_table_exists($config)) {
            $where[] = '(sw.name LIKE ? OR sw.code LIKE ? OR tw.name LIKE ? OR tw.code LIKE ? OR COALESCE(tr.reference_no, "") LIKE ? OR COALESCE(tr.note, "") LIKE ? OR COALESCE(tr.decision_note, "") LIKE ? OR COALESCE(tr.partner_name, "") LIKE ? OR COALESCE(tr.receiver_name, "") LIKE ? OR COALESCE(tr.receiver_phone, "") LIKE ? OR COALESCE(tr.receiver_email, "") LIKE ? OR COALESCE(tr.project_no, "") LIKE ? OR EXISTS (SELECT 1 FROM stock_transfer_items ti INNER JOIN material_items mi ON mi.id = ti.material_id WHERE ti.transfer_id = tr.id AND (mi.sku LIKE ? OR mi.name LIKE ?)))';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        } else {
            $where[] = '(sw.name LIKE ? OR sw.code LIKE ? OR tw.name LIKE ? OR tw.code LIKE ? OR COALESCE(tr.reference_no, "") LIKE ? OR COALESCE(tr.note, "") LIKE ? OR COALESCE(tr.decision_note, "") LIKE ? OR COALESCE(tr.partner_name, "") LIKE ? OR COALESCE(tr.receiver_name, "") LIKE ? OR COALESCE(tr.receiver_phone, "") LIKE ? OR COALESCE(tr.receiver_email, "") LIKE ? OR COALESCE(tr.project_no, "") LIKE ? OR EXISTS (SELECT 1 FROM material_items mi WHERE mi.id = tr.material_id AND (mi.sku LIKE ? OR mi.name LIKE ?)))';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
    }

    return ['allowed' => true, 'filters' => $filters, 'where' => $where, 'params' => $params];
}

function warehouse_transfer_count(array $config, array $filters = []): int {
    $pdo = warehouse_pdo($config);
    $query = warehouse_transfer_search_query_parts($config, $filters);
    if (!($query['allowed'] ?? false)) {
        return 0;
    }

    $sql = 'SELECT COUNT(*) FROM stock_transfers tr INNER JOIN warehouses sw ON sw.id = tr.source_warehouse_id INNER JOIN warehouses tw ON tw.id = tr.target_warehouse_id';
    if (($query['where'] ?? []) !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $query['where']);
    }

    $st = $pdo->prepare($sql);
    foreach (($query['params'] ?? []) as $idx => $value) {
        $st->bindValue($idx + 1, $value);
    }
    $st->execute();
    return (int)$st->fetchColumn();
}

function warehouse_transfer_search(array $config, array $filters = [], int $limit = 300, int $offset = 0): array {
    $pdo = warehouse_pdo($config);
    $query = warehouse_transfer_search_query_parts($config, $filters);
    if (!($query['allowed'] ?? false)) {
        return [];
    }
    $filters = $query['filters'] ?? warehouse_transfer_filter_values($filters);
    $where = $query['where'] ?? [];
    $params = $query['params'] ?? [];

    $sql = "
        SELECT tr.*, 
               sw.name AS source_warehouse_name, sw.code AS source_warehouse_code, sw.warehouse_type AS source_warehouse_type,
               tw.name AS target_warehouse_name, tw.code AS target_warehouse_code, tw.warehouse_type AS target_warehouse_type
        FROM stock_transfers tr
        INNER JOIN warehouses sw ON sw.id = tr.source_warehouse_id
        INNER JOIN warehouses tw ON tw.id = tr.target_warehouse_id
    ";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY tr.id DESC LIMIT ? OFFSET ?';

    $st = $pdo->prepare($sql);
    $idx = 1;
    foreach ($params as $value) {
        $st->bindValue($idx++, $value);
    }
    $st->bindValue($idx++, max(1, $limit), PDO::PARAM_INT);
    $st->bindValue($idx, max(0, $offset), PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    $rows = warehouse_transfer_attach_items($config, $rows);

    $userIds = [];
    foreach ($rows as $row) {
        foreach (['requested_by', 'accepted_by', 'rejected_by', 'cancelled_by'] as $field) {
            $uid = (int)($row[$field] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = $uid;
            }
        }
    }
    $userMap = warehouse_resolved_auth_user_map($config, array_values($userIds));
    foreach ($rows as &$row) {
        foreach (['requested_by', 'accepted_by', 'rejected_by', 'cancelled_by'] as $field) {
            $uid = (int)($row[$field] ?? 0);
            $row[$field . '_name'] = $userMap[$uid]['resolved_name'] ?? ($uid > 0 ? ('User #' . $uid) : '');
        }
    }
    unset($row);

    return $rows;
}

function warehouse_transfer_create_batch(array $config, int $sourceWarehouseId, int $targetWarehouseId, array $itemsInput, ?string $referenceNo = null, ?string $note = null): int {
    $pdo = warehouse_pdo($config);

    if ($sourceWarehouseId < 1 || $targetWarehouseId < 1) {
        throw new RuntimeException('Forrás és cél raktár megadása kötelező.');
    }
    if ($sourceWarehouseId === $targetWarehouseId) {
        throw new RuntimeException('A forrás és a cél raktár nem lehet azonos.');
    }
    if (!warehouse_user_can_manage_warehouse($config, $sourceWarehouseId)) {
        throw new RuntimeException('A forrás raktárhoz nincs átadási jogosultságod.');
    }

    $source = warehouse_find($config, $sourceWarehouseId);
    $target = warehouse_find($config, $targetWarehouseId);
    if (!$source || !$target) {
        throw new RuntimeException('A forrás vagy cél raktár nem található.');
    }
    if ((int)$source['is_active'] !== 1 || (int)$target['is_active'] !== 1) {
        throw new RuntimeException('Csak aktív raktárak között lehet átadást indítani.');
    }
    if (warehouse_type_normalize((string)($source['warehouse_type'] ?? 'internal')) !== 'internal' || warehouse_type_normalize((string)($target['warehouse_type'] ?? 'internal')) !== 'internal') {
        throw new RuntimeException('Raktárközi átadáshoz belső forrás és belső cél raktár szükséges.');
    }

    $items = warehouse_transfer_normalize_items_input($config, $sourceWarehouseId, $itemsInput);
    $referenceNo = trim((string)$referenceNo);
    $note = trim((string)$note);
    $representative = $items[0];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO stock_transfers (source_warehouse_id, target_warehouse_id, material_id, quantity, status, reference_no, note, requested_by) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([
            $sourceWarehouseId,
            $targetWarehouseId,
            (int)$representative['material_id'],
            $representative['quantity'],
            'pending',
            $referenceNo !== '' ? $referenceNo : null,
            $note !== '' ? $note : null,
            current_auth_user_id(),
        ]);
        $transferId = (int)$pdo->lastInsertId();

        if (warehouse_transfer_items_table_exists($config)) {
            $itemInsert = $pdo->prepare('INSERT INTO stock_transfer_items (transfer_id, material_id, quantity) VALUES (?,?,?)');
            foreach ($items as &$item) {
                $itemInsert->execute([
                    $transferId,
                    (int)$item['material_id'],
                    $item['quantity'],
                ]);
                $transferItemId = (int)$pdo->lastInsertId();
                $item['transfer_item_id'] = $transferItemId;
                if ($transferItemId > 0 && !empty($item['identifiers'])) {
                    warehouse_transfer_item_identifiers_save($pdo, $transferItemId, (array)$item['identifiers']);
                }
            }
            unset($item);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $auditItems = [];
    foreach ($items as $item) {
        $auditRow = [
            'material_id' => (int)$item['material_id'],
            'material_sku' => (string)($item['material']['sku'] ?? ''),
            'material_name' => (string)($item['material']['name'] ?? ''),
            'quantity' => $item['quantity'],
        ];
        if (!empty($item['identifiers'])) {
            $auditRow['identifiers'] = array_values(array_map(static fn(array $row): string => (string)($row['identifier_value'] ?? ''), (array)$item['identifiers']));
        }
        $auditItems[] = $auditRow;
    }

    warehouse_audit($config, 'transfer.create', 'stock_transfer', $transferId, [
        'reference' => warehouse_transfer_reference($transferId),
        'source_warehouse_id' => $sourceWarehouseId,
        'source_warehouse_name' => (string)$source['name'],
        'target_warehouse_id' => $targetWarehouseId,
        'target_warehouse_name' => (string)$target['name'],
        'item_count' => count($auditItems),
        'items' => $auditItems,
        'reference_no' => $referenceNo !== '' ? $referenceNo : null,
        'note' => $note !== '' ? $note : null,
    ]);

    return $transferId;
}

function warehouse_external_transfer_reference_generate(array $config, int $sourceWarehouseId): string {
    $source = warehouse_find($config, $sourceWarehouseId);
    if (!$source) {
        throw new RuntimeException('A forrás raktár nem található.');
    }
    $code = strtoupper(trim((string)($source['code'] ?? '')));
    $code = preg_replace('/[^A-Z0-9]+/', '', $code ?? '') ?: ('W' . $sourceWarehouseId);
    $prefix = 'PP-' . $code . '-';

    $pdo = warehouse_pdo($config);
    $st = $pdo->prepare('SELECT reference_no FROM stock_transfers WHERE transfer_type = ? AND source_warehouse_id = ? AND reference_no LIKE ?');
    $st->execute(['external', $sourceWarehouseId, $prefix . '%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ref) {
        $ref = (string)$ref;
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $ref, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $prefix . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}

/**
 * Külső partneres kiadás vagy visszavétel létrehozása.
 * A forrás / cél raktár és az azonosító-mozgatás irányát a művelet típusa határozza meg.
 */
function warehouse_external_transfer_create_batch(
    array $config,
    int $sourceWarehouseId,
    int $targetWarehouseId,
    array $itemsInput,
    bool $autoReference = true,
    ?string $referenceNo = null,
    ?string $receiverName = null,
    ?string $receiverPhone = null,
    ?string $receiverEmail = null,
    ?string $projectNo = null,
    ?string $note = null,
    ?string $signatureData = null
): int {
    $pdo = warehouse_pdo($config);

    if ($sourceWarehouseId < 1 || $targetWarehouseId < 1) {
        throw new RuntimeException('Forrás és külső partner raktár megadása kötelező.');
    }
    if ($sourceWarehouseId === $targetWarehouseId) {
        throw new RuntimeException('A forrás és a cél raktár nem lehet azonos.');
    }

    $source = warehouse_find($config, $sourceWarehouseId);
    $target = warehouse_find($config, $targetWarehouseId);
    if (!$source || !$target) {
        throw new RuntimeException('A forrás vagy a külső partner raktár nem található.');
    }
    if ((int)$source['is_active'] !== 1 || (int)$target['is_active'] !== 1) {
        throw new RuntimeException('Csak aktív raktárak között lehet átadást indítani.');
    }

    $sourceType = warehouse_type_normalize((string)($source['warehouse_type'] ?? 'internal'));
    $targetType = warehouse_type_normalize((string)($target['warehouse_type'] ?? 'internal'));
    $isOutbound = $sourceType === 'internal' && $targetType === 'external_partner';
    $isInbound = $sourceType === 'external_partner' && $targetType === 'internal';

    if (!$isOutbound && !$isInbound) {
        throw new RuntimeException('Külsős partneres művelethez egy belső és egy külső partner raktár szükséges.');
    }

    if ($isOutbound) {
        if (!warehouse_user_can_manage_warehouse($config, $sourceWarehouseId)) {
            throw new RuntimeException('A forrás raktárhoz nincs átadási jogosultságod.');
        }
    } else {
        if (!warehouse_user_can_manage_warehouse($config, $targetWarehouseId)) {
            throw new RuntimeException('A cél belső raktárhoz nincs átadási jogosultságod.');
        }
    }

    $items = warehouse_transfer_normalize_items_input($config, $sourceWarehouseId, $itemsInput);
    $autoReference = $autoReference ? true : false;
    $referenceNo = trim((string)$referenceNo);
    $referenceWarehouseId = $isOutbound ? $sourceWarehouseId : $targetWarehouseId;
    if ($autoReference) {
        $referenceNo = warehouse_external_transfer_reference_generate($config, $referenceWarehouseId);
    }
    if (!warehouse_transfer_signature_columns_exist($config)) {
        throw new RuntimeException('Az aláírás kezeléséhez előbb futtasd a külsős átadási aláírás frissítő SQL-t.');
    }

    $receiverName = trim((string)$receiverName);
    $receiverPhone = trim((string)$receiverPhone);
    $receiverEmail = trim((string)$receiverEmail);
    $projectNo = trim((string)$projectNo);
    $note = trim((string)$note);
    $signatureData = warehouse_signature_data_normalize($signatureData);
    if ($signatureData === '') {
        throw new RuntimeException('A külsős partneres művelethez az aláírás kötelező.');
    }

    $externalWarehouse = $isOutbound ? $target : $source;
    $partnerId = (int)($externalWarehouse['partner_id'] ?? 0);
    $partnerName = trim((string)($externalWarehouse['partner_name'] ?? ''));
    if ($partnerName === '') {
        $partnerName = (string)$externalWarehouse['name'];
    }
    if ($receiverName === '') {
        $receiverName = trim((string)($externalWarehouse['partner_receiver_name'] ?? ''));
    }
    if ($receiverPhone === '') {
        $receiverPhone = trim((string)($externalWarehouse['partner_phone'] ?? ''));
    }
    if ($receiverEmail === '') {
        $receiverEmail = trim((string)($externalWarehouse['partner_email'] ?? ''));
    }

    $representative = $items[0];
    $directionLabel = $isOutbound ? 'Külsős partner kiadás' : 'Külső partnertől visszavétel';

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO stock_transfers (source_warehouse_id, target_warehouse_id, material_id, quantity, status, transfer_type, reference_no, note, requested_by, accepted_by, accepted_at, partner_id, partner_name, receiver_name, receiver_phone, receiver_email, project_no, auto_reference, receiver_signature_data, receiver_signature_signed_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,NOW())');
        $st->execute([
            $sourceWarehouseId,
            $targetWarehouseId,
            (int)$representative['material_id'],
            $representative['quantity'],
            'accepted',
            'external',
            $referenceNo !== '' ? $referenceNo : null,
            $note !== '' ? $note : null,
            current_auth_user_id(),
            current_auth_user_id(),
            $partnerId > 0 ? $partnerId : null,
            $partnerName !== '' ? $partnerName : null,
            $receiverName !== '' ? $receiverName : null,
            $receiverPhone !== '' ? $receiverPhone : null,
            $receiverEmail !== '' ? $receiverEmail : null,
            $projectNo !== '' ? $projectNo : null,
            $autoReference ? 1 : 0,
            $signatureData,
        ]);
        $transferId = (int)$pdo->lastInsertId();

        if (warehouse_transfer_items_table_exists($config)) {
            $itemInsert = $pdo->prepare('INSERT INTO stock_transfer_items (transfer_id, material_id, quantity) VALUES (?,?,?)');
            foreach ($items as &$item) {
                $itemInsert->execute([
                    $transferId,
                    (int)$item['material_id'],
                    $item['quantity'],
                ]);
                $transferItemId = (int)$pdo->lastInsertId();
                $item['transfer_item_id'] = $transferItemId;
                if ($transferItemId > 0 && !empty($item['identifiers'])) {
                    warehouse_transfer_item_identifiers_save($pdo, $transferItemId, (array)$item['identifiers']);
                }
            }
            unset($item);
        }

        $baseNote = $directionLabel . ' #' . $transferId . ' [' . (string)$source['code'] . ' → ' . (string)$target['code'] . ']';
        if ($partnerName !== '') {
            $baseNote .= ' | ' . $partnerName;
        }
        if ($receiverName !== '') {
            $baseNote .= ' | Kapcsolattartó: ' . $receiverName;
        }
        if ($projectNo !== '') {
            $baseNote .= ' | Projekt: ' . $projectNo;
        }
        if ($note !== '') {
            $baseNote .= ' | ' . $note;
        }

        $insMovement = $pdo->prepare('INSERT INTO stock_movements (warehouse_id, material_id, movement_type, quantity_change, quantity_before, quantity_after, reference_no, note, performed_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $auditItems = [];

        foreach ($items as $item) {
            $materialId = (int)($item['material_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            if ($materialId < 1 || $qty <= 0) {
                continue;
            }
            $qtyS = warehouse_decimal_string($qty);

            $sourceState = warehouse_stock_sync_locked($pdo, $config, $sourceWarehouseId, $materialId);
            $sourceBefore = (float)$sourceState['quantity'];
            if ($sourceBefore < $qty - 0.0005) {
                throw new RuntimeException('A forrás raktárban nincs elegendő készlet ehhez: ' . (string)($item['material']['name'] ?? 'ismeretlen anyag') . '.');
            }
            $sourceAfter = $sourceBefore - $qty;
            $sourceBeforeS = warehouse_decimal_string($sourceBefore);
            $sourceAfterS = warehouse_decimal_string($sourceAfter);
            if ((int)$sourceState['id'] > 0) {
                $pdo->prepare('UPDATE warehouse_stock SET quantity=?, updated_by=? WHERE id=?')->execute([$sourceAfterS, current_auth_user_id(), (int)$sourceState['id']]);
            } else {
                throw new RuntimeException('A forrás raktári készletsor nem található.');
            }

            $targetState = warehouse_stock_sync_locked($pdo, $config, $targetWarehouseId, $materialId);
            $targetBefore = (float)$targetState['quantity'];
            $targetAfter = $targetBefore + $qty;
            $targetBeforeS = warehouse_decimal_string($targetBefore);
            $targetAfterS = warehouse_decimal_string($targetAfter);
            if ((int)$targetState['id'] > 0) {
                $pdo->prepare('UPDATE warehouse_stock SET quantity=?, updated_by=? WHERE id=?')->execute([$targetAfterS, current_auth_user_id(), (int)$targetState['id']]);
            } else {
                $pdo->prepare('INSERT INTO warehouse_stock (warehouse_id, material_id, quantity, created_by, updated_by) VALUES (?,?,?,?,?)')->execute([$targetWarehouseId, $materialId, $targetAfterS, current_auth_user_id(), current_auth_user_id()]);
            }

            $movedIdentifiers = warehouse_transfer_move_identifiers($pdo, $item, $sourceWarehouseId, $targetWarehouseId);
            $identifierValues = array_values(array_map(static fn(array $row): string => (string)($row['identifier_value'] ?? ''), $movedIdentifiers));

            $itemNote = $baseNote . ' | ' . (string)($item['material']['name'] ?? '') . ' [' . (string)($item['material']['sku'] ?? '') . ']';
            if ($identifierValues !== []) {
                $itemNote .= ' | ' . warehouse_material_identifier_value_label((array)($item['material'] ?? [])) . ': ' . implode(', ', $identifierValues);
            }

            $insMovement->execute([
                $sourceWarehouseId,
                $materialId,
                'external_transfer_out',
                warehouse_decimal_string(-1 * $qty),
                $sourceBeforeS,
                $sourceAfterS,
                $referenceNo !== '' ? $referenceNo : null,
                $itemNote,
                current_auth_user_id(),
            ]);

            $insMovement->execute([
                $targetWarehouseId,
                $materialId,
                'external_transfer_in',
                $qtyS,
                $targetBeforeS,
                $targetAfterS,
                $referenceNo !== '' ? $referenceNo : null,
                $itemNote,
                current_auth_user_id(),
            ]);

            $auditRow = [
                'material_id' => $materialId,
                'material_sku' => (string)($item['material']['sku'] ?? ''),
                'material_name' => (string)($item['material']['name'] ?? ''),
                'quantity' => $qtyS,
                'source_before' => $sourceBeforeS,
                'source_after' => $sourceAfterS,
                'target_before' => $targetBeforeS,
                'target_after' => $targetAfterS,
            ];
            if ($identifierValues !== []) {
                $auditRow['identifiers'] = $identifierValues;
            }
            $auditItems[] = $auditRow;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    warehouse_audit($config, 'transfer.external_create', 'stock_transfer', $transferId, [
        'reference' => warehouse_transfer_reference($transferId),
        'transfer_type' => 'external',
        'external_direction' => $isOutbound ? 'outbound' : 'inbound',
        'source_warehouse_id' => $sourceWarehouseId,
        'source_warehouse_name' => (string)$source['name'],
        'target_warehouse_id' => $targetWarehouseId,
        'target_warehouse_name' => (string)$target['name'],
        'partner_id' => $partnerId > 0 ? $partnerId : null,
        'partner_name' => $partnerName !== '' ? $partnerName : null,
        'receiver_name' => $receiverName !== '' ? $receiverName : null,
        'receiver_phone' => $receiverPhone !== '' ? $receiverPhone : null,
        'receiver_email' => $receiverEmail !== '' ? $receiverEmail : null,
        'project_no' => $projectNo !== '' ? $projectNo : null,
        'reference_no' => $referenceNo !== '' ? $referenceNo : null,
        'auto_reference' => $autoReference,
        'signature_present' => $signatureData !== '',
        'note' => $note !== '' ? $note : null,
        'item_count' => count($auditItems),
        'items' => $auditItems,
    ]);

    return $transferId;
}

function warehouse_transfer_create(array $config, int $sourceWarehouseId, int $targetWarehouseId, int $materialId, mixed $quantityInput, ?string $referenceNo = null, ?string $note = null): int {
    return warehouse_transfer_create_batch($config, $sourceWarehouseId, $targetWarehouseId, [[
        'material_id' => $materialId,
        'quantity' => $quantityInput,
    ]], $referenceNo, $note);
}

function warehouse_transfer_accept(array $config, int $transferId, ?string $decisionNote = null): int {
    $pdo = warehouse_pdo($config);
    $transfer = warehouse_transfer_find($config, $transferId);
    if (!$transfer) {
        throw new RuntimeException('Az átadás nem található.');
    }
    if ((string)$transfer['status'] !== 'pending') {
        throw new RuntimeException('Csak függőben lévő átadás fogadható el.');
    }
    $targetId = (int)$transfer['target_warehouse_id'];
    if (!warehouse_user_can_manage_warehouse_local($config, $targetId)) {
        throw new RuntimeException('A cél raktárhoz nincs elfogadási jogosultságod.');
    }

    $decisionNote = trim((string)$decisionNote);

    $pdo->beginTransaction();
    try {
        $lockTransfer = $pdo->prepare('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE');
        $lockTransfer->execute([$transferId]);
        $lockedTransfer = $lockTransfer->fetch();
        if (!$lockedTransfer) {
            throw new RuntimeException('Az átadás nem található.');
        }
        if ((string)$lockedTransfer['status'] !== 'pending') {
            throw new RuntimeException('Az átadás időközben már feldolgozásra került.');
        }

        $sourceId = (int)$lockedTransfer['source_warehouse_id'];
        $items = warehouse_transfer_items_for_transfer($config, $transferId);
        if ($items === []) {
            throw new RuntimeException('Az átadás nem tartalmaz tételt.');
        }

        $referenceNo = trim((string)($lockedTransfer['reference_no'] ?? ''));
        if ($referenceNo === '') {
            $referenceNo = warehouse_transfer_reference($transferId);
        }
        $baseNote = 'Raktárközi átadás #' . $transferId . ' [' . $transfer['source_warehouse_code'] . ' → ' . $transfer['target_warehouse_code'] . ']';
        if ($decisionNote !== '') {
            $baseNote .= ' | ' . $decisionNote;
        }

        $insMovement = $pdo->prepare('INSERT INTO stock_movements (warehouse_id, material_id, movement_type, quantity_change, quantity_before, quantity_after, reference_no, note, performed_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $auditItems = [];

        foreach ($items as $item) {
            $materialId = (int)($item['material_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            if ($materialId < 1 || $qty <= 0) {
                continue;
            }
            $qtyS = warehouse_decimal_string($qty);

            $sourceState = warehouse_stock_sync_locked($pdo, $config, $sourceId, $materialId);
            $sourceBefore = (float)$sourceState['quantity'];
            if ($sourceBefore < $qty - 0.0005) {
                throw new RuntimeException('A forrás raktárban nincs elegendő készlet az elfogadáshoz ehhez: ' . (string)($item['material_name'] ?? 'ismeretlen anyag') . '.');
            }
            $sourceAfter = $sourceBefore - $qty;
            $sourceBeforeS = warehouse_decimal_string($sourceBefore);
            $sourceAfterS = warehouse_decimal_string($sourceAfter);
            if ((int)$sourceState['id'] > 0) {
                $pdo->prepare('UPDATE warehouse_stock SET quantity=?, updated_by=? WHERE id=?')->execute([$sourceAfterS, current_auth_user_id(), (int)$sourceState['id']]);
            } else {
                throw new RuntimeException('A forrás raktári készletsor nem található.');
            }

            $targetState = warehouse_stock_sync_locked($pdo, $config, $targetId, $materialId);
            $targetBefore = (float)$targetState['quantity'];
            $targetAfter = $targetBefore + $qty;
            $targetBeforeS = warehouse_decimal_string($targetBefore);
            $targetAfterS = warehouse_decimal_string($targetAfter);
            if ((int)$targetState['id'] > 0) {
                $pdo->prepare('UPDATE warehouse_stock SET quantity=?, updated_by=? WHERE id=?')->execute([$targetAfterS, current_auth_user_id(), (int)$targetState['id']]);
            } else {
                $pdo->prepare('INSERT INTO warehouse_stock (warehouse_id, material_id, quantity, created_by, updated_by) VALUES (?,?,?,?,?)')->execute([$targetId, $materialId, $targetAfterS, current_auth_user_id(), current_auth_user_id()]);
            }

            $movedIdentifiers = warehouse_transfer_move_identifiers($pdo, $item, $sourceId, $targetId);
            $identifierValues = array_values(array_map(static fn(array $row): string => (string)($row['identifier_value'] ?? ''), $movedIdentifiers));

            $itemNote = $baseNote . ' | ' . (string)($item['material_name'] ?? '') . ' [' . (string)($item['sku'] ?? '') . ']';
            if ($identifierValues !== []) {
                $itemNote .= ' | ' . warehouse_material_identifier_value_label($item) . ': ' . implode(', ', $identifierValues);
            }

            $insMovement->execute([
                $sourceId,
                $materialId,
                'transfer_out',
                warehouse_decimal_string(-1 * $qty),
                $sourceBeforeS,
                $sourceAfterS,
                $referenceNo,
                $itemNote,
                current_auth_user_id(),
            ]);

            $insMovement->execute([
                $targetId,
                $materialId,
                'transfer_in',
                $qtyS,
                $targetBeforeS,
                $targetAfterS,
                $referenceNo,
                $itemNote,
                current_auth_user_id(),
            ]);

            $auditRow = [
                'material_id' => $materialId,
                'material_sku' => (string)($item['sku'] ?? ''),
                'material_name' => (string)($item['material_name'] ?? ''),
                'quantity' => $qtyS,
                'source_before' => $sourceBeforeS,
                'source_after' => $sourceAfterS,
                'target_before' => $targetBeforeS,
                'target_after' => $targetAfterS,
            ];
            if ($identifierValues !== []) {
                $auditRow['identifiers'] = $identifierValues;
            }
            $auditItems[] = $auditRow;
        }

        $pdo->prepare('UPDATE stock_transfers SET status="accepted", accepted_by=?, accepted_at=NOW(), decision_note=? WHERE id=?')->execute([
            current_auth_user_id(),
            $decisionNote !== '' ? $decisionNote : null,
            $transferId,
        ]);

        $pdo->commit();

        warehouse_audit($config, 'transfer.accept', 'stock_transfer', $transferId, [
            'reference' => warehouse_transfer_reference($transferId),
            'source_warehouse_id' => $sourceId,
            'target_warehouse_id' => $targetId,
            'item_count' => count($auditItems),
            'items' => $auditItems,
            'decision_note' => $decisionNote !== '' ? $decisionNote : null,
        ]);

        return $transferId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function warehouse_transfer_reject(array $config, int $transferId, ?string $decisionNote = null): int {
    $pdo = warehouse_pdo($config);
    $transfer = warehouse_transfer_find($config, $transferId);
    if (!$transfer) {
        throw new RuntimeException('Az átadás nem található.');
    }
    if ((string)$transfer['status'] !== 'pending') {
        throw new RuntimeException('Csak függőben lévő átadás utasítható el.');
    }
    $targetId = (int)$transfer['target_warehouse_id'];
    if (!warehouse_user_can_manage_warehouse_local($config, $targetId)) {
        throw new RuntimeException('A cél raktárhoz nincs elutasítási jogosultságod.');
    }
    $decisionNote = trim((string)$decisionNote);

    $st = $pdo->prepare('UPDATE stock_transfers SET status="rejected", rejected_by=?, rejected_at=NOW(), decision_note=? WHERE id=? AND status="pending"');
    $st->execute([current_auth_user_id(), $decisionNote !== '' ? $decisionNote : null, $transferId]);
    if ($st->rowCount() < 1) {
        throw new RuntimeException('Az átadás időközben már feldolgozásra került.');
    }

    warehouse_audit($config, 'transfer.reject', 'stock_transfer', $transferId, [
        'reference' => warehouse_transfer_reference($transferId),
        'decision_note' => $decisionNote !== '' ? $decisionNote : null,
    ]);

    return $transferId;
}

function warehouse_transfer_cancel(array $config, int $transferId, ?string $decisionNote = null): int {
    $pdo = warehouse_pdo($config);
    $transfer = warehouse_transfer_find($config, $transferId);
    if (!$transfer) {
        throw new RuntimeException('Az átadás nem található.');
    }
    if ((string)$transfer['status'] !== 'pending') {
        throw new RuntimeException('Csak függőben lévő átadás törölhető.');
    }
    $sourceId = (int)$transfer['source_warehouse_id'];
    if (!warehouse_user_can_manage_warehouse($config, $sourceId)) {
        throw new RuntimeException('A forrás raktárhoz nincs törlési jogosultságod.');
    }
    $decisionNote = trim((string)$decisionNote);

    $st = $pdo->prepare('UPDATE stock_transfers SET status="cancelled", cancelled_by=?, cancelled_at=NOW(), decision_note=? WHERE id=? AND status="pending"');
    $st->execute([current_auth_user_id(), $decisionNote !== '' ? $decisionNote : null, $transferId]);
    if ($st->rowCount() < 1) {
        throw new RuntimeException('Az átadás időközben már feldolgozásra került.');
    }

    warehouse_audit($config, 'transfer.cancel', 'stock_transfer', $transferId, [
        'reference' => warehouse_transfer_reference($transferId),
        'decision_note' => $decisionNote !== '' ? $decisionNote : null,
    ]);

    return $transferId;
}

// -----------------------------------------------------------------------------
// Ideiglenes beolvasás / staging
// A végleges rögzítés előtti gyűjtő- és ellenőrző logika.
// -----------------------------------------------------------------------------
function warehouse_identifier_staging_feature_ready(array $config): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = warehouse_pdo($config);
        $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'material_identifier_staging'")->fetchColumn();
        return $cache = $tableExists;
    } catch (Throwable $e) {
        return $cache = false;
    }
}

function warehouse_identifier_staging_filters(array $input): array {
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = max(1, min(200, (int)($input['per_page'] ?? 100)));
    $status = trim((string)($input['status'] ?? 'pending'));
    if (!in_array($status, ['all', 'pending', 'assigned', 'discarded'], true)) {
        $status = 'pending';
    }

    return [
        'q' => trim((string)($input['q'] ?? '')),
        'status' => $status,
        'page' => $page,
        'per_page' => $perPage,
    ];
}

function warehouse_identifier_staging_list(array $config, array $input = []): array {
    $filters = warehouse_identifier_staging_filters($input);
    if (!warehouse_identifier_staging_feature_ready($config)) {
        return [
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'pages' => 1,
            'per_page' => $filters['per_page'],
            'offset' => 0,
            'q' => $filters['q'],
            'status' => $filters['status'],
        ];
    }

    $pdo = warehouse_pdo($config);
    $where = [];
    $params = [];

    if ($filters['status'] !== 'all') {
        $where[] = 'mis.status = ?';
        $params[] = $filters['status'];
    }
    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $where[] = '(mis.identifier_value LIKE ? OR COALESCE(mis.secondary_identifier_value, "") LIKE ? OR COALESCE(mis.capture_source, "") LIKE ? OR COALESCE(mis.note, "") LIKE ? OR COALESCE(mis.result_message, "") LIKE ? OR COALESCE(m.sku, "") LIKE ? OR COALESCE(m.name, "") LIKE ? OR COALESCE(w.name, "") LIKE ? OR COALESCE(w.code, "") LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $countSt = $pdo->prepare('SELECT COUNT(*) FROM material_identifier_staging mis LEFT JOIN material_items m ON m.id = mis.assigned_material_id LEFT JOIN warehouses w ON w.id = mis.assigned_warehouse_id' . $whereSql);
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();
    $pages = max(1, (int)ceil($total / max(1, $filters['per_page'])));
    $page = min($filters['page'], $pages);
    $offset = ($page - 1) * $filters['per_page'];

    $sql = 'SELECT mis.*, m.sku AS assigned_material_sku, m.name AS assigned_material_name, m.identifier_label AS assigned_identifier_label, w.name AS assigned_warehouse_name, w.code AS assigned_warehouse_code
            FROM material_identifier_staging mis
            LEFT JOIN material_items m ON m.id = mis.assigned_material_id
            LEFT JOIN warehouses w ON w.id = mis.assigned_warehouse_id'
        . $whereSql
        . ' ORDER BY CASE mis.status WHEN "pending" THEN 0 WHEN "assigned" THEN 1 ELSE 2 END, mis.id DESC LIMIT ? OFFSET ?';
    $st = $pdo->prepare($sql);
    $index = 1;
    foreach ($params as $param) {
        $st->bindValue($index++, $param, PDO::PARAM_STR);
    }
    $st->bindValue($index++, $filters['per_page'], PDO::PARAM_INT);
    $st->bindValue($index, $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    $userIds = [];
    foreach ($rows as $row) {
        $createdBy = (int)($row['created_by'] ?? 0);
        $assignedBy = (int)($row['assigned_by'] ?? 0);
        if ($createdBy > 0) {
            $userIds[$createdBy] = $createdBy;
        }
        if ($assignedBy > 0) {
            $userIds[$assignedBy] = $assignedBy;
        }
    }
    $userMap = warehouse_resolved_auth_user_map($config, array_values($userIds));
    foreach ($rows as &$row) {
        $createdBy = (int)($row['created_by'] ?? 0);
        $assignedBy = (int)($row['assigned_by'] ?? 0);
        $row['created_by_name'] = $userMap[$createdBy]['resolved_name'] ?? ($createdBy > 0 ? ('User #' . $createdBy) : '—');
        $row['assigned_by_name'] = $userMap[$assignedBy]['resolved_name'] ?? ($assignedBy > 0 ? ('User #' . $assignedBy) : '');
    }
    unset($row);

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $filters['per_page'],
        'offset' => $offset,
        'q' => $filters['q'],
        'status' => $filters['status'],
    ];
}

function warehouse_identifier_staging_parse_candidates(string $rawInput, string $scanMode = 'single'): array {
    $scanMode = strtolower(trim($scanMode));
    $scanMode = $scanMode === 'pair' ? 'pair' : 'single';
    $lines = preg_split('/
|
|
/', (string)$rawInput) ?: [];
    $entries = [];
    foreach ($lines as $index => $line) {
        $lineNo = $index + 1;
        $value = trim((string)$line);
        if ($value === '') {
            continue;
        }
        $entries[] = [
            'line' => $lineNo,
            'value' => $value,
        ];
    }

    if ($scanMode !== 'pair') {
        return array_map(static function (array $entry): array {
            return [
                'line' => (int)$entry['line'],
                'value' => (string)$entry['value'],
                'normalized' => warehouse_material_identifier_normalize((string)$entry['value']),
                'secondary_value' => '',
                'secondary_normalized' => '',
                'scan_mode' => 'single',
            ];
        }, $entries);
    }

    $candidates = [];
    $pendingFirst = null;
    foreach ($entries as $entry) {
        $parts = preg_split('/	+|\s*\|\s*|\s*;\s*/u', (string)$entry['value']) ?: [];
        $parts = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $parts), static fn(string $value): bool => $value !== ''));
        if (count($parts) >= 2) {
            $firstValue = (string)$parts[0];
            $secondValue = (string)$parts[1];
            $candidates[] = [
                'line' => (int)$entry['line'],
                'value' => $firstValue,
                'normalized' => warehouse_material_identifier_normalize($firstValue),
                'secondary_value' => $secondValue,
                'secondary_normalized' => warehouse_material_identifier_normalize($secondValue),
                'scan_mode' => 'pair',
            ];
            $pendingFirst = null;
            continue;
        }

        if ($pendingFirst === null) {
            $pendingFirst = $entry;
            continue;
        }

        $candidates[] = [
            'line' => (int)$pendingFirst['line'],
            'value' => (string)$pendingFirst['value'],
            'normalized' => warehouse_material_identifier_normalize((string)$pendingFirst['value']),
            'secondary_value' => (string)$entry['value'],
            'secondary_normalized' => warehouse_material_identifier_normalize((string)$entry['value']),
            'scan_mode' => 'pair',
        ];
        $pendingFirst = null;
    }

    if ($pendingFirst !== null) {
        $candidates[] = [
            'line' => (int)$pendingFirst['line'],
            'value' => (string)$pendingFirst['value'],
            'normalized' => warehouse_material_identifier_normalize((string)$pendingFirst['value']),
            'secondary_value' => '',
            'secondary_normalized' => '',
            'scan_mode' => 'pair_incomplete',
        ];
    }

    return $candidates;
}

/**
 * Ideiglenes beolvasás mentése a staging táblába.
 * Ugyanazt a szabályrendszert használja, mint a végleges azonosítórögzítés, de még anyaghoz rendelés nélkül.
 */
function warehouse_identifier_staging_capture_bulk(array $config, string $rawInput, ?string $captureSource = null, ?string $note = null, string $scanMode = 'single'): array {
    if (!warehouse_material_identifier_feature_ready($config)) {
        throw new RuntimeException('Az egyedi azonosítós funkció adatbázis része még nincs telepítve.');
    }
    if (!warehouse_identifier_staging_feature_ready($config)) {
        throw new RuntimeException('Az ideiglenes azonosító beolvasó adatbázis része még nincs telepítve.');
    }

    $captureSource = trim((string)$captureSource);
    $note = trim((string)$note);
    $scanMode = strtolower(trim($scanMode));
    $scanMode = $scanMode === 'pair' ? 'pair' : 'single';
    $candidates = warehouse_identifier_staging_parse_candidates($rawInput, $scanMode);

    $total = 0;
    $duplicateInputRows = 0;
    $alreadyPendingRows = 0;
    $errors = [];
    $seen = [];
    $validCandidates = [];

    foreach ($candidates as $candidate) {
        $lineNo = (int)($candidate['line'] ?? 0);
        $value = trim((string)($candidate['value'] ?? ''));
        $normalized = trim((string)($candidate['normalized'] ?? ''));
        $secondaryValue = trim((string)($candidate['secondary_value'] ?? ''));
        $secondaryNormalized = trim((string)($candidate['secondary_normalized'] ?? ''));
        $effectiveMode = (string)($candidate['scan_mode'] ?? $scanMode);

        if ($value === '') {
            continue;
        }
        $total++;

        if ($normalized === '') {
            if (count($errors) < 50) {
                $errors[] = 'Sor ' . $lineNo . ': az első kód üres vagy érvénytelen.';
            }
            continue;
        }

        if ($effectiveMode === 'pair_incomplete') {
            if (count($errors) < 50) {
                $errors[] = 'Sor ' . $lineNo . ': páros módban a második kód hiányzik.';
            }
            continue;
        }

        if ($scanMode === 'pair') {
            if ($secondaryValue === '' || $secondaryNormalized === '') {
                if (count($errors) < 50) {
                    $errors[] = 'Sor ' . $lineNo . ': páros módban két kód szükséges.';
                }
                continue;
            }
            if ($normalized === $secondaryNormalized) {
                if (count($errors) < 50) {
                    $errors[] = 'Sor ' . $lineNo . ': a két kód nem lehet azonos (' . $value . ').';
                }
                continue;
            }
        }

        foreach (array_filter([$normalized, $secondaryNormalized], static fn(string $norm): bool => $norm !== '') as $norm) {
            if (isset($seen[$norm])) {
                $duplicateInputRows++;
                if (count($errors) < 50) {
                    $errors[] = 'Sor ' . $lineNo . ': a most beolvasott listában már szerepel ez a kód (' . ($norm === $normalized ? $value : $secondaryValue) . ').';
                }
                continue 2;
            }
        }
        $seen[$normalized] = $lineNo;
        if ($secondaryNormalized !== '') {
            $seen[$secondaryNormalized] = $lineNo;
        }

        $validCandidates[] = [
            'line' => $lineNo,
            'value' => $value,
            'normalized' => $normalized,
            'secondary_value' => $secondaryValue,
            'secondary_normalized' => $secondaryNormalized,
            'scan_mode' => $scanMode,
        ];
    }

    if ($total <= 0) {
        throw new RuntimeException($scanMode === 'pair'
            ? 'Nem érkezett rögzíthető kódpár. Páros módban két sor alkot egy rekordot.'
            : 'Nem érkezett rögzíthető azonosító. Egy sorba egy kód kerüljön.');
    }

    if ($validCandidates === []) {
        return [
            'total_rows' => $total,
            'inserted_rows' => 0,
            'error_rows' => $duplicateInputRows,
            'duplicate_input_rows' => $duplicateInputRows,
            'already_pending_rows' => 0,
            'scan_mode' => $scanMode,
            'errors' => $errors,
        ];
    }

    $pdo = warehouse_pdo($config);
    $allNormalized = [];
    foreach ($validCandidates as $candidate) {
        $allNormalized[] = $candidate['normalized'];
        if ($candidate['secondary_normalized'] !== '') {
            $allNormalized[] = $candidate['secondary_normalized'];
        }
    }
    $allNormalized = array_values(array_unique($allNormalized));
    $placeholders = implode(',', array_fill(0, count($allNormalized), '?'));
    $st = $pdo->prepare('SELECT id, identifier_value_norm, secondary_identifier_value_norm FROM material_identifier_staging WHERE identifier_value_norm IN (' . $placeholders . ') OR secondary_identifier_value_norm IN (' . $placeholders . ')');
    $st->execute(array_merge($allNormalized, $allNormalized));
    $pendingSet = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $normA = trim((string)($row['identifier_value_norm'] ?? ''));
        $normB = trim((string)($row['secondary_identifier_value_norm'] ?? ''));
        if ($normA !== '') {
            $pendingSet[$normA] = true;
        }
        if ($normB !== '') {
            $pendingSet[$normB] = true;
        }
    }

    $globalConflicts = warehouse_material_identifier_code_conflicts($config, $allNormalized);

    $toInsert = [];
    foreach ($validCandidates as $candidate) {
        foreach (array_filter([$candidate['normalized'], $candidate['secondary_normalized']], static fn(string $norm): bool => $norm !== '') as $norm) {
            if (isset($pendingSet[$norm])) {
                $alreadyPendingRows++;
                if (count($errors) < 50) {
                    $badValue = $norm === $candidate['normalized'] ? $candidate['value'] : $candidate['secondary_value'];
                    $errors[] = 'Sor ' . $candidate['line'] . ': ez a kód már szerepel az ideiglenes listában (' . $badValue . ').';
                }
                continue 2;
            }
            if (isset($globalConflicts[$norm])) {
                $badValue = (string)($globalConflicts[$norm]['raw_value'] ?? ($norm === $candidate['normalized'] ? $candidate['value'] : $candidate['secondary_value']));
                if (count($errors) < 50) {
                    $errors[] = 'Sor ' . $candidate['line'] . ': ez a kód már szerepel az adatbázisban (' . $badValue . ').';
                }
                $alreadyPendingRows++;
                continue 2;
            }
        }
        $toInsert[] = $candidate;
    }

    $inserted = 0;
    if ($toInsert !== []) {
        $ins = $pdo->prepare('INSERT INTO material_identifier_staging (identifier_value, identifier_value_norm, secondary_identifier_value, secondary_identifier_value_norm, scan_mode, status, capture_source, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $pdo->beginTransaction();
        try {
            foreach ($toInsert as $candidate) {
                $ins->execute([
                    $candidate['value'],
                    $candidate['normalized'],
                    $candidate['secondary_value'] !== '' ? $candidate['secondary_value'] : null,
                    $candidate['secondary_normalized'] !== '' ? $candidate['secondary_normalized'] : null,
                    $scanMode,
                    'pending',
                    $captureSource !== '' ? $captureSource : null,
                    $note !== '' ? $note : null,
                    current_auth_user_id() ?: null,
                ]);
                $inserted++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    warehouse_audit($config, 'identifier_staging.capture', 'identifier_staging', null, [
        'total_rows' => $total,
        'inserted_rows' => $inserted,
        'duplicate_input_rows' => $duplicateInputRows,
        'already_pending_rows' => $alreadyPendingRows,
        'capture_source' => $captureSource !== '' ? $captureSource : null,
        'scan_mode' => $scanMode,
    ]);

    return [
        'total_rows' => $total,
        'inserted_rows' => $inserted,
        'error_rows' => $duplicateInputRows + $alreadyPendingRows,
        'duplicate_input_rows' => $duplicateInputRows,
        'already_pending_rows' => $alreadyPendingRows,
        'scan_mode' => $scanMode,
        'errors' => $errors,
    ];
}

/**
 * Staging rekordok végleges hozzárendelése egy anyaghoz és raktárhoz.
 * Siker esetén a staging bejegyzés állapota is frissül, hogy nyoma maradjon az importnak.
 */
function warehouse_identifier_staging_assign(array $config, int $materialId, int $warehouseId, array $entryIds, ?string $assignmentNote = null): array {
    if (!warehouse_material_identifier_feature_ready($config)) {
        throw new RuntimeException('Az egyedi azonosítós funkció adatbázis része még nincs telepítve.');
    }
    if (!warehouse_identifier_staging_feature_ready($config)) {
        throw new RuntimeException('Az ideiglenes azonosító beolvasó adatbázis része még nincs telepítve.');
    }
    if ($materialId < 1 || $warehouseId < 1) {
        throw new RuntimeException('Az anyag és a raktár kiválasztása kötelező.');
    }
    if (!warehouse_user_can_manage_warehouse($config, $warehouseId)) {
        throw new RuntimeException('Ehhez a raktárhoz nincs kezelési jogosultságod.');
    }

    $material = warehouse_material_find($config, $materialId);
    if (!$material || (int)($material['is_identified'] ?? 0) !== 1) {
        throw new RuntimeException('Csak egyedi azonosítós anyaghoz lehet ideiglenes kódokat hozzárendelni.');
    }
    $warehouse = warehouse_find($config, $warehouseId);
    if (!$warehouse || (int)($warehouse['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('A kiválasztott raktár nem található vagy nem aktív.');
    }

    $assignmentNote = trim((string)$assignmentNote);
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn(int $id): bool => $id > 0)));
    if ($entryIds === []) {
        throw new RuntimeException('Nincs kiválasztott ideiglenes azonosító.');
    }

    $pdo = warehouse_pdo($config);
    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
    $st = $pdo->prepare('SELECT * FROM material_identifier_staging WHERE id IN (' . $placeholders . ') FOR UPDATE');

    $pdo->beginTransaction();
    try {
        $st->execute($entryIds);
        $loaded = [];
        foreach ($st->fetchAll() as $row) {
            $loaded[(int)$row['id']] = $row;
        }

        $setPendingError = $pdo->prepare('UPDATE material_identifier_staging SET result_message = ?, updated_at = NOW() WHERE id = ?');
        $setAssigned = $pdo->prepare('UPDATE material_identifier_staging SET status = "assigned", result_message = ?, assigned_material_id = ?, assigned_warehouse_id = ?, assigned_identifier_id = ?, assigned_by = ?, assigned_at = NOW(), updated_at = NOW() WHERE id = ?');

        $assigned = [];
        $errors = [];
        $seenNorm = [];

        foreach ($entryIds as $entryId) {
            if (!isset($loaded[$entryId])) {
                $errors[] = 'Az egyik kiválasztott ideiglenes sor már nem található (#' . $entryId . ').';
                continue;
            }

            $row = $loaded[$entryId];
            $identifierValue = (string)($row['identifier_value'] ?? '');
            $normalized = (string)($row['identifier_value_norm'] ?? '');
            $secondaryValue = (string)($row['secondary_identifier_value'] ?? '');
            $secondaryNormalized = (string)($row['secondary_identifier_value_norm'] ?? '');

            if ((string)($row['status'] ?? '') !== 'pending') {
                $message = warehouse_material_identifier_display_value($row) . ': ez a sor már nem függő állapotú.';
                $setPendingError->execute([$message, $entryId]);
                $errors[] = $message;
                continue;
            }

            foreach (array_filter([$normalized, $secondaryNormalized], static fn(string $value): bool => $value !== '') as $norm) {
                if (isset($seenNorm[$norm])) {
                    $message = warehouse_material_identifier_display_value($row) . ': duplikált kód a most kiválasztott ideiglenes listában.';
                    $setPendingError->execute([$message, $entryId]);
                    $errors[] = $message;
                    continue 2;
                }
            }
            if ($normalized !== '') {
                $seenNorm[$normalized] = $entryId;
            }
            if ($secondaryNormalized !== '') {
                $seenNorm[$secondaryNormalized] = $entryId;
            }

            $noteParts = [];
            if (trim((string)($row['note'] ?? '')) !== '') {
                $noteParts[] = trim((string)$row['note']);
            }
            if ($assignmentNote !== '') {
                $noteParts[] = $assignmentNote;
            }
            if (trim((string)($row['capture_source'] ?? '')) !== '') {
                $noteParts[] = 'Ideiglenes forrás: ' . trim((string)$row['capture_source']);
            }
            $noteParts[] = ((string)($row['scan_mode'] ?? 'single') === 'pair') ? 'Ideiglenes páros beolvasásból hozzárendelve' : 'Ideiglenes beolvasásból hozzárendelve';
            $realIdentifierNote = implode(' | ', array_filter($noteParts, static fn(string $value): bool => trim($value) !== ''));

            try {
                $identifierId = warehouse_material_identifier_create($config, [
                    'warehouse_id' => $warehouseId,
                    'material_id' => $materialId,
                    'identifier_value' => $identifierValue,
                    'secondary_identifier_value' => $secondaryValue,
                    'note' => $realIdentifierNote,
                    'exclude_staging_ids' => [$entryId],
                ]);
                $displayValue = warehouse_material_identifier_display_value($row);
                $message = 'Hozzárendelve: ' . $displayValue;
                $setAssigned->execute([
                    $message,
                    $materialId,
                    $warehouseId,
                    $identifierId,
                    current_auth_user_id() ?: null,
                    $entryId,
                ]);
                $assigned[] = $displayValue;
            } catch (Throwable $e) {
                $message = warehouse_material_identifier_display_value($row) . ': ' . $e->getMessage();
                $setPendingError->execute([$message, $entryId]);
                $errors[] = $message;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    warehouse_audit($config, 'identifier_staging.assign', 'identifier_staging', null, [
        'material_id' => $materialId,
        'material_name' => (string)($material['name'] ?? ''),
        'warehouse_id' => $warehouseId,
        'warehouse_name' => (string)($warehouse['name'] ?? ''),
        'selected_count' => count($entryIds),
        'assigned_count' => count($assigned),
        'error_count' => count($errors),
        'assigned_identifiers' => $assigned,
    ]);

    return [
        'selected_count' => count($entryIds),
        'assigned_count' => count($assigned),
        'error_count' => count($errors),
        'assigned' => $assigned,
        'errors' => $errors,
    ];
}

function warehouse_identifier_staging_discard(array $config, array $entryIds): array {
    if (!warehouse_identifier_staging_feature_ready($config)) {
        throw new RuntimeException('Az ideiglenes azonosító beolvasó adatbázis része még nincs telepítve.');
    }

    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn(int $id): bool => $id > 0)));
    if ($entryIds === []) {
        throw new RuntimeException('Nincs kiválasztott ideiglenes azonosító.');
    }

    $pdo = warehouse_pdo($config);
    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
    $st = $pdo->prepare('UPDATE material_identifier_staging SET status = "discarded", result_message = ?, assigned_by = ?, assigned_at = NOW(), updated_at = NOW() WHERE id IN (' . $placeholders . ') AND status = "pending"');
    $params = array_merge(['Kézzel elvetve.', current_auth_user_id() ?: null], $entryIds);
    $st->execute($params);
    $affected = (int)$st->rowCount();

    warehouse_audit($config, 'identifier_staging.discard', 'identifier_staging', null, [
        'selected_count' => count($entryIds),
        'discarded_count' => $affected,
    ]);

    return [
        'selected_count' => count($entryIds),
        'discarded_count' => $affected,
    ];
}


function warehouse_sql_ident(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function warehouse_resettable_tables(array $config): array {
    $pdo = warehouse_pdo($config);
    $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
    $tables = array_map(static fn(array $row): string => (string)$row['TABLE_NAME'], $pdo->query($sql)->fetchAll());
    $preserve = [
        'schema_migrations',
        'migrations',
        'migration_versions',
        'warehouse_migrations',
    ];
    return array_values(array_filter($tables, static fn(string $table): bool => !in_array($table, $preserve, true)));
}

function warehouse_reset_truncate_table(PDO $pdo, string $tableName): void {
    $quoted = warehouse_sql_ident($tableName);
    try {
        $pdo->exec('TRUNCATE TABLE ' . $quoted);
        return;
    } catch (Throwable $e) {
        $pdo->exec('DELETE FROM ' . $quoted);
        try {
            $pdo->exec('ALTER TABLE ' . $quoted . ' AUTO_INCREMENT = 1');
        } catch (Throwable $ignored) {
        }
    }
}

function warehouse_reset_storage_contents(string $dir, array &$stats): void {
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            warehouse_reset_storage_contents($path, $stats);
            if (@rmdir($path)) {
                $stats['deleted_dirs']++;
            }
            continue;
        }
        if (@unlink($path)) {
            $stats['deleted_files']++;
        }
    }
}

function warehouse_reset_all_data(array $config): array {
    if (!warehouse_module_admin($config)) {
        throw new RuntimeException('Ehhez a művelethez warehousemgr admin jogosultság szükséges.');
    }

    $pdo = warehouse_pdo($config);
    $tables = warehouse_resettable_tables($config);
    $summary = [
        'table_count' => 0,
        'tables' => [],
        'deleted_files' => 0,
        'deleted_dirs' => 0,
    ];

    $foreignChecksDisabled = false;
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $foreignChecksDisabled = true;
        foreach ($tables as $tableName) {
            warehouse_reset_truncate_table($pdo, $tableName);
            $summary['tables'][] = $tableName;
            $summary['table_count']++;
        }
    } finally {
        if ($foreignChecksDisabled) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable $ignored) {
            }
        }
    }

    $storageBase = warehouse_storage_path();
    if (!is_dir($storageBase) && !@mkdir($storageBase, 0775, true) && !is_dir($storageBase)) {
        throw new RuntimeException('Nem sikerült előkészíteni a storage könyvtárat.');
    }
    warehouse_reset_storage_contents($storageBase, $summary);

    return $summary;
}

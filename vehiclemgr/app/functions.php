<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function base_url(string $path = ''): string {
  $bp = rtrim((string)config()['base_path'], '/');
  return $bp . '/' . ltrim($path, '/');
}

function asset_url(string $path): string { return base_url($path); }

function e(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function start_session(): void {
  $name = (string)config()['session_name'];
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($name);
    session_start();
  }
}

function flash_set(string $k, string $v): void { start_session(); $_SESSION['_flash'][$k] = $v; }
function flash_get(string $k): ?string {
  start_session();
  if (!isset($_SESSION['_flash'][$k])) return null;
  $v = (string)$_SESSION['_flash'][$k];
  unset($_SESSION['_flash'][$k]);
  return $v;
}

function csrf_token(): string {
  start_session();
  if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['_csrf'];
}

function verify_csrf(): void {
  start_session();
  $ok = isset($_POST['_csrf'], $_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], (string)$_POST['_csrf']);
  if (!$ok) { http_response_code(400); exit('CSRF hiba'); }
}

function redirect(string $path): void { header('Location: ' . base_url($path)); exit; }

function audit(string $action, string $entityType = '', int $entityId = 0, array $details = []): void {
  try {
    $u = current_user();
    $uid = (int)($u['id'] ?? 0);
    db()->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details_json) VALUES (?,?,?,?,?)")
       ->execute([$uid, $action, $entityType ?: null, $entityId ?: null, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);
  } catch (Throwable $e) {}
}

// Jármű alapadatok a projectmgr-ből
function get_vehicle(int $id): ?array {
  try {
    $st = db_pm()->prepare("SELECT v.id, v.vehicle_identifier, v.license_plate, v.make, v.model, v.fuel_type, v.odometer_km, v.archived FROM vehicles v WHERE v.id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  } catch (Throwable $e) { return null; }
}

// Összes aktív jármű a projectmgr-ből
function get_all_vehicles(bool $includeArchived = false): array {
  try {
    $where = $includeArchived ? '' : 'WHERE v.archived=0';
    return db_pm()->query("SELECT v.id, v.vehicle_identifier, v.license_plate, v.make, v.model, v.fuel_type, v.odometer_km, v.archived FROM vehicles v $where ORDER BY v.license_plate")->fetchAll();
  } catch (Throwable $e) { return []; }
}

// Jármű megjelenítési neve
function vehicle_label(array $v): string {
  $parts = [];
  if (!empty($v['license_plate'])) $parts[] = $v['license_plate'];
  if (!empty($v['make'])) $parts[] = $v['make'];
  if (!empty($v['model'])) $parts[] = $v['model'];
  if (!empty($v['vehicle_identifier'])) $parts[] = '(' . $v['vehicle_identifier'] . ')';
  return implode(' ', $parts) ?: 'Ismeretlen jármű';
}

// Aktuális hozzárendelés egy járműhöz
function get_active_assignment(int $vehicleId): ?array {
  try {
    $st = db()->prepare("SELECT * FROM vehicle_assignments WHERE vehicle_id=? AND status='active' ORDER BY assigned_at DESC LIMIT 1");
    $st->execute([$vehicleId]);
    return $st->fetch() ?: null;
  } catch (Throwable $e) { return null; }
}

// Dolgozó aktív hozzárendelései
function get_employee_assignments(int $employeeId): array {
  try {
    $st = db()->prepare("SELECT * FROM vehicle_assignments WHERE employee_id=? AND status='active' ORDER BY assigned_at DESC");
    $st->execute([$employeeId]);
    return $st->fetchAll();
  } catch (Throwable $e) { return []; }
}

// Dolgozó neve a HR DB-ből
function employee_name(int $id): string {
  try {
    $st = db_hr()->prepare("SELECT full_name FROM employees WHERE id=? LIMIT 1");
    $st->execute([$id]);
    return (string)($st->fetchColumn() ?: "#{$id}");
  } catch (Throwable $e) { return "#{$id}"; }
}

// Összes aktív dolgozó listája
function get_all_employees(): array {
  try {
    return db_hr()->query("SELECT id, full_name FROM employees WHERE is_active=1 ORDER BY full_name")->fetchAll();
  } catch (Throwable $e) { return []; }
}

// Van-e már napi checklist ma erre a hozzárendelésre
function has_daily_checklist_today(int $assignmentId): bool {
  try {
    $st = db()->prepare("SELECT COUNT(*) FROM checklist_submissions WHERE assignment_id=? AND type='daily' AND DATE(submitted_at)=CURDATE()");
    $st->execute([$assignmentId]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

// Checklist sablon tételek egy járműhöz
function get_checklist_template(int $vehicleId): array {
  try {
    $st = db()->prepare("SELECT * FROM checklist_templates WHERE vehicle_id=? AND is_active=1 ORDER BY item_order, id");
    $st->execute([$vehicleId]);
    return $st->fetchAll();
  } catch (Throwable $e) { return []; }
}

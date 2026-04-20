<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

function warehouses_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query("SELECT 1 FROM warehouses LIMIT 1");
    $pdo->query("SELECT 1 FROM warehouse_admins LIMIT 1");
    $st = $pdo->query("SHOW COLUMNS FROM assets LIKE 'current_warehouse_id'");
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

function warehouse_is_admin(array $u, ?int $warehouseId = null): bool {
  if (($u['role'] ?? '') === 'admin') return true;
  $uid = (int)($u['id'] ?? 0);
  if ($uid <= 0) return false;
  $pdo = db();
  if (!warehouses_schema_ready($pdo)) return false;
  if ($warehouseId !== null && $warehouseId > 0) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM warehouse_admins WHERE user_id=? AND warehouse_id=?");
    $st->execute([$uid, $warehouseId]);
    return ((int)($st->fetchColumn() ?: 0)) > 0;
  }
  $st = $pdo->prepare("SELECT COUNT(*) FROM warehouse_admins WHERE user_id=?");
  $st->execute([$uid]);
  return ((int)($st->fetchColumn() ?: 0)) > 0;
}

function warehouse_accessible_ids(array $u): array {
  $pdo = db();
  if (!warehouses_schema_ready($pdo)) return [];
  if (($u['role'] ?? '') === 'admin') {
    return array_map('intval', $pdo->query("SELECT id FROM warehouses WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));
  }
  $uid = (int)($u['id'] ?? 0);
  if ($uid <= 0) return [];
  $st = $pdo->prepare("SELECT w.id
                       FROM warehouses w
                       JOIN warehouse_admins wa ON wa.warehouse_id=w.id
                       WHERE wa.user_id=? AND w.is_active=1
                       ORDER BY w.name");
  $st->execute([$uid]);
  return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function warehouses_for_user(array $u): array {
  $ids = warehouse_accessible_ids($u);
  if (!$ids) return [];
  $pdo = db();
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT * FROM warehouses WHERE id IN ($in) ORDER BY name");
  $st->execute($ids);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function auth_active_users_for_warehouse_admins(): array {
  $pdo = auth_pdo();
  return $pdo->query("SELECT id, username, email, full_name
                      FROM users
                      WHERE is_active=1
                      ORDER BY COALESCE(NULLIF(full_name,''), username, email)")
             ->fetchAll(PDO::FETCH_ASSOC);
}


function warehouses_all_active(): array {
  $pdo = db();
  if (!warehouses_schema_ready($pdo)) return [];
  return $pdo->query("SELECT id, name, location, note, is_active FROM warehouses WHERE is_active=1 ORDER BY name")
             ->fetchAll(PDO::FETCH_ASSOC);
}

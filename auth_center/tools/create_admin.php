<?php
declare(strict_types=1);

/*
Usage:
  php /var/www/html/auth_center/tools/create_admin.php admin "Admin User" "StrongPassword"
*/

$config = require __DIR__ . '/../app/config/auth.php';
require_once '/var/www/html/_common/auth/db.php';

if ($argc < 4) {
  fwrite(STDERR, "Usage: php create_admin.php <username> <full_name> <password>\n");
  exit(1);
}

$username = trim((string)$argv[1]);
$fullName = trim((string)$argv[2]);
$password = (string)$argv[3];

$pdo = auth_pdo($config);

$pdo->beginTransaction();
try {
  $hash = password_hash($password, PASSWORD_DEFAULT);

  $stmt = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
  $stmt->execute([$username]);
  $uid = $stmt->fetchColumn();

  if (!$uid) {
    $ins = $pdo->prepare("INSERT INTO users (username, full_name, password_hash, is_active) VALUES (?,?,?,1)");
    $ins->execute([$username, $fullName, $hash]);
    $uid = (int)$pdo->lastInsertId();
  } else {
    $uid = (int)$uid;
    $pdo->prepare("UPDATE users SET full_name=?, password_hash=?, is_active=1 WHERE id=?")
        ->execute([$fullName, $hash, $uid]);
  }

  $authModuleId = $pdo->query("SELECT id FROM modules WHERE module_key='auth' LIMIT 1")->fetchColumn();
  if (!$authModuleId) throw new RuntimeException("Module 'auth' not found. Did you import database/auth_db.sql?");

  $adminRoleId = $pdo->query("SELECT id FROM roles WHERE role_key='admin' LIMIT 1")->fetchColumn();
  if (!$adminRoleId) throw new RuntimeException("Role 'admin' not found.");

  $pdo->prepare("REPLACE INTO user_module_roles (user_id, module_id, role_id) VALUES (?,?,?)")
      ->execute([$uid, (int)$authModuleId, (int)$adminRoleId]);

  $pdo->commit();
  echo "OK: admin user ready (username={$username}).\n";
} catch (Throwable $e) {
  $pdo->rollBack();
  fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
  exit(2);
}

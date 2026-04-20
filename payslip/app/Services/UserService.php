<?php
namespace Services;

class UserService {
    public static function createUser(string $username, string $password, string $role='user', int $active=1): void {
        $username = trim($username);
        if ($username === '') throw new \InvalidArgumentException("Üres username");
        if (!in_array($role, ['admin','user'], true)) $role = 'user';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $pdo = \Db::pdo();
        $st = $pdo->prepare("INSERT INTO users(username,password_hash,role,active) VALUES(?,?,?,?)
                             ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role=VALUES(role), active=VALUES(active)");
        $st->execute([$username, $hash, $role, $active]);
    }
}

<?php
namespace App;

use App\Db;
use App\Logger;
use PDO;

class UserService {
  public static function all(): array {
    return Db::pdo()->query('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id DESC')->fetchAll();
  }

  public static function find(int $id): ?array {
    $st = Db::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->bindValue(1, $id, PDO::PARAM_INT);
    $st->execute();
    $u = $st->fetch(PDO::FETCH_ASSOC);
    Logger::write('UserService::find requested_id='.$id.' returned_id='.(isset($u['id'])?$u['id']:'NULL'));
    return $u ?: null;
  }

  public static function create(string $name, string $email, string $password, int $role_id, int $is_active = 1): int {
    $st = Db::pdo()->prepare('INSERT INTO users (name,email,password_hash,role_id,is_active) VALUES (?,?,?,?,?)');
    $st->execute([$name,$email,password_hash($password, PASSWORD_DEFAULT),$role_id,$is_active]);
    return (int)Db::pdo()->lastInsertId();
  }

  public static function update(int $id, string $name, string $email, ?string $password, int $role_id, int $is_active): void {
    if ($password) {
      $st = Db::pdo()->prepare('UPDATE users SET name=?, email=?, password_hash=?, role_id=?, is_active=? WHERE id=?');
      $st->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role_id,$is_active,$id]);
    } else {
      $st = Db::pdo()->prepare('UPDATE users SET name=?, email=?, role_id=?, is_active=? WHERE id=?');
      $st->execute([$name,$email,$role_id,$is_active,$id]);
    }
  }

  public static function delete(int $id): void {
    $st = Db::pdo()->prepare('DELETE FROM users WHERE id = ?');
    $st->bindValue(1, $id, PDO::PARAM_INT);
    $st->execute();
  }
}

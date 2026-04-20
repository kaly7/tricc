<?php
namespace App;

use App\Db;
use PDO;

class ProjectService {
  public static function all(): array {
    return Db::pdo()->query('SELECT p.*, u.name AS owner_name FROM projects p JOIN users u ON u.id=p.owner_user_id ORDER BY p.id DESC')->fetchAll();
  }

  public static function create(int $ownerUserId, string $code, string $name, string $description, string $uploadRoot): int {
    $rootRel = $code;
    $st = Db::pdo()->prepare('INSERT INTO projects (code,name,description,root_dir,owner_user_id) VALUES (?,?,?,?,?)');
    $st->execute([$code,$name,$description,$rootRel,$ownerUserId]);
    $pid = (int)Db::pdo()->lastInsertId();

    $absRoot = rtrim($uploadRoot,'/').'/'.$rootRel;
    if (!is_dir($absRoot)) mkdir($absRoot, 0775, true);

    $template = [
      '01_Dokumentacio',
      '02_Szerzodesek',
      '03_Tervek_DWG',
      '04_Rajzok_PDF',
      '05_Tablazatok_XLS',
      '06_Kepek',
      '99_Egyeb',
    ];
    foreach ($template as $dir) {
      $p = $absRoot.'/'.$dir;
      if (!is_dir($p)) mkdir($p, 0775, true);
    }
    return $pid;
  }
}

<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
@set_time_limit(0);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$pdo = Db::pdo();
$project_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$since_id = (int)($_GET['since_id'] ?? 0);
$uid = (int)Auth::user()['id'];

function emit($event, $data) {
  echo "event: {$event}\n";
  echo "data: ".json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
  @ob_flush(); @flush();
}

$alive = 0;
while ($alive < 300) { // ~5 perc
  $params = [];
  $where = ' WHERE ';
  if ($project_id) { $where .= ' pm.project_id = ? '; $params[] = $project_id; }
  else { $where .= ' pm.project_id IS NULL '; }
  if ($since_id > 0) { $where .= ' AND pm.id > ? '; $params[] = $since_id; }
  $sql = 'SELECT pm.id, pm.user_id, u.name, u.email, pm.body, pm.created_at
          FROM project_messages pm JOIN users u ON u.id=pm.user_id ' . $where . ' ORDER BY pm.id ASC LIMIT 100';
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if ($rows) {
    foreach ($rows as $r) {
      $since_id = max($since_id, (int)$r['id']);
    }
    emit('messages', ['rows'=>$rows, 'since_id'=>$since_id]);
  } else {
    // heartbeat
    emit('ping', ['t'=>time(), 'since_id'=>$since_id]);
  }
  sleep(2);
  $alive += 2;
}

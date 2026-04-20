<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';

use App\Auth; use App\Middleware; use App\Db;

header('Content-Type: application/json; charset=utf-8');
Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$project_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$since_id = filter_input(INPUT_GET, 'since_id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$limit) $limit = 50;

// base query
$params = [];
$where = ' WHERE ';
if ($project_id) {
  $where .= ' pm.project_id = ? ';
  $params[] = $project_id;
} else {
  $where .= ' pm.project_id IS NULL ';
}
if ($since_id) {
  $where .= ' AND pm.id > ? ';
  $params[] = $since_id;
}

$sql = 'SELECT pm.*, u.name AS user_name, u.email AS user_email
        FROM project_messages pm
        JOIN users u ON u.id = pm.user_id '.
        $where.'
        ORDER BY pm.id DESC
        LIMIT '.(int)$limit;

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// render simple HTML (newest first)
ob_start();
if (!$since_id && !$rows) {
  echo '<div class="p-3 text-muted">Nincs üzenet.</div>';
} else {
  foreach ($rows as $r) {
    $user = htmlspecialchars($r['user_name'] ?: $r['user_email']);
    $time = htmlspecialchars($r['created_at']);
    $body = nl2br(htmlspecialchars($r['body']));
    $id   = (int)$r['id'];
    echo '<div class="p-3 border-bottom">';
    echo '<div class="small text-muted">'. $time .' – <strong>'. $user .'</strong></div>';
    echo '<div class="mt-1" style="white-space: pre-wrap;">'. $body .'</div>';
    echo '</div>';
  }
}
$html = ob_get_clean();
$max_id = 0;
foreach ($rows as $r) { if ((int)$r['id'] > $max_id) $max_id = (int)$r['id']; }

echo json_encode(['ok'=>true, 'html'=>$html, 'max_id'=>$max_id], JSON_UNESCAPED_UNICODE);

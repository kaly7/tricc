<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';

use App\Auth; use App\Middleware; use App\Db;

header('Content-Type: application/json; charset=utf-8');
Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$uid = (int)Auth::user()['id'];
$project_id = isset($_POST['id']) ? (int)$_POST['id'] : null;
$last_id = (int)($_POST['last_id'] ?? 0);

$st = $pdo->prepare('INSERT INTO chat_last_seen (user_id, project_id, last_seen_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_seen_id=VALUES(last_seen_id)');
$st->execute([$uid, $project_id ?: null, $last_id]);
echo json_encode(['ok'=>true]);

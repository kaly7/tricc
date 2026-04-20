<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/app/Activity.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers; use App\Activity;

Auth::start(); Middleware::requireAuth();
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Method Not Allowed'); }
if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }

$project_id = (int)($_POST['project_id'] ?? 0);
$parent_id  = (int)($_POST['parent_id'] ?? 0);
$body = trim($_POST['body'] ?? '');
if ($body==='') { Helpers::flash('err','Üres üzenet'); header('Location: /pm_chat.php'.($project_id?('?id='.$project_id):'')); exit; }

$pdo = Db::pdo();

// if project_id given, verify it exists
if ($project_id>0) {
  $st = $pdo->prepare('SELECT id FROM projects WHERE id=?');
  $st->execute([$project_id]);
  if (!$st->fetchColumn()) { http_response_code(400); exit('Hibás projekt'); }
}

// if parent_id given, verify it belongs to the same stream
if ($parent_id>0) {
  $st = $pdo->prepare('SELECT project_id FROM project_messages WHERE id=?');
  $st->execute([$parent_id]);
  $pid = $st->fetchColumn();
  if ($pid != $project_id) { http_response_code(400); exit('Hibás szülő üzenet'); }
  $parent_id = (int)$parent_id;
} else {
  $parent_id = null;
}

$st = $pdo->prepare('INSERT INTO project_messages (project_id,user_id,parent_id,body) VALUES (?,?,?,?)');
$st->execute([$project_id ?: null, (int)Auth::user()['id'], $parent_id, $body]);

if ($project_id) {
  Activity::log($project_id, (int)Auth::user()['id'], 'chat.post', ['len'=>mb_strlen($body)]);
}

header('Location: /pm_chat.php'.($project_id?('?id='.$project_id):''));

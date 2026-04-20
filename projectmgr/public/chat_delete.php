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

$id = (int)($_POST['id'] ?? 0);
$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($id<=0) { http_response_code(400); exit('Hibás üzenet'); }

$pdo = Db::pdo();
$msg = $pdo->prepare('SELECT id, project_id, user_id FROM project_messages WHERE id=?');
$msg->execute([$id]);
$row = $msg->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Nem található'); }

$user = Auth::user();
$isAdmin = (isset($user['role']) && ($user['role']==='admin' || $user['role']==='Admin'));
if (!$isAdmin && (int)$row['user_id'] !== (int)$user['id']) {
  http_response_code(403); exit('Nincs jogosultság törölni');
}

$del = $pdo->prepare('DELETE FROM project_messages WHERE id=?');
$del->execute([$id]);

if ($row['project_id']) {
  Activity::log((int)$row['project_id'], (int)$user['id'], 'chat.delete', ['id'=>$id]);
}

Helpers::flash('ok','Üzenet törölve');
header('Location: /pm_chat.php'+($project_id?('?id='.$project_id):''));

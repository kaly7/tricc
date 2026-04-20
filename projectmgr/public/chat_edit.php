<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers;

Auth::start(); Middleware::requireAuth();
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Method Not Allowed'); }
if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }

$id = (int)($_POST['id'] ?? 0);
$body = trim($_POST['body'] ?? '');
if ($id<=0 || $body==='') { http_response_code(400); exit('Hibás adatok'); }

$pdo = Db::pdo();
$st = $pdo->prepare('SELECT user_id, project_id, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_min FROM project_messages WHERE id=?');
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Nem található'); }

$user = Auth::user();
$isAdmin = (isset($user['role']) && ($user['role']==='admin' || $user['role']==='Admin'));
if ((int)$row['user_id'] !== (int)$user['id'] and !$isAdmin) { http_response_code(403); exit('Nincs jogosultság'); }
if (!$isAdmin && (int)$row['age_min'] > 10) { http_response_code(403); exit('Szerkesztési időablak lejárt'); }

$upd = $pdo->prepare('UPDATE project_messages SET body=? WHERE id=?');
$upd->execute([$body, $id]);

\App\Helpers::flash('ok','Üzenet frissítve');
header('Location: /pm_chat.php'+($row['project_id']?('?id='.$row['project_id']):''));

<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Method Not Allowed'); }
if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }

$msg_id = (int)($_POST['message_id'] ?? 0);
if ($msg_id<=0) { http_response_code(400); exit('Hibás üzenet ID'); }

$pdo = Db::pdo();
$chk = $pdo->prepare('SELECT project_id FROM project_messages WHERE id=?');
$chk->execute([$msg_id]);
$proj = $chk->fetchColumn();
if ($proj===false) { http_response_code(404); exit('Üzenet nem található'); }

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { http_response_code(400); exit('Nincs fájl'); }

$orig = $_FILES['file']['name'];
$base = basename($orig);
$tmp  = $_FILES['file']['tmp_name'];
$size = (int)$_FILES['file']['size'];
$mime = $_FILES['file']['type'] ?? null;

$storeDir = dirname(__DIR__).'/storage/chat_uploads';
if (!is_dir($storeDir)) @mkdir($storeDir, 0775, true);
$stored = uniqid('cf_', true).'_'.preg_replace('~[^A-Za-z0-9._-]+~','_', $base);
$dest = $storeDir.'/'.$stored;
if (!move_uploaded_file($tmp, $dest)) { http_response_code(500); exit('Mentési hiba'); }

$ins = $pdo->prepare('INSERT INTO project_message_files (message_id,orig_name,stored_name,mime,size) VALUES (?,?,?,?,?)');
$ins->execute([$msg_id,$base,$stored,$mime,$size]);

header('Location: /pm_chat.php'+($proj?('?id='.$proj):''));

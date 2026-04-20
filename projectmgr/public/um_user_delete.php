<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';

use App\Auth; use App\Middleware; use App\Db; use App\Csrf; use App\Helpers;

Auth::start(); Middleware::requireAuth(); Auth::requireRole(1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
if (!Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(400); exit('Hibás azonosító'); }

$pdo = Db::pdo();
$st = $pdo->prepare('DELETE FROM users WHERE id = ?');
$st->bindValue(1, $id, PDO::PARAM_INT);
$st->execute();

Helpers::flash('ok','Felhasználó törölve');
header('Location: /um_users.php'); exit;

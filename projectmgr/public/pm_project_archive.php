<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers;

Auth::start(); Middleware::requireAuth(); Auth::requireRole(1);
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Method Not Allowed'); }
if (!\App\Csrf::check($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('CSRF hiba'); }

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$archived = (int)($_POST['archived'] ?? 0);
if (!$id) { http_response_code(400); exit('Hibás ID'); }

$pdo = Db::pdo();
$st = $pdo->prepare('UPDATE projects SET archived=? WHERE id=?');
$st->execute([$archived,$id]);
Helpers::flash('ok', $archived ? 'Projekt archiválva' : 'Projekt visszaaktiválva');
header('Location: /pm_projects.php'); exit;

<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/app/UserService.php';

use App\Auth;
use App\Middleware;
use App\Csrf;
use App\Helpers;
use App\UserService;

Auth::start();
Middleware::requireAuth();
Auth::requireRole(1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        UserService::delete($id);
        Helpers::flash('ok', 'Felhasználó törölve');
    }
    header('Location: /users.php');
    exit;
}
http_response_code(405);
echo "Method Not Allowed";
